<?php

namespace App\Integrations\ContaAzul\Services;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Integrations\ContaAzul\Models\ContaAzulSyncLog;
use App\Models\Cliente;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use Illuminate\Support\Facades\DB;

class ConciliacaoContaAzulService
{
    public function __construct(
        private readonly ContaAzulAutoMatchService $autoMatch
    ) {
    }

    /**
     * @return array{conciliados:int, pendentes:int, conflitos:int}
     */
    public function conciliarPessoas(?int $lojaId = null): array
    {
        return $this->conciliarStaging(
            'stg_conta_azul_pessoas',
            ContaAzulEntityType::PESSOA,
            $lojaId,
            fn (object $row, array $payload) => $this->autoMatch->matchPessoa($row, $payload, $lojaId)
        );
    }

    /**
     * @return array{conciliados:int, pendentes:int, conflitos:int}
     */
    public function conciliarProdutos(?int $lojaId = null): array
    {
        return $this->conciliarStaging(
            'stg_conta_azul_produtos',
            ContaAzulEntityType::PRODUTO,
            $lojaId,
            fn (object $row, array $payload) => $this->autoMatch->matchProduto($row, $payload, $lojaId)
        );
    }

    /**
     * @return array{conciliados:int, pendentes:int, conflitos:int}
     */
    public function conciliarVendas(?int $lojaId = null): array
    {
        return $this->conciliarStaging(
            'stg_conta_azul_vendas',
            ContaAzulEntityType::VENDA,
            $lojaId,
            fn (object $row, array $payload) => $this->autoMatch->matchVenda($row, $payload, $lojaId)
        );
    }

    /**
     * @return array{conciliados:int, pendentes:int, conflitos:int}
     */
    public function conciliarTitulos(?int $lojaId = null): array
    {
        return $this->conciliarStaging(
            'stg_conta_azul_financeiro',
            ContaAzulEntityType::TITULO,
            $lojaId,
            fn (object $row, array $payload) => $this->autoMatch->matchTitulo($row, $payload, $lojaId)
        );
    }

    /**
     * @return array{conciliados:int, pendentes:int, conflitos:int}
     */
    public function conciliarContasPagar(?int $lojaId = null): array
    {
        return $this->conciliarStaging(
            'stg_conta_azul_contas_pagar',
            ContaAzulEntityType::CONTA_PAGAR,
            $lojaId,
            fn (object $row, array $payload) => $this->autoMatch->matchContaPagar($row, $payload, $lojaId)
        );
    }

    /**
     * @return array{conciliados:int, pendentes:int, conflitos:int}
     */
    public function conciliarBaixas(?int $lojaId = null): array
    {
        return $this->conciliarStaging(
            'stg_conta_azul_baixas',
            ContaAzulEntityType::BAIXA,
            $lojaId,
            fn (object $row, array $payload) => $this->autoMatch->matchBaixa($row, $payload, $lojaId)
        );
    }

    /**
     * @return array{conciliados:int, pendentes:int, conflitos:int}
     */
    public function conciliarParcelas(?int $lojaId = null): array
    {
        return $this->conciliarStaging(
            'stg_conta_azul_parcelas',
            ContaAzulEntityType::PARCELA,
            $lojaId,
            fn (object $row, array $payload) => $this->autoMatch->matchParcela($row, $payload, $lojaId)
        );
    }

    /**
     * @return array{conciliados:int, pendentes:int, conflitos:int}
     */
    public function conciliarSaldosContasFinanceiras(?int $lojaId = null): array
    {
        return $this->conciliarStaging(
            'stg_conta_azul_saldos_contas_financeiras',
            ContaAzulEntityType::SALDO_CONTA_FINANCEIRA,
            $lojaId,
            fn (object $row, array $payload) => $this->autoMatch->matchSaldoContaFinanceira($row, $payload, $lojaId)
        );
    }

    /**
     * @return array{conciliados:int, pendentes:int, conflitos:int}
     */
    public function conciliarFormasPagamento(?int $lojaId = null): array
    {
        return $this->conciliarStaging(
            'stg_conta_azul_formas_pagamento',
            ContaAzulEntityType::FORMA_PAGAMENTO,
            $lojaId,
            fn (object $row, array $payload) => $this->autoMatch->matchFormaPagamento($row, $payload, $lojaId)
        );
    }

    /**
     * @return array{conciliados:int, pendentes:int, conflitos:int}
     */
    public function conciliarContasFinanceiras(?int $lojaId = null): array
    {
        return $this->conciliarStaging(
            'stg_conta_azul_contas_financeiras',
            ContaAzulEntityType::CONTA_FINANCEIRA,
            $lojaId,
            fn (object $row, array $payload) => $this->autoMatch->matchContaFinanceira($row, $payload, $lojaId)
        );
    }

    /**
     * @return array{conciliados:int, pendentes:int, conflitos:int}
     */
    public function conciliarCategoriasFinanceiras(?int $lojaId = null): array
    {
        $result = $this->conciliarStaging(
            'stg_conta_azul_categorias_financeiras',
            ContaAzulEntityType::CATEGORIA_FINANCEIRA,
            $lojaId,
            fn (object $row, array $payload) => $this->autoMatch->matchCategoriaFinanceira($row, $payload, $lojaId)
        );

        $this->preencherHierarquiaCatalogo(
            ContaAzulEntityType::CATEGORIA_FINANCEIRA,
            'stg_conta_azul_categorias_financeiras',
            'categorias_financeiras',
            'categoria_pai_id',
            $lojaId
        );

        return $result;
    }

    /**
     * @return array{conciliados:int, pendentes:int, conflitos:int}
     */
    public function conciliarCentrosCusto(?int $lojaId = null): array
    {
        $result = $this->conciliarStaging(
            'stg_conta_azul_centros_custo',
            ContaAzulEntityType::CENTRO_CUSTO,
            $lojaId,
            fn (object $row, array $payload) => $this->autoMatch->matchCentroCusto($row, $payload, $lojaId)
        );

        $this->preencherHierarquiaCatalogo(
            ContaAzulEntityType::CENTRO_CUSTO,
            'stg_conta_azul_centros_custo',
            'centros_custo',
            'centro_custo_pai_id',
            $lojaId
        );

        return $result;
    }

    /**
     * @return array<string, array{conciliados:int, pendentes:int, conflitos:int}>
     */
    public function conciliarTudo(?int $lojaId = null): array
    {
        return [
            'pessoas' => $this->conciliarPessoas($lojaId),
            'produtos' => $this->conciliarProdutos($lojaId),
            'vendas' => $this->conciliarVendas($lojaId),
            'titulos' => $this->conciliarTitulos($lojaId),
            'contas_pagar' => $this->conciliarContasPagar($lojaId),
            'parcelas' => $this->conciliarParcelas($lojaId),
            'baixas' => $this->conciliarBaixas($lojaId),
            'contas_financeiras' => $this->conciliarContasFinanceiras($lojaId),
            'saldos_contas_financeiras' => $this->conciliarSaldosContasFinanceiras($lojaId),
            'categorias_financeiras' => $this->conciliarCategoriasFinanceiras($lojaId),
            'centros_custo' => $this->conciliarCentrosCusto($lojaId),
            'formas_pagamento' => $this->conciliarFormasPagamento($lojaId),
        ];
    }

    /**
     * @return array<int, array{entidade: string, pendentes: int, conflitos: int}>
     */
    public function resumoPendencias(?int $lojaId = null): array
    {
        $tables = [
            'pessoa' => 'stg_conta_azul_pessoas',
            'produto' => 'stg_conta_azul_produtos',
            'venda' => 'stg_conta_azul_vendas',
            'titulo' => 'stg_conta_azul_financeiro',
            'conta_pagar' => 'stg_conta_azul_contas_pagar',
            'parcela' => 'stg_conta_azul_parcelas',
            'baixa' => 'stg_conta_azul_baixas',
            'nota' => 'stg_conta_azul_notas',
            'conta_financeira' => 'stg_conta_azul_contas_financeiras',
            'saldo_conta_financeira' => 'stg_conta_azul_saldos_contas_financeiras',
            'categoria_financeira' => 'stg_conta_azul_categorias_financeiras',
            'centro_custo' => 'stg_conta_azul_centros_custo',
            'forma_pagamento' => 'stg_conta_azul_formas_pagamento',
        ];

        $out = [];
        foreach ($tables as $label => $table) {
            $pend = DB::table($table)
                ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
                ->when($lojaId === null, fn ($q) => $q->whereNull('loja_id'))
                ->whereIn('status_conciliacao', ['novo', 'pendente'])
                ->count();
            $conf = DB::table($table)
                ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
                ->when($lojaId === null, fn ($q) => $q->whereNull('loja_id'))
                ->where('status_conciliacao', 'conflito')
                ->count();
            $auto = DB::table($table)
                ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
                ->when($lojaId === null, fn ($q) => $q->whereNull('loja_id'))
                ->where('status_conciliacao', 'conciliado')
                ->where('conciliacao_origem', 'auto')
                ->count();
            $sugestao = DB::table($table)
                ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
                ->when($lojaId === null, fn ($q) => $q->whereNull('loja_id'))
                ->whereIn('status_conciliacao', ['novo', 'pendente'])
                ->whereNotNull('candidato_id_local')
                ->count();
            $semCandidato = DB::table($table)
                ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
                ->when($lojaId === null, fn ($q) => $q->whereNull('loja_id'))
                ->whereIn('status_conciliacao', ['novo', 'pendente'])
                ->whereNull('candidato_id_local')
                ->count();
            $out[] = [
                'entidade' => $label,
                'pendentes' => $pend,
                'conflitos' => $conf,
                'auto_conciliadas' => $auto,
                'com_sugestao' => $sugestao,
                'pendentes_sem_candidato' => $semCandidato,
            ];
        }

        return $out;
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listarPendenciasDetalhadas(
        ?int $lojaId = null,
        ?string $entidade = null,
        array $statuses = ['novo', 'pendente', 'conflito'],
        int $perPage = 50,
        int $page = 1,
        ?string $bucket = null
    ): array
    {
        $statuses = array_values(array_intersect($statuses, ['novo', 'pendente', 'conflito', 'ignorado', 'conciliado']));
        if ($statuses === []) {
            $statuses = ['novo', 'pendente', 'conflito'];
        }
        if ($bucket === 'auto') {
            $statuses = ['conciliado'];
        } elseif ($bucket === 'sugestao' || $bucket === 'pendente') {
            $statuses = ['novo', 'pendente'];
        } elseif ($bucket === 'conflito') {
            $statuses = ['conflito'];
        }

        $page = max(1, $page);
        $perPage = max(1, min($perPage, 200));
        $tables = $entidade
            ? [$this->normalizeEntidade($entidade) => $this->stagingTableFor($entidade)]
            : $this->stagingTables();

        $out = [];
        foreach ($tables as $tipo => $table) {
            $rows = DB::table($table)
                ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
                ->when($lojaId === null, fn ($q) => $q->whereNull('loja_id'))
                ->whereIn('status_conciliacao', $statuses)
                ->when($bucket === 'auto', fn ($q) => $q
                    ->where('status_conciliacao', 'conciliado')
                    ->where('conciliacao_origem', 'auto'))
                ->when($bucket === 'sugestao', fn ($q) => $q
                    ->whereIn('status_conciliacao', ['novo', 'pendente'])
                    ->whereNotNull('candidato_id_local'))
                ->when($bucket === 'pendente', fn ($q) => $q
                    ->whereIn('status_conciliacao', ['novo', 'pendente'])
                    ->whereNull('candidato_id_local'))
                ->when($bucket === 'conflito', fn ($q) => $q->where('status_conciliacao', 'conflito'))
                ->orderByRaw("FIELD(status_conciliacao, 'conflito', 'novo', 'pendente', 'ignorado', 'conciliado')")
                ->orderByDesc('updated_at')
                ->get();

            foreach ($rows as $row) {
                $payload = json_decode((string) $row->payload_json, true);
                $payload = is_array($payload) ? $payload : [];

                $out[] = [
                    'id' => (int) $row->id,
                    'entidade' => $tipo,
                    'loja_id' => $row->loja_id !== null ? (int) $row->loja_id : null,
                    'identificador_externo' => (string) $row->identificador_externo,
                    'status_conciliacao' => (string) $row->status_conciliacao,
                    'observacao_conciliacao' => $row->observacao_conciliacao,
                    'payload_resumo' => $this->payloadResumo($payload),
                    'payload_json' => $payload,
                    'candidato_id_local' => isset($row->candidato_id_local) ? (int) $row->candidato_id_local : null,
                    'candidato_score' => isset($row->candidato_score) ? (int) $row->candidato_score : null,
                    'candidato_motivo' => $row->candidato_motivo ?? null,
                    'candidato' => $this->decodeCandidate($row->candidato_json ?? null),
                    'conciliacao_origem' => $row->conciliacao_origem ?? null,
                    'updated_at' => $row->updated_at,
                    'created_at' => $row->created_at,
                ];
            }
        }

        usort($out, function (array $a, array $b): int {
            $order = ['conflito' => 0, 'novo' => 1, 'pendente' => 2, 'ignorado' => 3, 'conciliado' => 4];
            $statusCmp = ($order[$a['status_conciliacao']] ?? 9) <=> ($order[$b['status_conciliacao']] ?? 9);
            if ($statusCmp !== 0) {
                return $statusCmp;
            }

            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });

        $total = count($out);
        $offset = ($page - 1) * $perPage;
        $data = array_slice($out, $offset, $perPage);

        return [
            'data' => array_values($data),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($offset + count($data), $total),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolverPendencia(
        string $entidade,
        int $stagingId,
        ?int $lojaId,
        string $acao,
        ?int $idLocal = null,
        ?string $observacao = null,
        ?string $codigoExterno = null
    ): array {
        $tipo = $this->normalizeEntidade($entidade);
        $table = $this->stagingTableFor($tipo);

        $row = DB::table($table)
            ->where('id', $stagingId)
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->when($lojaId === null, fn ($q) => $q->whereNull('loja_id'))
            ->first();

        if (!$row) {
            throw new ContaAzulException('Pendencia Conta Azul nao encontrada.', 'pendencia_nao_encontrada');
        }

        $acao = strtolower(trim($acao));
        if ($acao === 'ignorar') {
            DB::table($table)->where('id', $stagingId)->update([
                'status_conciliacao' => 'ignorado',
                'observacao_conciliacao' => $observacao ?: 'Ignorado manualmente',
                'conciliacao_origem' => 'manual',
                'updated_at' => now(),
            ]);
            $this->logConciliacao($lojaId, $tipo, (string) $row->identificador_externo, 'ignorado', $observacao ?: 'Ignorado manualmente');

            return ['status' => 'ignorado'];
        }

        if ($acao !== 'vincular') {
            throw new ContaAzulException('Acao de pendencia Conta Azul invalida.', 'acao_invalida');
        }

        if ($tipo === ContaAzulEntityType::NOTA) {
            throw new ContaAzulException('Notas fiscais Conta Azul estao em modo somente leitura; ignore a pendencia ou conclua o desenho fiscal antes de vincular.', 'nota_read_only');
        }

        if ($idLocal === null || $idLocal <= 0) {
            throw new ContaAzulException('Informe um id local valido para vincular a pendencia.', 'id_local_invalido');
        }

        $payloadJson = (string) $row->payload_json;
        $hashExt = hash('sha256', $payloadJson);
        $extId = (string) $row->identificador_externo;

        $existing = ContaAzulMapeamento::query()
            ->where('tipo_entidade', $tipo)
            ->where('id_externo', $extId)
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->first();

        if ($existing && (int) $existing->id_local !== $idLocal) {
            throw new ContaAzulException('Este id externo ja esta vinculado a outro registro local.', 'mapeamento_conflitante');
        }

        ContaAzulMapeamento::updateOrCreate(
            [
                'loja_id' => $lojaId,
                'tipo_entidade' => $tipo,
                'id_local' => $idLocal,
            ],
            [
                'id_externo' => $extId,
                'codigo_externo' => $codigoExterno,
                'origem_inicial' => 'manual',
                'hash_payload_externo' => $hashExt,
                'sincronizado_em' => now(),
                'metadata_json' => ['staging_id' => $stagingId, 'observacao' => $observacao],
            ]
        );

        DB::table($table)->where('id', $stagingId)->update([
            'status_conciliacao' => 'conciliado',
            'observacao_conciliacao' => $observacao,
            'candidato_id_local' => $idLocal,
            'candidato_score' => 100,
            'candidato_motivo' => 'Vinculado manualmente',
            'candidato_json' => json_encode([
                'id_local' => $idLocal,
                'score' => 100,
                'motivo' => 'Vinculado manualmente',
                'label' => 'Registro local #' . $idLocal,
            ], JSON_UNESCAPED_UNICODE),
            'conciliacao_origem' => 'manual',
            'updated_at' => now(),
        ]);
        $this->logConciliacao($lojaId, $tipo, $extId, 'conciliado', $observacao ?: 'Vinculado manualmente');

        return [
            'status' => 'conciliado',
            'id_local' => $idLocal,
            'id_externo' => $extId,
        ];
    }

    /**
     * @param  callable(object, array): array{status:'conciliado'|'pendente'|'conflito'|'ignorado', id_local?:int, observacao?:string}  $matcher
     * @return array{conciliados:int, pendentes:int, conflitos:int}
     */
    private function conciliarStaging(string $table, string $tipoEntidade, ?int $lojaId, callable $matcher): array
    {
        $conciliados = 0;
        $pendentes = 0;
        $conflitos = 0;

        $rows = DB::table($table)
            ->whereIn('status_conciliacao', ['novo', 'pendente', 'conflito'])
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->when($lojaId === null, fn ($q) => $q->whereNull('loja_id'))
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload_json, true);
            if (!is_array($payload)) {
                DB::table($table)->where('id', $row->id)->update(array_merge([
                    'status_conciliacao' => 'ignorado',
                    'observacao_conciliacao' => 'Payload inválido',
                    'updated_at' => now(),
                ], $this->clearCandidateColumns('ignorado')));
                $pendentes++;
                continue;
            }

            $result = $matcher($row, $payload);
            $status = $result['status'];

            if ($status === 'ignorado') {
                DB::table($table)->where('id', $row->id)->update(array_merge([
                    'status_conciliacao' => 'ignorado',
                    'observacao_conciliacao' => $result['observacao'] ?? null,
                    'updated_at' => now(),
                ], $this->candidateColumns($result, 'auto')));
                $pendentes++;
                continue;
            }

            if ($status === 'pendente') {
                DB::table($table)->where('id', $row->id)->update(array_merge([
                    'status_conciliacao' => 'pendente',
                    'observacao_conciliacao' => $result['observacao'] ?? 'Sem correspondência',
                    'updated_at' => now(),
                ], $this->candidateColumns($result, isset($result['candidato']) ? 'sugerido' : null)));
                $pendentes++;
                continue;
            }

            if ($status === 'conflito') {
                DB::table($table)->where('id', $row->id)->update(array_merge([
                    'status_conciliacao' => 'conflito',
                    'observacao_conciliacao' => $result['observacao'] ?? 'Conflito',
                    'updated_at' => now(),
                ], $this->candidateColumns($result, 'conflito')));
                $conflitos++;
                $this->logConciliacao($lojaId, $tipoEntidade, (string) $row->identificador_externo, 'conflito', $result['observacao'] ?? null);
                continue;
            }

            $idLocal = (int) ($result['id_local'] ?? 0);
            if ($idLocal <= 0) {
                $pendentes++;
                continue;
            }

            $extId = (string) $row->identificador_externo;
            $hashExt = hash('sha256', (string) $row->payload_json);

            $existing = ContaAzulMapeamento::query()
                ->where('tipo_entidade', $tipoEntidade)
                ->where('id_externo', $extId)
                ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
                ->first();

            if ($existing && (int) $existing->id_local !== $idLocal) {
                DB::table($table)->where('id', $row->id)->update([
                    'status_conciliacao' => 'conflito',
                    'observacao_conciliacao' => 'Mapeamento existente aponta para outro registro local',
                    'updated_at' => now(),
                ]);
                $conflitos++;
                continue;
            }

            ContaAzulMapeamento::updateOrCreate(
                [
                    'loja_id' => $lojaId,
                    'tipo_entidade' => $tipoEntidade,
                    'id_local' => $idLocal,
                ],
                [
                    'id_externo' => $extId,
                    'codigo_externo' => $result['codigo_externo'] ?? null,
                    'origem_inicial' => 'import',
                    'hash_payload_externo' => $hashExt,
                    'sincronizado_em' => now(),
                    'metadata_json' => [
                        'staging_id' => $row->id,
                        'auto_match' => $result['candidato'] ?? null,
                    ],
                ]
            );

            DB::table($table)->where('id', $row->id)->update(array_merge([
                'status_conciliacao' => 'conciliado',
                'observacao_conciliacao' => null,
                'updated_at' => now(),
            ], $this->candidateColumns($result, 'auto')));
            $conciliados++;
        }

        return compact('conciliados', 'pendentes', 'conflitos');
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function candidateColumns(array $result, ?string $origin): array
    {
        $candidate = $result['candidato'] ?? null;
        $candidates = $result['candidatos'] ?? null;
        $json = $candidates ?: $candidate;

        if (!is_array($candidate)) {
            return $this->clearCandidateColumns($origin);
        }

        return [
            'candidato_id_local' => isset($candidate['id_local']) ? (int) $candidate['id_local'] : null,
            'candidato_score' => isset($candidate['score']) ? (int) $candidate['score'] : null,
            'candidato_motivo' => $candidate['motivo'] ?? null,
            'candidato_json' => $json ? json_encode($json, JSON_UNESCAPED_UNICODE) : null,
            'conciliacao_origem' => $origin,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clearCandidateColumns(?string $origin = null): array
    {
        return [
            'candidato_id_local' => null,
            'candidato_score' => null,
            'candidato_motivo' => null,
            'candidato_json' => null,
            'conciliacao_origem' => $origin,
        ];
    }

    private function decodeCandidate(mixed $json): ?array
    {
        if (!$json) {
            return null;
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array{status:'conciliado'|'pendente'|'conflito'|'ignorado', id_local?:int, observacao?:string, codigo_externo?:string}
     */
    private function matchPessoa(object $row, array $payload, ?int $lojaId): array
    {
        $doc = $this->firstString($payload, ['cpf', 'cnpj', 'documento', 'numeroDocumento', 'cpfCnpj']);
        $norm = $this->normalizeDocumento($doc);
        if ($norm === '') {
            return ['status' => 'pendente', 'observacao' => 'Documento ausente no payload'];
        }

        $cliente = Cliente::query()
            ->where(function ($q) use ($doc, $norm) {
                $q->where('documento', $doc)
                    ->orWhereRaw(
                        'REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(documento, ""), ".", ""), "/", ""), "-", ""), " ", "") = ?',
                        [$norm]
                    );
            })
            ->first();

        if (!$cliente) {
            return ['status' => 'pendente', 'observacao' => 'Sem correspondência local por documento'];
        }

        $extId = (string) $row->identificador_externo;
        $existing = ContaAzulMapeamento::query()
            ->where('tipo_entidade', ContaAzulEntityType::PESSOA)
            ->where('id_externo', $extId)
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->first();

        if ($existing && (int) $existing->id_local !== (int) $cliente->id) {
            return ['status' => 'conflito', 'observacao' => 'Mapeamento existente para outro cliente'];
        }

        return ['status' => 'conciliado', 'id_local' => (int) $cliente->id];
    }

    /**
     * @return array{status:'conciliado'|'pendente'|'conflito'|'ignorado', id_local?:int, observacao?:string, codigo_externo?:string}
     */
    private function matchProduto(object $row, array $payload, ?int $lojaId): array
    {
        $extId = (string) $row->identificador_externo;

        $map = ContaAzulMapeamento::query()
            ->where('tipo_entidade', ContaAzulEntityType::PRODUTO)
            ->where('id_externo', $extId)
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->first();
        if ($map) {
            return ['status' => 'conciliado', 'id_local' => (int) $map->id_local, 'codigo_externo' => $map->codigo_externo];
        }

        $codigo = $this->firstString($payload, ['sku', 'codigo', 'codigoSKU', 'codigoServico']);
        if ($codigo !== '') {
            $v = ProdutoVariacao::query()->where('sku_interno', $codigo)->first();
            if ($v) {
                return ['status' => 'conciliado', 'id_local' => (int) $v->produto_id, 'codigo_externo' => $codigo];
            }
            $p = Produto::query()->where('codigo_produto', $codigo)->first();
            if ($p) {
                return ['status' => 'conciliado', 'id_local' => (int) $p->id, 'codigo_externo' => $codigo];
            }
        }

        $nome = $this->firstString($payload, ['nome', 'descricao']);
        if ($nome !== '') {
            $norm = $this->normalizeNome($nome);
            $p = Produto::query()
                ->whereRaw('LOWER(nome) = ?', [$norm])
                ->orWhere('nome', $nome)
                ->first();
            if ($p) {
                return ['status' => 'conciliado', 'id_local' => (int) $p->id];
            }
        }

        return ['status' => 'pendente', 'observacao' => 'Produto sem SKU/código/nome correspondente'];
    }

    /**
     * @return array{status:'conciliado'|'pendente'|'conflito'|'ignorado', id_local?:int, observacao?:string}
     */
    private function matchVenda(object $row, array $payload, ?int $lojaId): array
    {
        $extId = (string) $row->identificador_externo;

        $map = ContaAzulMapeamento::query()
            ->where('tipo_entidade', ContaAzulEntityType::VENDA)
            ->where('id_externo', $extId)
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->first();
        if ($map) {
            return ['status' => 'conciliado', 'id_local' => (int) $map->id_local];
        }

        $numero = $this->firstString($payload, ['numero', 'numeroVenda', 'numeroPedido']);
        if ($numero !== '') {
            $pedido = Pedido::query()->where('numero_externo', $numero)->first();
            if ($pedido) {
                return ['status' => 'conciliado', 'id_local' => (int) $pedido->id];
            }
        }

        $idClienteExt = $this->firstString($payload, ['idCliente', 'clienteId', 'id_cliente']);
        $data = $this->parseDate($this->firstString($payload, ['data', 'dataVenda', 'dataPedido', 'dataCriacao']));
        $valor = $this->parseMoney($this->firstString($payload, ['valorTotal', 'valor', 'total', 'valorLiquido']));

        $clienteLocalId = null;
        if ($idClienteExt !== '') {
            $m = ContaAzulMapeamento::query()
                ->where('tipo_entidade', ContaAzulEntityType::PESSOA)
                ->where('id_externo', $idClienteExt)
                ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
                ->first();
            $clienteLocalId = $m ? (int) $m->id_local : null;
        }

        if ($clienteLocalId && $data && $valor !== null) {
            $pedido = Pedido::query()
                ->where('id_cliente', $clienteLocalId)
                ->whereDate('data_pedido', $data->format('Y-m-d'))
                ->get()
                ->first(fn ($p) => $this->moneyClose((float) $p->valor_total, $valor));

            if ($pedido) {
                return ['status' => 'conciliado', 'id_local' => (int) $pedido->id];
            }
        }

        return ['status' => 'pendente', 'observacao' => 'Venda sem número externo nem combinação cliente/data/total'];
    }

    /**
     * @return array{status:'conciliado'|'pendente'|'conflito'|'ignorado', id_local?:int, observacao?:string}
     */
    private function matchTitulo(object $row, array $payload, ?int $lojaId): array
    {
        $extId = (string) $row->identificador_externo;

        $map = ContaAzulMapeamento::query()
            ->where('tipo_entidade', ContaAzulEntityType::TITULO)
            ->where('id_externo', $extId)
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->first();
        if ($map) {
            return ['status' => 'conciliado', 'id_local' => (int) $map->id_local];
        }

        $idVendaExt = $this->firstString($payload, ['idVenda', 'vendaId', 'id_venda']);
        if ($idVendaExt !== '') {
            $mv = ContaAzulMapeamento::query()
                ->where('tipo_entidade', ContaAzulEntityType::VENDA)
                ->where('id_externo', $idVendaExt)
                ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
                ->first();
            if ($mv) {
                $pedidoId = (int) $mv->id_local;
                $valor = $this->parseMoney($this->firstString($payload, ['valor', 'valorTitulo', 'valorLiquido', 'valorParcela']));
                $venc = $this->parseDate($this->firstString($payload, ['dataVencimento', 'vencimento', 'data_vencimento']));
                $q = ContaReceber::query()->where('pedido_id', $pedidoId);
                if ($venc) {
                    $q->whereDate('data_vencimento', $venc->format('Y-m-d'));
                }
                $conta = $q->get()->first(function ($c) use ($valor) {
                    if ($valor === null) {
                        return true;
                    }
                    $liq = (float) $c->valor_liquido;

                    return $this->moneyClose($liq, $valor);
                });
                if ($conta) {
                    return ['status' => 'conciliado', 'id_local' => (int) $conta->id];
                }
            }
        }

        $idClienteExt = $this->firstString($payload, ['idCliente', 'clienteId']);
        $valor = $this->parseMoney($this->firstString($payload, ['valor', 'valorTitulo', 'valorLiquido']));
        $venc = $this->parseDate($this->firstString($payload, ['dataVencimento', 'vencimento']));
        if ($idClienteExt !== '' && $valor !== null && $venc) {
            $m = ContaAzulMapeamento::query()
                ->where('tipo_entidade', ContaAzulEntityType::PESSOA)
                ->where('id_externo', $idClienteExt)
                ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
                ->first();
            if ($m) {
                $conta = ContaReceber::query()
                    ->whereHas('pedido', fn ($q) => $q->where('id_cliente', (int) $m->id_local))
                    ->whereDate('data_vencimento', $venc->format('Y-m-d'))
                    ->get()
                    ->first(fn ($c) => $this->moneyClose((float) $c->valor_liquido, $valor));

                if ($conta) {
                    return ['status' => 'conciliado', 'id_local' => (int) $conta->id];
                }
            }
        }

        return ['status' => 'pendente', 'observacao' => 'Título sem vínculo com venda ou cliente/data/valor'];
    }

    /**
     * @return array{status:'conciliado'|'pendente'|'conflito'|'ignorado', id_local?:int, observacao?:string}
     */
    private function matchBaixa(object $row, array $payload, ?int $lojaId): array
    {
        $extId = (string) $row->identificador_externo;

        $map = ContaAzulMapeamento::query()
            ->where('tipo_entidade', ContaAzulEntityType::BAIXA)
            ->where('id_externo', $extId)
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->first();
        if ($map) {
            return ['status' => 'conciliado', 'id_local' => (int) $map->id_local];
        }

        $idTituloExt = $this->firstString($payload, ['idTitulo', 'tituloId', 'idParcela', 'idEvento']);
        $valor = $this->parseMoney($this->firstString($payload, ['valor', 'valorBaixa', 'valorPago']));
        $data = $this->parseDate($this->firstString($payload, ['data', 'dataPagamento', 'dataBaixa']));

        if ($idTituloExt !== '') {
            $mt = ContaAzulMapeamento::query()
                ->where('tipo_entidade', ContaAzulEntityType::TITULO)
                ->where('id_externo', $idTituloExt)
                ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
                ->first();
            if ($mt && $valor !== null) {
                $contaId = (int) $mt->id_local;
                $q = ContaReceberPagamento::query()->where('conta_receber_id', $contaId);
                if ($data) {
                    $q->whereDate('data_pagamento', $data->format('Y-m-d'));
                }
                $pg = $q->get()->first(fn ($p) => $this->moneyClose((float) $p->valor, $valor));
                if ($pg) {
                    return ['status' => 'conciliado', 'id_local' => (int) $pg->id];
                }
            }
        }

        return ['status' => 'pendente', 'observacao' => 'Baixa sem título mapeado ou pagamento correspondente'];
    }

    private function preencherHierarquiaCatalogo(
        string $tipo,
        string $stagingTable,
        string $localTable,
        string $parentColumn,
        ?int $lojaId
    ): void {
        $rows = DB::table($stagingTable)
            ->where('status_conciliacao', 'conciliado')
            ->whereNotNull('candidato_id_local')
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->when($lojaId === null, fn ($q) => $q->whereNull('loja_id'))
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload_json, true);
            if (!is_array($payload)) {
                continue;
            }

            $parentExt = $this->parentExternalId($payload);
            if ($parentExt === '') {
                continue;
            }

            $parentLocalId = ContaAzulMapeamento::query()
                ->where('tipo_entidade', $tipo)
                ->where('id_externo', $parentExt)
                ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
                ->value('id_local');
            $localId = (int) $row->candidato_id_local;

            if (!$parentLocalId || (int) $parentLocalId === $localId) {
                continue;
            }

            DB::table($localTable)
                ->where('id', $localId)
                ->whereNull($parentColumn)
                ->update([
                    $parentColumn => (int) $parentLocalId,
                    'updated_at' => now(),
                ]);
        }
    }

    private function parentExternalId(array $payload): string
    {
        return $this->firstStringNested($payload, [
            'idCategoriaPai',
            'categoriaPaiId',
            'categoria_pai_id',
            'idCentroCustoPai',
            'centroCustoPaiId',
            'centro_custo_pai_id',
            'parentId',
            'parent.id',
            'pai.id',
            'idPai',
            'paiId',
        ]);
    }

    /**
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

    private function logConciliacao(?int $lojaId, string $tipo, string $idExterno, string $status, ?string $msg): void
    {
        ContaAzulSyncLog::create([
            'loja_id' => $lojaId,
            'tipo_entidade' => $tipo,
            'id_local' => null,
            'id_externo' => $idExterno,
            'direcao' => 'import',
            'status' => $status,
            'tentativa' => 1,
            'payload_resumo' => json_encode(['fase' => 'conciliacao'], JSON_UNESCAPED_UNICODE),
            'resposta_resumo' => $msg,
            'erro_mensagem' => $msg,
            'executado_em' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function stagingTables(): array
    {
        return [
            ContaAzulEntityType::PESSOA => 'stg_conta_azul_pessoas',
            ContaAzulEntityType::PRODUTO => 'stg_conta_azul_produtos',
            ContaAzulEntityType::VENDA => 'stg_conta_azul_vendas',
            ContaAzulEntityType::TITULO => 'stg_conta_azul_financeiro',
            ContaAzulEntityType::CONTA_PAGAR => 'stg_conta_azul_contas_pagar',
            ContaAzulEntityType::PARCELA => 'stg_conta_azul_parcelas',
            ContaAzulEntityType::BAIXA => 'stg_conta_azul_baixas',
            ContaAzulEntityType::NOTA => 'stg_conta_azul_notas',
            ContaAzulEntityType::CONTA_FINANCEIRA => 'stg_conta_azul_contas_financeiras',
            ContaAzulEntityType::SALDO_CONTA_FINANCEIRA => 'stg_conta_azul_saldos_contas_financeiras',
            ContaAzulEntityType::CATEGORIA_FINANCEIRA => 'stg_conta_azul_categorias_financeiras',
            ContaAzulEntityType::CENTRO_CUSTO => 'stg_conta_azul_centros_custo',
            ContaAzulEntityType::FORMA_PAGAMENTO => 'stg_conta_azul_formas_pagamento',
        ];
    }

    private function stagingTableFor(string $entidade): string
    {
        $tipo = $this->normalizeEntidade($entidade);
        $table = $this->stagingTables()[$tipo] ?? null;
        if (!$table) {
            throw new ContaAzulException('Entidade Conta Azul invalida.', 'entidade_invalida');
        }

        return $table;
    }

    private function normalizeEntidade(string $entidade): string
    {
        return match (strtolower(trim($entidade))) {
            'pessoa', 'pessoas' => ContaAzulEntityType::PESSOA,
            'produto', 'produtos' => ContaAzulEntityType::PRODUTO,
            'venda', 'vendas' => ContaAzulEntityType::VENDA,
            'titulo', 'titulos', 'financeiro' => ContaAzulEntityType::TITULO,
            'conta_pagar', 'contas_pagar', 'contas-pagar' => ContaAzulEntityType::CONTA_PAGAR,
            'parcela', 'parcelas' => ContaAzulEntityType::PARCELA,
            'baixa', 'baixas' => ContaAzulEntityType::BAIXA,
            'nota', 'notas' => ContaAzulEntityType::NOTA,
            'conta_financeira', 'contas_financeiras', 'contas-financeiras', 'conta-financeira' => ContaAzulEntityType::CONTA_FINANCEIRA,
            'saldo_conta_financeira', 'saldo-conta-financeira', 'saldos-contas-financeiras', 'saldos_contas_financeiras' => ContaAzulEntityType::SALDO_CONTA_FINANCEIRA,
            'categoria_financeira', 'categorias_financeiras', 'categorias-financeiras', 'categoria-financeira', 'categoria', 'categorias' => ContaAzulEntityType::CATEGORIA_FINANCEIRA,
            'centro_custo', 'centros_custo', 'centros-custo', 'centro-de-custo', 'centro_custos' => ContaAzulEntityType::CENTRO_CUSTO,
            'forma_pagamento', 'formas_pagamento', 'formas-pagamento', 'formas-de-pagamento' => ContaAzulEntityType::FORMA_PAGAMENTO,
            default => throw new ContaAzulException('Entidade Conta Azul invalida.', 'entidade_invalida'),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function payloadResumo(array $payload): array
    {
        $keys = [
            'nome',
            'descricao',
            'documento',
            'cpf',
            'cnpj',
            'cpfCnpj',
            'codigo',
            'sku',
            'numero',
            'numeroVenda',
            'numero_documento',
            'data',
            'dataVenda',
            'data_vencimento',
            'dataVencimento',
            'valor',
            'valorTotal',
            'valorLiquido',
            'valorPago',
            'valorBaixa',
            'status',
            'situacao',
            'tipo',
            'metodo_pagamento',
            'metodoPagamento',
            'bancoNome',
            'nomeBanco',
            'agencia',
            'conta',
            'moeda',
            'saldo_atual',
            'saldoAtual',
        ];

        $out = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        if ($out !== []) {
            return array_slice($out, 0, 8, true);
        }

        foreach ($payload as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[(string) $key] = $value;
            }
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function firstString(array $payload, array $keys): string
    {
        foreach ($keys as $k) {
            if (!empty($payload[$k])) {
                return trim((string) $payload[$k]);
            }
        }

        return '';
    }

    private function normalizeDocumento(string $doc): string
    {
        return preg_replace('/\D+/', '', $doc) ?? '';
    }

    private function normalizeNome(string $nome): string
    {
        $s = mb_strtolower(preg_replace('/\s+/', ' ', trim($nome)));

        return $s;
    }

    private function parseMoney(?string $s): ?float
    {
        if ($s === null || $s === '') {
            return null;
        }
        $n = str_replace(['.', ' '], ['', ''], str_replace(',', '.', $s));
        if (!is_numeric($n)) {
            return null;
        }

        return (float) $n;
    }

    private function parseDate(?string $s): ?\DateTimeImmutable
    {
        if ($s === null || $s === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($s);
        } catch (\Throwable) {
            return null;
        }
    }

    private function moneyClose(float $a, float $b): bool
    {
        return abs($a - $b) < 0.06;
    }
}
