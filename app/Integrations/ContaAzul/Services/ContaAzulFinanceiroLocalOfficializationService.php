<?php

namespace App\Integrations\ContaAzul\Services;

use App\Enums\LancamentoTipo;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Services\FinanceiroLedgerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContaAzulFinanceiroLocalOfficializationService
{
    public function __construct(private readonly FinanceiroLedgerService $ledger)
    {
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function dryRun(?int $lojaId = null): array
    {
        return [
            'contas_financeiras' => ['previstos' => count($this->distinctStagingRows('stg_conta_azul_contas_financeiras', $lojaId))],
            'saldos_contas_financeiras' => ['previstos' => count($this->distinctStagingRows('stg_conta_azul_saldos_contas_financeiras', $lojaId))],
            'formas_pagamento' => ['previstos' => count($this->formasPagamentoPrevistas($lojaId))],
            'categorias_financeiras' => ['previstos' => count($this->distinctStagingRows('stg_conta_azul_categorias_financeiras', $lojaId))],
            'centros_custo' => ['previstos' => count($this->distinctStagingRows('stg_conta_azul_centros_custo', $lojaId))],
            'notas_fiscais' => ['previstos' => count($this->distinctStagingRows('stg_conta_azul_notas', $lojaId))],
            'contas_receber' => ['previstos' => count($this->distinctStagingRows('stg_conta_azul_financeiro', $lojaId))],
            'contas_pagar' => ['previstos' => count($this->distinctStagingRows('stg_conta_azul_contas_pagar', $lojaId))],
            'baixas_pagamentos' => ['previstos' => count($this->distinctStagingRows('stg_conta_azul_baixas', $lojaId))],
        ];
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function oficializar(?int $lojaId = null): array
    {
        return DB::transaction(function () use ($lojaId) {
            $resumo = [];
            $resumo['contas_financeiras'] = $this->oficializarContasFinanceiras($lojaId);
            $resumo['saldos_contas_financeiras'] = $this->oficializarSaldosContasFinanceiras($lojaId);
            $resumo['formas_pagamento'] = $this->oficializarFormasPagamento($lojaId);
            $resumo['categorias_financeiras'] = $this->oficializarCategoriasFinanceiras($lojaId);
            $resumo['centros_custo'] = $this->oficializarCentrosCusto($lojaId);
            $resumo['notas_fiscais'] = $this->oficializarNotasFiscais($lojaId);
            $resumo['contas_receber'] = $this->oficializarContasReceber($lojaId);
            $resumo['contas_pagar'] = $this->oficializarContasPagar($lojaId);
            $resumo['baixas_pagamentos'] = $this->oficializarBaixas($lojaId);

            return $resumo;
        });
    }

    /**
     * @return array<string, int>
     */
    private function oficializarContasFinanceiras(?int $lojaId): array
    {
        $res = $this->emptyResult();

        foreach ($this->distinctStagingRows('stg_conta_azul_contas_financeiras', $lojaId) as $row) {
            $p = $row['payload'];
            $idExterno = (string) $row['identificador_externo'];
            $attrs = [
                'nome' => $this->limit($this->str($p, 'nome', $idExterno), 120),
                'slug' => $this->slugFor('ca-conta', $this->str($p, 'nome', 'conta'), $idExterno, 140),
                'tipo' => $this->tipoContaFinanceira($this->str($p, 'tipo', 'OUTROS')),
                'banco_nome' => $this->limit($this->str($p, 'banco'), 120),
                'banco_codigo' => $this->limit((string) data_get($p, 'codigo_banco', ''), 10),
                'agencia' => $this->limit($this->str($p, 'agencia'), 20),
                'conta' => $this->limit($this->str($p, 'numero'), 30),
                'moeda' => 'BRL',
                'ativo' => $this->bool($p, 'ativo', true),
                'padrao' => $this->bool($p, 'conta_padrao', false),
                'saldo_inicial' => 0,
                'observacoes' => 'Criada localmente a partir da Conta Azul.',
                'meta_json' => $this->json(['conta_azul' => $p]),
            ];

            $created = $this->upsertMapped(ContaAzulEntityType::CONTA_FINANCEIRA, $idExterno, $lojaId, 'contas_financeiras', $attrs, ['tabela' => 'contas_financeiras']);
            $res[$created ? 'criados' : 'atualizados']++;
        }

        return $res;
    }

    /**
     * @return array<string, int>
     */
    private function oficializarSaldosContasFinanceiras(?int $lojaId): array
    {
        $res = $this->emptyResult();

        foreach ($this->distinctStagingRows('stg_conta_azul_saldos_contas_financeiras', $lojaId) as $row) {
            $p = $row['payload'];
            $contaExterna = $this->str($p, 'id_conta_financeira');
            $contaId = $contaExterna !== '' ? $this->mappedId(ContaAzulEntityType::CONTA_FINANCEIRA, $contaExterna, $lojaId) : null;
            if (!$contaId) {
                $res['ignorados']++;
                continue;
            }

            DB::table('contas_financeiras')->where('id', $contaId)->update([
                'saldo_atual' => $this->money(data_get($p, 'saldo_atual', data_get($p, 'saldo', 0))),
                'saldo_atual_em' => $this->datetime($this->str($p, 'consultado_em')) ?? now(),
                'updated_at' => now(),
            ]);
            $res['atualizados']++;
        }

        return $res;
    }

    /**
     * @return array<string, int>
     */
    private function oficializarFormasPagamento(?int $lojaId): array
    {
        $res = $this->emptyResult();

        foreach ($this->formasPagamentoPrevistas($lojaId) as $codigo => $nome) {
            $attrs = [
                'nome' => $this->limit($nome, 50),
                'slug' => $this->slugFor('ca-forma', $codigo, $codigo, 60),
                'ativo' => true,
            ];

            $created = $this->upsertMapped(ContaAzulEntityType::FORMA_PAGAMENTO, $codigo, $lojaId, 'formas_pagamento', $attrs, ['tabela' => 'formas_pagamento']);
            $res[$created ? 'criados' : 'atualizados']++;
        }

        return $res;
    }

    /**
     * @return array<string, int>
     */
    private function oficializarCategoriasFinanceiras(?int $lojaId): array
    {
        $res = $this->emptyResult();

        foreach ($this->distinctStagingRows('stg_conta_azul_categorias_financeiras', $lojaId) as $row) {
            $p = $row['payload'];
            $idExterno = (string) $row['identificador_externo'];
            $attrs = [
                'nome' => $this->limit($this->str($p, 'nome', $idExterno), 120),
                'slug' => $this->slugFor('ca-cat', $this->str($p, 'nome', 'categoria'), $idExterno, 140),
                'tipo' => $this->tipoCategoria($this->str($p, 'tipo')),
                'categoria_pai_id' => null,
                'ordem' => 0,
                'ativo' => true,
                'padrao' => false,
                'meta_json' => $this->json([
                    'conta_azul_id' => $idExterno,
                    'conta_azul_categoria_pai' => data_get($p, 'categoria_pai'),
                    'conta_azul' => $p,
                ]),
            ];

            $created = $this->upsertMapped(ContaAzulEntityType::CATEGORIA_FINANCEIRA, $idExterno, $lojaId, 'categorias_financeiras', $attrs, ['tabela' => 'categorias_financeiras']);
            $res[$created ? 'criados' : 'atualizados']++;
        }

        return $res;
    }

    /**
     * @return array<string, int>
     */
    private function oficializarCentrosCusto(?int $lojaId): array
    {
        $res = $this->emptyResult();

        foreach ($this->distinctStagingRows('stg_conta_azul_centros_custo', $lojaId) as $row) {
            $p = $row['payload'];
            $idExterno = (string) $row['identificador_externo'];
            $attrs = [
                'nome' => $this->limit($this->str($p, 'nome', $idExterno), 120),
                'slug' => $this->slugFor('ca-cc', $this->str($p, 'nome', 'centro-custo'), $idExterno, 140),
                'centro_custo_pai_id' => null,
                'ordem' => 0,
                'ativo' => $this->bool($p, 'ativo', true),
                'padrao' => false,
                'meta_json' => $this->json(['conta_azul_id' => $idExterno, 'conta_azul' => $p]),
            ];

            $created = $this->upsertMapped(ContaAzulEntityType::CENTRO_CUSTO, $idExterno, $lojaId, 'centros_custo', $attrs, ['tabela' => 'centros_custo']);
            $res[$created ? 'criados' : 'atualizados']++;
        }

        return $res;
    }

    /**
     * @return array<string, int>
     */
    private function oficializarNotasFiscais(?int $lojaId): array
    {
        $res = $this->emptyResult();

        foreach ($this->distinctStagingRows('stg_conta_azul_notas', $lojaId) as $row) {
            $p = $row['payload'];
            $idExterno = (string) $row['identificador_externo'];
            $chave = $this->str($p, 'chave_acesso', $this->str($p, 'chaveAcesso', $idExterno));
            if ($chave === '') {
                $res['ignorados']++;
                continue;
            }

            $attrs = [
                'loja_id' => $lojaId,
                'chave_acesso' => $this->limit($chave, 60),
                'numero_nota' => $this->limit($this->str($p, 'numero_nota', $this->str($p, 'numeroNota')), 30),
                'status' => $this->limit($this->str($p, 'status'), 40),
                'data_emissao' => $this->datetime($this->str($p, 'data_emissao')),
                'nome_destinatario' => $this->limit($this->str($p, 'nome_destinatario'), 190),
                'documento_local_type' => null,
                'documento_local_id' => null,
                'origem' => 'conta_azul',
                'payload_json' => $this->json($p),
            ];

            $created = $this->upsertMapped(ContaAzulEntityType::NOTA, $idExterno, $lojaId, 'notas_fiscais', $attrs, ['tabela' => 'notas_fiscais']);
            $res[$created ? 'criados' : 'atualizados']++;
        }

        return $res;
    }

    /**
     * @return array<string, int>
     */
    private function oficializarContasReceber(?int $lojaId): array
    {
        $res = $this->emptyResult();
        $formasPorParcela = $this->formasPorParcela($lojaId);

        foreach ($this->distinctStagingRows('stg_conta_azul_financeiro', $lojaId) as $row) {
            $p = $row['payload'];
            $idExterno = (string) $row['identificador_externo'];
            $valor = $this->money(data_get($p, 'total', 0));
            $recebido = $this->money(data_get($p, 'pago', 0));
            $descricaoCompleta = $this->str($p, 'descricao', 'Conta Azul ' . $idExterno);
            $attrs = [
                'parcelamento_id' => null,
                'parcela_numero' => null,
                'parcelas_total' => null,
                'is_entrada' => false,
                'pedido_id' => null,
                'descricao' => $this->limit($descricaoCompleta, 180),
                'numero_documento' => $this->limit($idExterno, 80),
                'data_emissao' => $this->date($this->str($p, 'data_criacao')),
                'data_vencimento' => $this->date($this->str($p, 'data_vencimento')),
                'valor_bruto' => $valor,
                'desconto' => 0,
                'juros' => 0,
                'multa' => 0,
                'valor_liquido' => $valor,
                'valor_recebido' => $recebido,
                'saldo_aberto' => $this->money(data_get($p, 'nao_pago', max(0, $valor - $recebido))),
                'status' => $this->statusConta($this->str($p, 'status')),
                'forma_recebimento' => $this->limit($formasPorParcela[$idExterno] ?? '', 30) ?: null,
                'categoria_id' => $this->categoriaLocalId($p, $lojaId),
                'centro_custo_id' => $this->centroCustoLocalId($p, $lojaId),
                'observacoes' => $this->observacoesFinanceiro($descricaoCompleta, $p),
            ];

            $created = $this->upsertMapped(ContaAzulEntityType::TITULO, $idExterno, $lojaId, 'contas_receber', $attrs, ['tabela' => 'contas_receber']);
            $res[$created ? 'criados' : 'atualizados']++;
        }

        return $res;
    }

    /**
     * @return array<string, int>
     */
    private function oficializarContasPagar(?int $lojaId): array
    {
        $res = $this->emptyResult();
        $formasPorParcela = $this->formasPorParcela($lojaId);

        foreach ($this->distinctStagingRows('stg_conta_azul_contas_pagar', $lojaId) as $row) {
            $p = $row['payload'];
            $idExterno = (string) $row['identificador_externo'];
            $descricaoCompleta = $this->str($p, 'descricao', 'Conta Azul ' . $idExterno);
            $attrs = [
                'parcelamento_id' => null,
                'parcela_numero' => null,
                'parcelas_total' => null,
                'is_entrada' => false,
                'fornecedor_id' => null,
                'descricao' => $this->limit($descricaoCompleta, 180),
                'numero_documento' => $this->limit($idExterno, 80),
                'data_emissao' => $this->date($this->str($p, 'data_criacao')),
                'data_vencimento' => $this->date($this->str($p, 'data_vencimento')) ?? now()->toDateString(),
                'valor_bruto' => $this->money(data_get($p, 'total', 0)),
                'desconto' => 0,
                'juros' => 0,
                'multa' => 0,
                'status' => $this->statusConta($this->str($p, 'status')),
                'forma_pagamento' => $this->limit($formasPorParcela[$idExterno] ?? '', 50) ?: null,
                'categoria_id' => $this->categoriaLocalId($p, $lojaId),
                'centro_custo_id' => $this->centroCustoLocalId($p, $lojaId),
                'observacoes' => $this->observacoesFinanceiro($descricaoCompleta, $p),
            ];

            $created = $this->upsertMapped(ContaAzulEntityType::CONTA_PAGAR, $idExterno, $lojaId, 'contas_pagar', $attrs, ['tabela' => 'contas_pagar']);
            $res[$created ? 'criados' : 'atualizados']++;
        }

        return $res;
    }

    /**
     * @return array<string, int>
     */
    private function oficializarBaixas(?int $lojaId): array
    {
        $res = $this->emptyResult() + ['lancamentos' => 0];

        foreach ($this->distinctStagingRows('stg_conta_azul_baixas', $lojaId) as $row) {
            $p = $row['payload'];
            $baixaId = (string) $row['identificador_externo'];
            $parcelaId = $this->str($p, 'idParcela', $this->str($p, 'id_parcela'));
            $tipoEvento = $this->str($p, 'evento_tipo_sierra');
            $contaFinanceiraExterna = (string) data_get($p, 'conta_financeira.id', '');
            $contaFinanceiraId = $contaFinanceiraExterna !== '' ? $this->mappedId(ContaAzulEntityType::CONTA_FINANCEIRA, $contaFinanceiraExterna, $lojaId) : null;

            if ($parcelaId === '' || !$contaFinanceiraId) {
                $res['ignorados']++;
                continue;
            }

            if ($tipoEvento === ContaAzulEntityType::CONTA_PAGAR) {
                $created = $this->upsertPagamentoContaPagar($baixaId, $parcelaId, $lojaId, $p, $contaFinanceiraId);
                $res[$created ? 'criados' : 'atualizados']++;
                $res['lancamentos']++;
                continue;
            }

            $created = $this->upsertPagamentoContaReceber($baixaId, $parcelaId, $lojaId, $p, $contaFinanceiraId);
            $res[$created ? 'criados' : 'atualizados']++;
            $res['lancamentos']++;
        }

        return $res;
    }

    private function upsertPagamentoContaReceber(string $baixaId, string $parcelaId, ?int $lojaId, array $payload, int $contaFinanceiraId): bool
    {
        $contaId = $this->mappedId(ContaAzulEntityType::TITULO, $parcelaId, $lojaId);
        if (!$contaId) {
            return false;
        }

        $attrs = [
            'conta_receber_id' => $contaId,
            'data_pagamento' => $this->date($this->str($payload, 'data_pagamento')) ?? now()->toDateString(),
            'valor' => $this->valorBaixa($payload),
            'forma_pagamento' => $this->limit($this->metodoPagamento($payload), 30),
            'comprovante_path' => null,
            'observacoes' => $this->observacoesBaixa($baixaId, $payload),
            'usuario_id' => null,
            'conta_financeira_id' => $contaFinanceiraId,
        ];

        [$id, $created] = $this->upsertMappedPagamento($baixaId, $lojaId, 'contas_receber_pagamentos', $attrs);
        $pagamento = ContaReceberPagamento::query()->findOrFail($id);
        $conta = ContaReceber::query()->findOrFail($contaId);
        $this->ledger->criarLancamentoPorPagamento(
            LancamentoTipo::RECEITA->value,
            $this->limit('Recebimento Conta Azul ' . ($conta->descricao ?: $parcelaId), 255),
            (float) $attrs['valor'],
            $contaFinanceiraId,
            $conta->categoria_id,
            $conta->centro_custo_id,
            Carbon::parse($attrs['data_pagamento']),
            $conta,
            $pagamento
        );

        return $created;
    }

    private function upsertPagamentoContaPagar(string $baixaId, string $parcelaId, ?int $lojaId, array $payload, int $contaFinanceiraId): bool
    {
        $contaId = $this->mappedId(ContaAzulEntityType::CONTA_PAGAR, $parcelaId, $lojaId);
        if (!$contaId) {
            return false;
        }

        $attrs = [
            'conta_pagar_id' => $contaId,
            'data_pagamento' => $this->date($this->str($payload, 'data_pagamento')) ?? now()->toDateString(),
            'valor' => $this->valorBaixa($payload),
            'forma_pagamento' => $this->limit($this->metodoPagamento($payload), 30),
            'comprovante_path' => null,
            'observacoes' => $this->observacoesBaixa($baixaId, $payload),
            'usuario_id' => null,
            'conta_financeira_id' => $contaFinanceiraId,
        ];

        [$id, $created] = $this->upsertMappedPagamento($baixaId, $lojaId, 'contas_pagar_pagamentos', $attrs);
        $pagamento = ContaPagarPagamento::query()->findOrFail($id);
        $conta = ContaPagar::query()->findOrFail($contaId);
        $this->ledger->criarLancamentoPorPagamento(
            LancamentoTipo::DESPESA->value,
            $this->limit('Pagamento Conta Azul ' . $conta->descricao, 255),
            (float) $attrs['valor'],
            $contaFinanceiraId,
            $conta->categoria_id,
            $conta->centro_custo_id,
            Carbon::parse($attrs['data_pagamento']),
            $conta,
            $pagamento
        );

        return $created;
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array{0:int,1:bool}
     */
    private function upsertMappedPagamento(string $idExterno, ?int $lojaId, string $table, array $attrs): array
    {
        $mapping = $this->mapping(ContaAzulEntityType::BAIXA, $idExterno, $lojaId);
        if ($mapping && $mapping->id_local && ($mapping->metadata_json['tabela'] ?? null) === $table && DB::table($table)->where('id', $mapping->id_local)->exists()) {
            DB::table($table)->where('id', $mapping->id_local)->update($attrs + ['updated_at' => now()]);
            return [(int) $mapping->id_local, false];
        }

        $id = (int) DB::table($table)->insertGetId($attrs + ['created_at' => now(), 'updated_at' => now()]);
        $this->saveMapping(ContaAzulEntityType::BAIXA, $idExterno, $id, $lojaId, ['tabela' => $table]);

        return [$id, true];
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function upsertMapped(string $tipo, string $idExterno, ?int $lojaId, string $table, array $attrs, array $metadata): bool
    {
        $mapping = $this->mapping($tipo, $idExterno, $lojaId);
        if ($mapping && $mapping->id_local && DB::table($table)->where('id', $mapping->id_local)->exists()) {
            DB::table($table)->where('id', $mapping->id_local)->update($attrs + ['updated_at' => now()]);
            return false;
        }

        $id = (int) DB::table($table)->insertGetId($attrs + ['created_at' => now(), 'updated_at' => now()]);
        $this->saveMapping($tipo, $idExterno, $id, $lojaId, $metadata);

        return true;
    }

    private function saveMapping(string $tipo, string $idExterno, int $idLocal, ?int $lojaId, array $metadata): void
    {
        $query = DB::table('conta_azul_mapeamentos')
            ->where('tipo_entidade', $tipo)
            ->where('id_externo', $idExterno);
        $lojaId === null ? $query->whereNull('loja_id') : $query->where('loja_id', $lojaId);

        $attrs = [
            'loja_id' => $lojaId,
            'tipo_entidade' => $tipo,
            'id_local' => $idLocal,
            'id_externo' => $idExterno,
            'origem_inicial' => 'oficializacao_local',
            'sincronizado_em' => now(),
            'metadata_json' => $this->json($metadata + ['oficializacao_local' => true]),
            'updated_at' => now(),
        ];

        if ($query->exists()) {
            $query->update($attrs);
            return;
        }

        DB::table('conta_azul_mapeamentos')->insert($attrs + ['created_at' => now()]);
    }

    private function mappedId(string $tipo, string $idExterno, ?int $lojaId): ?int
    {
        $mapping = $this->mapping($tipo, $idExterno, $lojaId);
        return $mapping?->id_local ? (int) $mapping->id_local : null;
    }

    private function mapping(string $tipo, string $idExterno, ?int $lojaId): ?object
    {
        $query = DB::table('conta_azul_mapeamentos')
            ->where('tipo_entidade', $tipo)
            ->where('id_externo', $idExterno);
        $lojaId === null ? $query->whereNull('loja_id') : $query->where('loja_id', $lojaId);

        $row = $query->first();
        if ($row && is_string($row->metadata_json ?? null)) {
            $row->metadata_json = json_decode($row->metadata_json, true) ?: [];
        }

        return $row;
    }

    /**
     * @return array<string, string>
     */
    private function formasPagamentoPrevistas(?int $lojaId): array
    {
        $formas = [];
        foreach ($this->distinctStagingRows('stg_conta_azul_formas_pagamento', $lojaId) as $row) {
            $p = $row['payload'];
            $codigo = $this->str($p, 'codigo', (string) $row['identificador_externo']);
            if ($codigo !== '') {
                $formas[$codigo] = $this->str($p, 'nome', $this->labelFromCode($codigo));
            }
        }

        foreach ($this->distinctStagingRows('stg_conta_azul_baixas', $lojaId) as $row) {
            $codigo = $this->str($row['payload'], 'metodo_pagamento');
            if ($codigo !== '') {
                $formas[$codigo] ??= $this->labelFromCode($codigo);
            }
        }

        return $formas;
    }

    /**
     * @return array<string, string>
     */
    private function formasPorParcela(?int $lojaId): array
    {
        $formas = [];
        foreach ($this->distinctStagingRows('stg_conta_azul_baixas', $lojaId) as $row) {
            $parcelaId = $this->str($row['payload'], 'idParcela', $this->str($row['payload'], 'id_parcela'));
            if ($parcelaId !== '' && !isset($formas[$parcelaId])) {
                $formas[$parcelaId] = $this->metodoPagamento($row['payload']);
            }
        }

        return $formas;
    }

    private function categoriaLocalId(array $payload, ?int $lojaId): ?int
    {
        $categoriaId = (string) data_get($payload, 'categorias.0.id', '');
        return $categoriaId !== '' ? $this->mappedId(ContaAzulEntityType::CATEGORIA_FINANCEIRA, $categoriaId, $lojaId) : null;
    }

    private function centroCustoLocalId(array $payload, ?int $lojaId): ?int
    {
        $centroId = (string) data_get($payload, 'centros_de_custo.0.id', '');
        return $centroId !== '' ? $this->mappedId(ContaAzulEntityType::CENTRO_CUSTO, $centroId, $lojaId) : null;
    }

    /**
     * @return array<int, array{identificador_externo:string,payload:array<string,mixed>}>
     */
    private function distinctStagingRows(string $table, ?int $lojaId): array
    {
        $query = DB::table($table)->select(['id', 'identificador_externo', 'payload_json'])->orderBy('id');
        $lojaId === null ? $query->whereNull('loja_id') : $query->where('loja_id', $lojaId);

        $rows = [];
        foreach ($query->get() as $row) {
            $payload = is_string($row->payload_json) ? (json_decode($row->payload_json, true) ?: []) : (array) $row->payload_json;
            $rows[(string) $row->identificador_externo] = [
                'identificador_externo' => (string) $row->identificador_externo,
                'payload' => $payload,
            ];
        }

        return array_values($rows);
    }

    private function tipoContaFinanceira(string $tipo): string
    {
        return match (strtoupper($tipo)) {
            'CONTA_CORRENTE' => 'banco',
            'CAIXINHA' => 'caixa',
            'OUTROS' => 'outros',
            default => Str::lower($tipo ?: 'outros'),
        };
    }

    private function tipoCategoria(string $tipo): ?string
    {
        return match (strtoupper($tipo)) {
            'RECEITA' => 'receita',
            'DESPESA' => 'despesa',
            default => null,
        };
    }

    private function statusConta(string $status): string
    {
        return match (strtoupper($status)) {
            'ACQUITTED' => 'PAGA',
            'PARTIAL' => 'PARCIAL',
            'CANCELED', 'CANCELLED' => 'CANCELADA',
            default => 'ABERTA',
        };
    }

    private function valorBaixa(array $payload): float
    {
        return $this->money(data_get($payload, 'valor_composicao.valor_liquido', data_get($payload, 'valor_composicao.valor_bruto', 0)));
    }

    private function metodoPagamento(array $payload): string
    {
        return $this->str($payload, 'metodo_pagamento') ?: 'OUTRO';
    }

    private function observacoesFinanceiro(string $descricaoCompleta, array $payload): string
    {
        $obs = ['Origem: Conta Azul'];
        if (mb_strlen($descricaoCompleta) > 180) {
            $obs[] = 'Descricao completa: ' . $descricaoCompleta;
        }
        if ($cliente = data_get($payload, 'cliente.nome')) {
            $obs[] = 'Cliente Conta Azul: ' . $cliente;
        }
        if ($fornecedor = data_get($payload, 'fornecedor.nome')) {
            $obs[] = 'Fornecedor Conta Azul: ' . $fornecedor;
        }
        $obs[] = 'ID Conta Azul: ' . $this->str($payload, 'id');

        return implode("\n", $obs);
    }

    private function observacoesBaixa(string $baixaId, array $payload): string
    {
        return implode("\n", [
            'Origem: Conta Azul',
            'ID baixa Conta Azul: ' . $baixaId,
            'ID parcela Conta Azul: ' . $this->str($payload, 'idParcela', $this->str($payload, 'id_parcela')),
            'Metodo original: ' . ($this->str($payload, 'metodo_pagamento') ?: '(vazio)'),
        ]);
    }

    private function slugFor(string $prefix, string $label, string $idExterno, int $limit): string
    {
        $base = Str::slug($label) ?: $prefix;
        $suffix = substr(preg_replace('/[^a-zA-Z0-9]/', '', $idExterno), 0, 10) ?: substr(sha1($idExterno), 0, 10);
        return $this->limit($prefix . '-' . $base . '-' . $suffix, $limit);
    }

    private function labelFromCode(string $code): string
    {
        return Str::of($code)->lower()->replace('_', ' ')->title()->toString();
    }

    private function str(array $payload, string $key, string $default = ''): string
    {
        $value = data_get($payload, $key, $default);
        if ($value === null) {
            return $default;
        }

        return trim((string) $value);
    }

    private function bool(array $payload, string $key, bool $default): bool
    {
        $value = data_get($payload, $key, $default);
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    private function datetime(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return Carbon::parse($value)->toDateTimeString();
    }

    private function limit(string $value, int $limit): string
    {
        return Str::limit($value, $limit, '');
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, int>
     */
    private function emptyResult(): array
    {
        return ['criados' => 0, 'atualizados' => 0, 'ignorados' => 0];
    }
}
