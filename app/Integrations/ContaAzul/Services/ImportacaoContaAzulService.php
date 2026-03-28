<?php

namespace App\Integrations\ContaAzul\Services;

use App\Integrations\ContaAzul\Clients\ContaAzulClient;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Models\ContaAzulImportBatch;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ImportacaoContaAzulService
{
    public function __construct(
        private readonly array $config,
        private readonly ContaAzulConnectionService $connections,
        private readonly ContaAzulClient $client
    ) {
    }

    /**
     * @return array{batch_id:int, lidos:int}
     */
    public function importarParaStaging(ContaAzulConexao $conexao, string $tipoEntidade, ?int $lojaId = null): array
    {
        if (!filter_var($this->config['flags']['importacao_ativa'] ?? true, FILTER_VALIDATE_BOOL)) {
            throw new ContaAzulException('Importação desativada por configuração.');
        }

        $path = $this->pathForEntity($tipoEntidade);
        $stagingTable = $this->stagingTableForEntity($tipoEntidade);
        $settings = $this->importSettingsFor($tipoEntidade);

        $pageParam = (string) ($this->config['pagination']['page_param'] ?? 'pagina');
        $sizeParam = (string) ($this->config['pagination']['page_size_param'] ?? 'tamanhoPagina');
        $pageSize = (int) ($this->config['pagination']['page_size'] ?? 50);

        $batch = ContaAzulImportBatch::create([
            'loja_id' => $lojaId,
            'conexao_id' => $conexao->id,
            'tipo_entidade' => $tipoEntidade,
            'status' => 'executando',
            'parametros_json' => ['path' => $path, 'import' => $settings],
            'iniciado_em' => now(),
        ]);

        $token = $this->connections->getValidAccessToken($conexao);
        $lidos = 0;
        $pagina = 1;

        $dateRange = $this->buildDateRangeQuery($settings);

        try {
            while (true) {
                $this->throttle($conexao->id);

                $baseQuery = array_merge(
                    (array) ($settings['query'] ?? []),
                    $dateRange,
                    [
                        $pageParam => $pagina,
                        $sizeParam => $pageSize,
                    ]
                );

                $baseBody = array_merge((array) ($settings['body'] ?? []), $dateRange, [
                    $pageParam => $pagina,
                    $sizeParam => $pageSize,
                ]);

                $method = strtoupper((string) ($settings['method'] ?? 'GET'));
                $res = match ($method) {
                    'POST' => $this->client->post(ltrim($path, '/'), $token, $baseBody),
                    'PUT' => $this->client->put(ltrim($path, '/'), $token, $baseBody),
                    default => $this->client->get(ltrim($path, '/'), $token, $baseQuery),
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

                    DB::table($stagingTable)->upsert(
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

                if (count($items) < $pageSize) {
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

    private function throttle(int $conexaoId): void
    {
        $sec = (float) ($this->config['throttle_seconds_per_connection'] ?? 0);
        if ($sec <= 0) {
            return;
        }
        usleep((int) ($sec * 1_000_000));
    }

    /**
     * @return array<string, mixed>
     */
    private function importSettingsFor(string $tipo): array
    {
        $import = (array) ($this->config['import'] ?? []);
        $key = match ($tipo) {
            ContaAzulEntityType::PESSOA => 'pessoa',
            ContaAzulEntityType::PRODUTO => 'produto',
            ContaAzulEntityType::VENDA => 'venda',
            ContaAzulEntityType::TITULO => 'titulo',
            ContaAzulEntityType::BAIXA => 'baixa',
            ContaAzulEntityType::NOTA => 'nota',
            default => throw new ContaAzulException('Tipo de entidade desconhecido: ' . $tipo),
        };

        $defaults = ['method' => 'GET', 'query' => [], 'body' => []];

        return array_merge($defaults, (array) ($import[$key] ?? []));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    private function buildDateRangeQuery(array $settings): array
    {
        $days = isset($settings['date_start_days_ago']) ? (int) $settings['date_start_days_ago'] : 0;
        $keys = $settings['date_query_keys'] ?? null;
        if ($days <= 0 || !is_array($keys) || count($keys) < 2) {
            return [];
        }

        $start = CarbonImmutable::now()->subDays($days)->startOfDay()->format('Y-m-d');
        $end = CarbonImmutable::now()->endOfDay()->format('Y-m-d');

        return [
            (string) $keys[0] => $start,
            (string) $keys[1] => $end,
        ];
    }

    private function pathForEntity(string $tipo): string
    {
        $paths = (array) ($this->config['paths'] ?? []);

        return match ($tipo) {
            ContaAzulEntityType::PESSOA => (string) ($paths['pessoas'] ?? '/v1/pessoas'),
            ContaAzulEntityType::PRODUTO => (string) ($paths['produtos'] ?? '/v1/produtos'),
            ContaAzulEntityType::VENDA => (string) ($paths['vendas_busca'] ?? '/v1/venda/busca'),
            ContaAzulEntityType::TITULO => (string) ($paths['financeiro'] ?? '/v1/financeiro/eventos-financeiros/consulta'),
            ContaAzulEntityType::BAIXA => (string) ($paths['baixas'] ?? '/v1/financeiro/eventos-financeiros/consulta'),
            ContaAzulEntityType::NOTA => (string) ($paths['notas'] ?? '/v1/notas'),
            default => throw new ContaAzulException('Tipo de entidade desconhecido: ' . $tipo),
        };
    }

    private function stagingTableForEntity(string $tipo): string
    {
        return match ($tipo) {
            ContaAzulEntityType::PESSOA => 'stg_conta_azul_pessoas',
            ContaAzulEntityType::PRODUTO => 'stg_conta_azul_produtos',
            ContaAzulEntityType::VENDA => 'stg_conta_azul_vendas',
            ContaAzulEntityType::TITULO => 'stg_conta_azul_financeiro',
            ContaAzulEntityType::BAIXA => 'stg_conta_azul_baixas',
            ContaAzulEntityType::NOTA => 'stg_conta_azul_notas',
            default => throw new ContaAzulException('Tipo de entidade desconhecido: ' . $tipo),
        };
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
            'items', 'data', 'resultado', 'lista', 'pessoas', 'produtos', 'vendas', 'registros',
            'titulos', 'parcelas', 'eventos', 'baixas', 'notas', 'notasFiscais', 'lancamentos',
        ] as $k) {
            if (isset($json[$k]) && is_array($json[$k])) {
                return array_values(array_filter($json[$k], fn ($v) => is_array($v)));
            }
        }

        if (array_is_list($json)) {
            return array_values(array_filter($json, fn ($v) => is_array($v)));
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function extractExternalId(array $item): string
    {
        foreach (['id', 'uuid', 'codigo', 'legacyId', 'idEvento', 'idTitulo', 'idParcela', 'idNota'] as $k) {
            if (!empty($item[$k])) {
                return (string) $item[$k];
            }
        }

        return '';
    }
}
