<?php

namespace App\Integrations\ContaAzul\Services;

use App\Integrations\ContaAzul\Clients\ContaAzulClient;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Import\ContaAzulImportAdapter;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulImportBatch;
use Illuminate\Support\Facades\DB;

class ImportacaoContaAzulService
{
    /**
     * @var array<string, ContaAzulImportAdapter>
     */
    private array $adaptersByType = [];

    public function __construct(
        private readonly array $config,
        private readonly ContaAzulConnectionService $connections,
        private readonly ContaAzulClient $client,
        iterable $adapters
    ) {
        foreach ($adapters as $adapter) {
            if ($adapter instanceof ContaAzulImportAdapter) {
                $this->adaptersByType[$adapter->tipoEntidade()] = $adapter;
            }
        }
    }

    /**
     * @return array{batch_id:int, lidos:int}
     */
    public function importarParaStaging(ContaAzulConexao $conexao, string $tipoEntidade, ?int $lojaId = null): array
    {
        if (!filter_var($this->config['flags']['importacao_ativa'] ?? true, FILTER_VALIDATE_BOOL)) {
            throw new ContaAzulException('Importação desativada por configuração.');
        }

        $adapter = $this->adapterFor($tipoEntidade);
        $pageSize = (int) ($this->config['pagination']['page_size'] ?? 50);

        $batch = ContaAzulImportBatch::create([
            'loja_id' => $lojaId,
            'conexao_id' => $conexao->id,
            'tipo_entidade' => $tipoEntidade,
            'status' => 'executando',
            'parametros_json' => ['adapter' => get_class($adapter)],
            'iniciado_em' => now(),
        ]);

        $token = $this->connections->getValidAccessToken($conexao);
        $lidos = 0;
        $pagina = 1;

        try {
            while (true) {
                $this->throttle($conexao->id);

                $request = $adapter->buildRequest($this->config, $pagina, $pageSize);
                $method = strtoupper((string) ($request['method'] ?? 'GET'));
                $path = ltrim((string) $request['path'], '/');

                $res = match ($method) {
                    'POST' => $this->client->post($path, $token, (array) $request['body']),
                    'PUT' => $this->client->put($path, $token, (array) $request['body']),
                    default => $this->client->get($path, $token, (array) $request['query']),
                };

                if ($res['status'] < 200 || $res['status'] >= 300) {
                    throw new ContaAzulException('Falha na importação HTTP ' . $res['status']);
                }

                $items = $this->extractItems($res['json']);
                if ($items === []) {
                    break;
                }

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $extId = $this->extractExternalId($item);
                    if ($extId === '') {
                        continue;
                    }

                    $hash = hash('sha256', json_encode($item, JSON_UNESCAPED_UNICODE));

                    DB::table($adapter->stagingTable())->upsert(
                        [[
                            'loja_id' => $lojaId,
                            'identificador_externo' => $extId,
                            'payload_json' => json_encode($item, JSON_UNESCAPED_UNICODE),
                            'hash_payload' => $hash,
                            'batch_id' => $batch->id,
                            'status_conciliacao' => 'novo',
                            'observacao_conciliacao' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]],
                        ['loja_id', 'identificador_externo'],
                        ['payload_json', 'hash_payload', 'batch_id', 'status_conciliacao', 'observacao_conciliacao', 'updated_at']
                    );

                    $lidos++;
                }

                $totalPaginas = $this->resolveTotalPages($res['json'], $pageSize);
                if ($totalPaginas !== null && $pagina >= $totalPaginas) {
                    break;
                }

                if ($totalPaginas === null && count($items) < $pageSize) {
                    break;
                }

                $pagina++;
            }

            $batch->update([
                'status' => 'concluido',
                'total_lidos' => $lidos,
                'finalizado_em' => now(),
                'resumo_json' => ['lidos' => $lidos],
            ]);

            return ['batch_id' => (int) $batch->id, 'lidos' => $lidos];
        } catch (\Throwable $e) {
            $batch->update([
                'status' => 'falhou',
                'finalizado_em' => now(),
                'resumo_json' => ['erro' => $e->getMessage()],
            ]);

            throw $e;
        }
    }

    private function adapterFor(string $tipoEntidade): ContaAzulImportAdapter
    {
        $adapter = $this->adaptersByType[$tipoEntidade] ?? null;
        if ($adapter) {
            return $adapter;
        }

        throw new ContaAzulException('Tipo de entidade desconhecido ou não suportado para importação: ' . $tipoEntidade);
    }

    private function throttle(int $conexaoId): void
    {
        $sec = (float) ($this->config['throttle_seconds_per_connection'] ?? 0);
        if ($sec <= 0) {
            return;
        }

        usleep((int) ($sec * 1_000_000));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractItems(mixed $json): array
    {
        if (!is_array($json)) {
            return [];
        }

        foreach ([
            'items',
            'itens',
            'data',
            'resultado',
            'lista',
            'pessoas',
            'produtos',
            'vendas',
            'registros',
            'titulos',
            'parcelas',
            'eventos',
            'baixas',
            'notas',
            'notasFiscais',
            'lancamentos',
        ] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                return array_values(array_filter($json[$key], fn ($value) => is_array($value)));
            }
        }

        if (array_is_list($json)) {
            return array_values(array_filter($json, fn ($value) => is_array($value)));
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function extractExternalId(array $item): string
    {
        foreach (['id', 'uuid', 'id_legado', 'codigo', 'legacyId', 'idEvento', 'idTitulo', 'idParcela', 'idNota'] as $key) {
            if (!empty($item[$key])) {
                return (string) $item[$key];
            }
        }

        return '';
    }

    private function resolveTotalPages(mixed $json, int $pageSize): ?int
    {
        if (!is_array($json)) {
            return null;
        }

        $paginacao = $json['paginacao'] ?? null;
        if (is_array($paginacao) && !empty($paginacao['total_paginas'])) {
            return max(1, (int) $paginacao['total_paginas']);
        }

        foreach (['itens_totais', 'total_itens', 'total_items'] as $key) {
            if (!empty($json[$key]) && $pageSize > 0) {
                return max(1, (int) ceil(((int) $json[$key]) / $pageSize));
            }
        }

        return null;
    }
}
