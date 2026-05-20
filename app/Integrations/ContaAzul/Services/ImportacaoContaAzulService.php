<?php

namespace App\Integrations\ContaAzul\Services;

use App\Integrations\ContaAzul\Clients\ContaAzulClient;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Import\ContaAzulImportAdapter;
use App\Integrations\ContaAzul\Models\ContaAzulConexao;
use App\Integrations\ContaAzul\Models\ContaAzulImportBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

        if ($tipoEntidade === ContaAzulEntityType::PARCELA) {
            return $this->importarParcelasDetalhadas($conexao, $lojaId);
        }
        if ($tipoEntidade === ContaAzulEntityType::BAIXA) {
            return $this->importarBaixas($conexao, $lojaId);
        }
        if ($tipoEntidade === ContaAzulEntityType::SALDO_CONTA_FINANCEIRA) {
            return $this->importarSaldosContasFinanceiras($conexao, $lojaId);
        }
        if ($tipoEntidade === ContaAzulEntityType::FORMA_PAGAMENTO) {
            return $this->importarFormasPagamento($conexao, $lojaId);
        }
        if ($tipoEntidade === ContaAzulEntityType::NOTA) {
            return $this->importarNotasPorJanelas($conexao, $lojaId);
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
                            'candidato_id_local' => null,
                            'candidato_score' => null,
                            'candidato_motivo' => null,
                            'candidato_json' => null,
                            'conciliacao_origem' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]],
                        ['loja_id', 'identificador_externo'],
                        [
                            'payload_json',
                            'hash_payload',
                            'batch_id',
                            'status_conciliacao',
                            'observacao_conciliacao',
                            'candidato_id_local',
                            'candidato_score',
                            'candidato_motivo',
                            'candidato_json',
                            'conciliacao_origem',
                            'updated_at',
                        ]
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

    /**
     * @return array{batch_id:int, lidos:int}
     */
    private function importarParcelasDetalhadas(ContaAzulConexao $conexao, ?int $lojaId): array
    {
        $batch = $this->startBatch($conexao, ContaAzulEntityType::PARCELA, ['origem' => 'eventos_financeiros', 'loja_id' => $lojaId]);
        $token = $this->connections->getValidAccessToken($conexao);
        $pathTemplate = (string) (($this->config['paths']['parcelas_by_evento'] ?? null) ?: '/v1/financeiro/eventos-financeiros/{id_evento}/parcelas');
        $lidos = 0;

        try {
            foreach ($this->financialEventSources($lojaId) as $source) {
                $this->throttle($conexao->id);
                $path = $this->replacePath($pathTemplate, ['id_evento' => $source['id_evento']]);
                $res = $this->client->get($path, $token, []);
                if ($res['status'] === 401) {
                    $conexao->load('token');
                    $token = $this->connections->getValidAccessToken($conexao, true);
                    $res = $this->client->get($path, $token, []);
                }

                if ($res['status'] === 404) {
                    continue;
                }
                if ($res['status'] < 200 || $res['status'] >= 300) {
                    throw new ContaAzulException('Falha ao importar parcelas HTTP ' . $res['status']);
                }

                foreach ($this->extractItems($res['json']) as $item) {
                    $item['id_evento'] = $source['id_evento'];
                    $item['evento_tipo_sierra'] = $source['tipo_evento'];
                    $item['evento_identificador_externo'] = $source['identificador_externo'];
                    $extId = $this->extractExternalId($item);
                    if ($extId === '') {
                        continue;
                    }

                    $this->upsertStagingRow('stg_conta_azul_parcelas', $lojaId, $extId, $item, $batch);
                    $lidos++;
                }
            }

            return $this->finishBatch($batch, $lidos);
        } catch (\Throwable $e) {
            $this->failBatch($batch, $e);
            throw $e;
        }
    }

    /**
     * @return array{batch_id:int, lidos:int}
     */
    private function importarNotasPorJanelas(ContaAzulConexao $conexao, ?int $lojaId): array
    {
        $batch = $this->startBatch($conexao, ContaAzulEntityType::NOTA, ['origem' => 'janelas_15_dias', 'loja_id' => $lojaId]);
        $token = $this->connections->getValidAccessToken($conexao);
        $path = (string) (($this->config['paths']['notas_list'] ?? null) ?: '/v1/notas-fiscais');
        $pageSize = $this->validContaAzulPageSize((int) ($this->config['pagination']['page_size'] ?? 50));
        $days = max(0, (int) ($this->config['import']['nota']['date_start_days_ago'] ?? 730));
        $lidos = 0;

        $start = CarbonImmutable::now()->subDays($days)->startOfDay();
        $limit = CarbonImmutable::now()->endOfDay();

        try {
            for ($windowStart = $start; $windowStart->lessThanOrEqualTo($limit); $windowStart = $windowEnd->addDay()->startOfDay()) {
                $windowEnd = $windowStart->addDays(14)->endOfDay();
                if ($windowEnd->greaterThan($limit)) {
                    $windowEnd = $limit;
                }

                $pagina = 1;
                while (true) {
                    $this->throttle($conexao->id);
                    $query = [
                        'data_inicial' => $windowStart->format('Y-m-d'),
                        'data_final' => $windowEnd->format('Y-m-d'),
                        (string) ($this->config['pagination']['page_param'] ?? 'pagina') => $pagina,
                        (string) ($this->config['pagination']['page_size_param'] ?? 'tamanho_pagina') => $pageSize,
                    ];

                    $res = $this->client->get($path, $token, $query);
                    if ($res['status'] === 401) {
                        $conexao->load('token');
                        $token = $this->connections->getValidAccessToken($conexao, true);
                        $res = $this->client->get($path, $token, $query);
                    }

                    if ($res['status'] < 200 || $res['status'] >= 300) {
                        throw new ContaAzulException('Falha ao importar notas HTTP ' . $res['status']);
                    }

                    $items = $this->extractItems($res['json']);
                    if ($items === []) {
                        break;
                    }

                    foreach ($items as $item) {
                        $extId = $this->extractExternalId($item);
                        if ($extId === '') {
                            continue;
                        }

                        $this->upsertStagingRow('stg_conta_azul_notas', $lojaId, $extId, $item, $batch);
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
            }

            return $this->finishBatch($batch, $lidos);
        } catch (\Throwable $e) {
            $this->failBatch($batch, $e);
            throw $e;
        }
    }

    /**
     * @return array{batch_id:int, lidos:int}
     */
    private function importarBaixas(ContaAzulConexao $conexao, ?int $lojaId): array
    {
        $this->importarParcelasDetalhadas($conexao, $lojaId);

        $batch = $this->startBatch($conexao, ContaAzulEntityType::BAIXA, ['origem' => 'parcelas', 'loja_id' => $lojaId]);
        $token = $this->connections->getValidAccessToken($conexao);
        $pathTemplate = (string) (($this->config['paths']['baixas_by_parcela'] ?? null) ?: '/v1/financeiro/eventos-financeiros/parcelas/{parcela_id}/baixa');
        $lidos = 0;

        try {
            foreach ($this->stagingPayloadRows('stg_conta_azul_parcelas', $lojaId) as $row) {
                $parcela = $row['payload'];
                $parcelaId = (string) $row['identificador_externo'];
                if ($parcelaId === '') {
                    continue;
                }

                $this->throttle($conexao->id);
                $res = $this->client->get(
                    $this->replacePath($pathTemplate, ['parcela_id' => $parcelaId]),
                    $token,
                    []
                );

                if ($res['status'] === 404) {
                    continue;
                }
                if ($res['status'] < 200 || $res['status'] >= 300) {
                    throw new ContaAzulException('Falha ao importar baixas HTTP ' . $res['status']);
                }

                foreach ($this->responseItemsOrObject($res['json']) as $item) {
                    $item['idParcela'] = $parcelaId;
                    $item['id_evento'] = $parcela['id_evento'] ?? null;
                    $item['evento_tipo_sierra'] = $parcela['evento_tipo_sierra'] ?? null;
                    $item['evento_identificador_externo'] = $parcela['evento_identificador_externo'] ?? null;
                    $extId = $this->extractExternalId($item);
                    if ($extId === '') {
                        $extId = $parcelaId . ':' . ($this->firstStringNested($item, ['data_pagamento', 'dataPagamento', 'data', 'dataBaixa']) ?: $lidos);
                    }

                    $this->upsertStagingRow('stg_conta_azul_baixas', $lojaId, $extId, $item, $batch);
                    $lidos++;
                }
            }

            return $this->finishBatch($batch, $lidos);
        } catch (\Throwable $e) {
            $this->failBatch($batch, $e);
            throw $e;
        }
    }

    /**
     * @return array{batch_id:int, lidos:int}
     */
    private function importarSaldosContasFinanceiras(ContaAzulConexao $conexao, ?int $lojaId): array
    {
        $batch = $this->startBatch($conexao, ContaAzulEntityType::SALDO_CONTA_FINANCEIRA, ['origem' => 'contas_financeiras', 'loja_id' => $lojaId]);
        $token = $this->connections->getValidAccessToken($conexao);
        $pathTemplate = (string) (($this->config['paths']['saldo_conta_financeira'] ?? null) ?: '/v1/conta-financeira/{id_conta_financeira}/saldo-atual');
        $lidos = 0;

        try {
            foreach ($this->financialAccountSources($lojaId) as $contaId) {
                $this->throttle($conexao->id);
                $res = $this->client->get(
                    $this->replacePath($pathTemplate, ['id_conta_financeira' => $contaId]),
                    $token,
                    []
                );

                if ($res['status'] === 404) {
                    continue;
                }
                if ($res['status'] < 200 || $res['status'] >= 300) {
                    throw new ContaAzulException('Falha ao importar saldo HTTP ' . $res['status']);
                }

                $payload = is_array($res['json']) ? $res['json'] : [];
                $payload['id_conta_financeira'] = $contaId;
                $payload['consultado_em'] = now()->toISOString();

                $this->upsertStagingRow('stg_conta_azul_saldos_contas_financeiras', $lojaId, $contaId, $payload, $batch);
                $lidos++;
            }

            return $this->finishBatch($batch, $lidos);
        } catch (\Throwable $e) {
            $this->failBatch($batch, $e);
            throw $e;
        }
    }

    /**
     * @return array{batch_id:int, lidos:int}
     */
    private function importarFormasPagamento(ContaAzulConexao $conexao, ?int $lojaId): array
    {
        if ($this->countRows('stg_conta_azul_parcelas', $lojaId) === 0) {
            $this->importarParcelasDetalhadas($conexao, $lojaId);
        }

        $batch = $this->startBatch($conexao, ContaAzulEntityType::FORMA_PAGAMENTO, ['origem' => 'metodo_pagamento', 'loja_id' => $lojaId]);
        $lidos = 0;

        try {
            foreach ($this->paymentMethodCodes($lojaId) as $code) {
                $payload = [
                    'codigo' => $code,
                    'nome' => $this->paymentMethodName($code),
                    'origem' => 'metodo_pagamento',
                ];

                $this->upsertStagingRow('stg_conta_azul_formas_pagamento', $lojaId, $code, $payload, $batch);
                $lidos++;
            }

            return $this->finishBatch($batch, $lidos);
        } catch (\Throwable $e) {
            $this->failBatch($batch, $e);
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
     * @param  array<string, mixed>  $params
     */
    private function startBatch(ContaAzulConexao $conexao, string $tipoEntidade, array $params): ContaAzulImportBatch
    {
        return ContaAzulImportBatch::create([
            'loja_id' => $params['loja_id'] ?? null,
            'conexao_id' => $conexao->id,
            'tipo_entidade' => $tipoEntidade,
            'status' => 'executando',
            'parametros_json' => $params,
            'iniciado_em' => now(),
        ]);
    }

    /**
     * @return array{batch_id:int, lidos:int}
     */
    private function finishBatch(ContaAzulImportBatch $batch, int $lidos): array
    {
        $batch->update([
            'status' => 'concluido',
            'total_lidos' => $lidos,
            'finalizado_em' => now(),
            'resumo_json' => ['lidos' => $lidos],
        ]);

        return ['batch_id' => (int) $batch->id, 'lidos' => $lidos];
    }

    private function failBatch(ContaAzulImportBatch $batch, \Throwable $e): void
    {
        $batch->update([
            'status' => 'falhou',
            'finalizado_em' => now(),
            'resumo_json' => ['erro' => $e->getMessage()],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertStagingRow(string $table, ?int $lojaId, string $externalId, array $payload, ContaAzulImportBatch $batch): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        DB::table($table)->upsert(
            [[
                'loja_id' => $lojaId,
                'identificador_externo' => $externalId,
                'payload_json' => $json,
                'hash_payload' => hash('sha256', (string) $json),
                'batch_id' => $batch->id,
                'status_conciliacao' => 'novo',
                'observacao_conciliacao' => null,
                'candidato_id_local' => null,
                'candidato_score' => null,
                'candidato_motivo' => null,
                'candidato_json' => null,
                'conciliacao_origem' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['loja_id', 'identificador_externo'],
            [
                'payload_json',
                'hash_payload',
                'batch_id',
                'status_conciliacao',
                'observacao_conciliacao',
                'candidato_id_local',
                'candidato_score',
                'candidato_motivo',
                'candidato_json',
                'conciliacao_origem',
                'updated_at',
            ]
        );
    }

    /**
     * @return array<int, array{id_evento:string, identificador_externo:string, tipo_evento:string}>
     */
    private function financialEventSources(?int $lojaId): array
    {
        $sources = [];
        foreach ([
            ['table' => 'stg_conta_azul_financeiro', 'tipo' => ContaAzulEntityType::TITULO],
            ['table' => 'stg_conta_azul_contas_pagar', 'tipo' => ContaAzulEntityType::CONTA_PAGAR],
        ] as $config) {
            if (!Schema::hasTable($config['table'])) {
                continue;
            }

            foreach ($this->stagingPayloadRows($config['table'], $lojaId) as $row) {
                $payload = $row['payload'];
                $eventId = $this->firstStringNested($payload, ['id_evento', 'idEvento', 'id', 'uuid'])
                    ?: (string) $row['identificador_externo'];
                if ($eventId === '') {
                    continue;
                }

                $sources[$config['tipo'] . ':' . $eventId] = [
                    'id_evento' => $eventId,
                    'identificador_externo' => (string) $row['identificador_externo'],
                    'tipo_evento' => $config['tipo'],
                ];
            }
        }

        if (Schema::hasTable('conta_azul_mapeamentos')) {
            $maps = DB::table('conta_azul_mapeamentos')
                ->whereIn('tipo_entidade', [ContaAzulEntityType::TITULO, ContaAzulEntityType::CONTA_PAGAR])
                ->whereNotNull('id_externo')
                ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
                ->when($lojaId === null, fn ($q) => $q->whereNull('loja_id'))
                ->get();

            foreach ($maps as $map) {
                $eventId = (string) $map->id_externo;
                if ($eventId === '') {
                    continue;
                }
                $sources[$map->tipo_entidade . ':' . $eventId] = [
                    'id_evento' => $eventId,
                    'identificador_externo' => $eventId,
                    'tipo_evento' => (string) $map->tipo_entidade,
                ];
            }
        }

        return array_values($sources);
    }

    /**
     * @return array<int, string>
     */
    private function financialAccountSources(?int $lojaId): array
    {
        $sources = [];
        foreach ($this->stagingPayloadRows('stg_conta_azul_contas_financeiras', $lojaId) as $row) {
            $accountId = (string) $row['identificador_externo'];
            if ($accountId !== '') {
                $sources[$accountId] = $accountId;
            }
        }

        if (Schema::hasTable('conta_azul_mapeamentos')) {
            $maps = DB::table('conta_azul_mapeamentos')
                ->where('tipo_entidade', ContaAzulEntityType::CONTA_FINANCEIRA)
                ->whereNotNull('id_externo')
                ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
                ->when($lojaId === null, fn ($q) => $q->whereNull('loja_id'))
                ->pluck('id_externo');

            foreach ($maps as $accountId) {
                $accountId = (string) $accountId;
                if ($accountId !== '') {
                    $sources[$accountId] = $accountId;
                }
            }
        }

        return array_values($sources);
    }

    /**
     * @return array<int, array{identificador_externo:string, payload:array<string, mixed>}>
     */
    private function stagingPayloadRows(string $table, ?int $lojaId): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->when($lojaId === null, fn ($q) => $q->whereNull('loja_id'))
            ->orderBy('id')
            ->get()
            ->map(function ($row) {
                $payload = json_decode((string) $row->payload_json, true);

                return [
                    'identificador_externo' => (string) $row->identificador_externo,
                    'payload' => is_array($payload) ? $payload : [],
                ];
            })
            ->filter(fn (array $row) => $row['payload'] !== [])
            ->values()
            ->all();
    }

    private function countRows(string $table, ?int $lojaId): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        return DB::table($table)
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->when($lojaId === null, fn ($q) => $q->whereNull('loja_id'))
            ->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function responseItemsOrObject(mixed $json): array
    {
        $items = $this->extractItems($json);
        if ($items !== []) {
            return $items;
        }

        if (is_array($json) && !array_is_list($json)) {
            return [$json];
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function paymentMethodCodes(?int $lojaId): array
    {
        $codes = [];
        foreach (['stg_conta_azul_parcelas', 'stg_conta_azul_baixas'] as $table) {
            foreach ($this->stagingPayloadRows($table, $lojaId) as $row) {
                $code = $this->firstStringNested($row['payload'], [
                    'metodo_pagamento',
                    'metodoPagamento',
                    'forma_pagamento',
                    'formaPagamento',
                    'payment_method',
                    'paymentMethod',
                ]);
                if ($code !== '') {
                    $codes[$this->normalizeCode($code)] = $this->normalizeCode($code);
                }
            }
        }

        return array_values($codes);
    }

    private function paymentMethodName(string $code): string
    {
        return [
            'PIX' => 'PIX',
            'BOLETO' => 'Boleto',
            'TRANSFERENCIA' => 'Transferencia',
            'TED' => 'TED',
            'DOC' => 'DOC',
            'DINHEIRO' => 'Dinheiro',
            'CARTAO_CREDITO' => 'Cartao de credito',
            'CARTAO_DEBITO' => 'Cartao de debito',
        ][$code] ?? Str::of($code)->lower()->replace('_', ' ')->title()->toString();
    }

    private function validContaAzulPageSize(int $pageSize): int
    {
        return in_array($pageSize, [10, 20, 50, 100], true) ? $pageSize : 50;
    }

    /**
     * @param  array<string, string>  $params
     */
    private function replacePath(string $path, array $params): string
    {
        foreach ($params as $key => $value) {
            $path = str_replace('{' . $key . '}', rawurlencode($value), $path);
        }

        return ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function firstStringNested(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->valueAtPath($payload, $key);
            if ($value !== null && $value !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function valueAtPath(array $payload, string $path): mixed
    {
        if (array_key_exists($path, $payload) && (is_scalar($payload[$path]) || $payload[$path] === null)) {
            return $payload[$path];
        }

        if (!str_contains($path, '.')) {
            return null;
        }

        $cursor = $payload;
        foreach (explode('.', $path) as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return null;
            }

            $cursor = $cursor[$part];
        }

        return is_scalar($cursor) || $cursor === null ? $cursor : null;
    }

    private function normalizeCode(string $code): string
    {
        $code = Str::ascii(trim($code));
        $code = strtoupper((string) preg_replace('/[^A-Z0-9]+/', '_', $code));

        return trim($code, '_');
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
            'contas',
            'contasFinanceiras',
            'categorias',
            'centrosCusto',
            'centros_custo',
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
        foreach (['id', 'uuid', 'id_legado', 'codigo', 'legacyId', 'idEvento', 'idTitulo', 'idParcela', 'idNota', 'idConta', 'idCategoria', 'idCentroCusto'] as $key) {
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
