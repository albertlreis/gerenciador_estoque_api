<?php

namespace App\Integrations\ContaAzul\Services;

use App\Enums\ContaStatus;
use App\Enums\LancamentoTipo;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Exceptions\ContaAzulException;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\FormaPagamento;
use App\Models\Fornecedor;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Services\FinanceiroLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ContaAzulLocalCreationService
{
    public function __construct(
        private readonly ContaAzulAutoMatchService $autoMatch,
        private readonly FinanceiroLedgerService $ledger
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(string $entidade, int $stagingId, ?int $lojaId = null): array
    {
        [$tipo, $table, $row, $payload] = $this->loadStaging($entidade, $stagingId, $lojaId);

        return match ($tipo) {
            ContaAzulEntityType::PESSOA => $this->previewPessoa($row, $payload),
            ContaAzulEntityType::TITULO => $this->previewFinanceiro($tipo, $row, $payload, $lojaId),
            ContaAzulEntityType::CONTA_PAGAR => $this->previewFinanceiro($tipo, $row, $payload, $lojaId),
            default => throw new ContaAzulException('Criacao local nao suportada para esta entidade.', 'criacao_local_entidade_nao_suportada'),
        };
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function criarLocal(string $entidade, int $stagingId, ?int $lojaId, array $request): array
    {
        [$tipo, $table, $row, $payload] = $this->loadStaging($entidade, $stagingId, $lojaId);

        return DB::transaction(function () use ($tipo, $table, $row, $payload, $lojaId, $request) {
            return match ($tipo) {
                ContaAzulEntityType::PESSOA => $this->criarPessoa($table, $row, $payload, $lojaId, $request),
                ContaAzulEntityType::TITULO => $this->criarContaReceber($table, $row, $payload, $lojaId, $request),
                ContaAzulEntityType::CONTA_PAGAR => $this->criarContaPagar($table, $row, $payload, $lojaId, $request),
                default => throw new ContaAzulException('Criacao local nao suportada para esta entidade.', 'criacao_local_entidade_nao_suportada'),
            };
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $itens
     * @return array<string, mixed>
     */
    public function criarLocalLote(array $itens, ?int $lojaId): array
    {
        $itens = array_values($itens);
        if (count($itens) > 100) {
            throw ValidationException::withMessages(['itens' => 'Selecione no maximo 100 pendencias por lote.']);
        }

        $resultado = [
            'total' => count($itens),
            'criados' => [],
            'atualizados' => [],
            'vinculados' => [],
            'ignorados' => [],
            'erros' => [],
            'por_entidade' => [],
            'visibilidade_financeira' => $this->emptyFinancialVisibility(),
        ];

        foreach ($itens as $item) {
            $entidade = (string) ($item['entidade'] ?? '');
            $id = (int) ($item['id'] ?? 0);
            $base = ['entidade' => $entidade, 'id' => $id];

            if ($entidade === '' || $id <= 0) {
                $resultado['erros'][] = $base + ['mensagem' => 'Item de lote invalido.'];
                continue;
            }

            try {
                $res = $this->criarLocalPadrao($entidade, $id, $lojaId);
                $bucket = match ($res['acao'] ?? 'criado') {
                    'atualizado' => 'atualizados',
                    'vinculado' => 'vinculados',
                    'ignorado' => 'ignorados',
                    default => 'criados',
                };
                $resultado[$bucket][] = $base + $res;
                $tipo = $res['entidade'] ?? $entidade;
                $resultado['por_entidade'][$tipo][$bucket] = ($resultado['por_entidade'][$tipo][$bucket] ?? 0) + 1;
                $this->sumFinancialVisibility($resultado, $res['visibilidade_financeira'] ?? []);
            } catch (ValidationException $e) {
                $resultado['erros'][] = $base + ['mensagem' => collect($e->errors())->flatten()->first() ?: $e->getMessage()];
            } catch (\Throwable $e) {
                $resultado['erros'][] = $base + ['mensagem' => $e instanceof ContaAzulException ? $e->getMessage() : $e->getMessage()];
            }
        }

        return $resultado;
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    public function criarLocalLotePorFiltro(array $filtros, ?int $lojaId): array
    {
        $statuses = $this->normalizeStatusFilter($filtros['status'] ?? ['novo', 'pendente', 'conflito']);
        $bucket = trim((string) ($filtros['bucket'] ?? ''));
        if ($bucket === 'auto') {
            $statuses = ['conciliado'];
        } elseif ($bucket === 'sugestao' || $bucket === 'pendente') {
            $statuses = ['novo', 'pendente'];
        } elseif ($bucket === 'conflito') {
            $statuses = ['conflito'];
        }

        $entidade = trim((string) ($filtros['entidade'] ?? ''));
        $tables = $entidade !== ''
            ? [$this->normalizeEntidade($entidade) => $this->stagingTableFor($entidade)]
            : $this->stagingTables();

        $resultado = [
            'total' => 0,
            'criados' => [],
            'atualizados' => [],
            'vinculados' => [],
            'ignorados' => [],
            'erros' => [],
            'por_entidade' => [],
            'visibilidade_financeira' => $this->emptyFinancialVisibility(),
        ];

        foreach ($tables as $tipo => $table) {
            DB::table($table)
                ->select('id')
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
                ->orderBy('id')
                ->chunkById(100, function ($rows) use (&$resultado, $tipo, $lojaId) {
                    foreach ($rows as $row) {
                        $resultado['total']++;
                        $this->processarItemLote($resultado, $tipo, (int) $row->id, $lojaId);
                    }
                });
        }

        return $resultado;
    }

    /**
     * @param  array<string, mixed>  $resultado
     */
    private function processarItemLote(array &$resultado, string $entidade, int $id, ?int $lojaId): void
    {
        $base = ['entidade' => $entidade, 'id' => $id];

        try {
            $res = $this->criarLocalPadrao($entidade, $id, $lojaId);
            $bucket = match ($res['acao'] ?? 'criado') {
                'atualizado' => 'atualizados',
                'vinculado' => 'vinculados',
                'ignorado' => 'ignorados',
                default => 'criados',
            };
            $resultado[$bucket][] = $base + $res;
            $tipo = $res['entidade'] ?? $entidade;
            $resultado['por_entidade'][$tipo][$bucket] = ($resultado['por_entidade'][$tipo][$bucket] ?? 0) + 1;
            $this->sumFinancialVisibility($resultado, $res['visibilidade_financeira'] ?? []);
        } catch (ValidationException $e) {
            $resultado['erros'][] = $base + ['mensagem' => collect($e->errors())->flatten()->first() ?: $e->getMessage()];
        } catch (\Throwable $e) {
            $resultado['erros'][] = $base + ['mensagem' => $e instanceof ContaAzulException ? $e->getMessage() : $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function criarLocalPadrao(string $entidade, int $stagingId, ?int $lojaId): array
    {
        [$tipo, $table, $row, $payload] = $this->loadStaging($entidade, $stagingId, $lojaId);

        if (!in_array((string) $row->status_conciliacao, ['novo', 'pendente', 'conflito'], true)) {
            return [
                'acao' => 'ignorado',
                'entidade' => $tipo,
                'mensagem' => 'Pendencia ja resolvida.',
            ];
        }

        return DB::transaction(function () use ($tipo, $table, $row, $payload, $lojaId) {
            return match ($tipo) {
                ContaAzulEntityType::PESSOA => $this->criarPessoaPadrao($table, $row, $payload, $lojaId),
                ContaAzulEntityType::PRODUTO => $this->criarProdutoPadrao($table, $row, $payload, $lojaId),
                ContaAzulEntityType::VENDA => $this->criarVendaPadrao($table, $row, $payload, $lojaId),
                ContaAzulEntityType::TITULO => $this->criarContaReceberPadrao($table, $row, $payload, $lojaId),
                ContaAzulEntityType::CONTA_PAGAR => $this->criarContaPagarPadrao($table, $row, $payload, $lojaId),
                ContaAzulEntityType::NOTA => $this->vincularNotaReadOnly($table, $row, $payload, $lojaId),
                default => $this->processarPorAutoMatch($tipo, $table, $row, $payload, $lojaId),
            };
        });
    }

    /**
     * @return array{0:string, 1:string, 2:object, 3:array<string, mixed>}
     */
    private function loadStaging(string $entidade, int $stagingId, ?int $lojaId): array
    {
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

        $payload = json_decode((string) $row->payload_json, true);
        if (!is_array($payload)) {
            throw new ContaAzulException('Payload Conta Azul invalido.', 'payload_invalido');
        }

        return [$tipo, $table, $row, $payload];
    }

    /**
     * @return array<string, mixed>
     */
    private function previewPessoa(object $row, array $payload): array
    {
        return [
            'entidade' => ContaAzulEntityType::PESSOA,
            'tipo_local' => 'cliente',
            'tipos_locais' => ['cliente', 'fornecedor'],
            'identificador_externo' => (string) $row->identificador_externo,
            'dados' => $this->personDataFromPayload($payload),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function previewFinanceiro(string $tipo, object $row, array $payload, ?int $lojaId): array
    {
        $tipoLocal = $tipo === ContaAzulEntityType::CONTA_PAGAR ? 'conta_pagar' : 'conta_receber';
        $valorBruto = $this->money($this->first($payload, ['valor', 'valorTitulo', 'valorLiquido', 'valorParcela', 'valorTotal', 'total'])) ?? 0.0;
        $valorPago = $this->detectPaidValue($payload, $valorBruto);
        $dataPagamento = $this->date($this->first($payload, ['dataPagamento', 'dataBaixa', 'dataRecebimento', 'dataLiquidacao']))
            ?: $this->date($this->first($payload, ['dataVencimento', 'vencimento', 'data_vencimento']))
            ?: now()->format('Y-m-d');

        return [
            'entidade' => $tipo,
            'tipo_local' => $tipoLocal,
            'identificador_externo' => (string) $row->identificador_externo,
            'dados' => [
                'descricao' => $this->first($payload, ['descricao', 'nome', 'historico', 'observacao', 'titulo']) ?: 'Título Conta Azul ' . $row->identificador_externo,
                'numero_documento' => $this->first($payload, ['numero_documento', 'numeroDocumento', 'numero', 'id']),
                'data_emissao' => $this->date($this->first($payload, ['dataEmissao', 'data_emissao', 'emissao', 'dataCriacao', 'data'])),
                'data_vencimento' => $this->date($this->first($payload, ['dataVencimento', 'vencimento', 'data_vencimento'])) ?: now()->format('Y-m-d'),
                'valor_bruto' => $this->decimal($valorBruto),
                'desconto' => $this->decimal($this->money($this->first($payload, ['desconto'])) ?? 0.0),
                'juros' => $this->decimal($this->money($this->first($payload, ['juros'])) ?? 0.0),
                'multa' => $this->decimal($this->money($this->first($payload, ['multa'])) ?? 0.0),
                'categoria_id' => null,
                'centro_custo_id' => null,
                'observacoes' => 'Criado a partir da pendência Conta Azul #' . $row->id,
            ],
            'pessoa' => $this->previewPessoaFinanceira($tipoLocal, $payload, $lojaId),
            'baixa' => [
                'requerida' => $valorPago > 0,
                'valor' => $this->decimal($valorPago),
                'data_pagamento' => $dataPagamento,
                'conta_financeira_id' => '',
                'forma_pagamento' => $this->suggestPaymentMethod($payload),
                'parcial' => $valorPago > 0 && $valorPago + 0.005 < $valorBruto,
            ],
            'opcoes' => $this->options(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function previewPessoaFinanceira(string $tipoLocal, array $payload, ?int $lojaId): ?array
    {
        $externalId = $this->firstNested($payload, $tipoLocal === 'conta_pagar'
            ? ['idFornecedor', 'fornecedorId', 'idPessoa', 'pessoaId', 'fornecedor.id', 'idCliente', 'clienteId', 'cliente.id']
            : ['idCliente', 'clienteId', 'idPessoa', 'pessoaId', 'cliente.id']);

        $mapType = $tipoLocal === 'conta_pagar' ? ContaAzulEntityType::FORNECEDOR : ContaAzulEntityType::PESSOA;
        if ($externalId !== '') {
            $idLocal = ContaAzulMapeamento::query()
                ->where('tipo_entidade', $mapType)
                ->where('id_externo', $externalId)
                ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
                ->value('id_local');

            if ($idLocal) {
                return [
                    'modo' => 'usar_existente',
                    'tipo_local' => $tipoLocal === 'conta_pagar' ? 'fornecedor' : 'cliente',
                    'id_local' => (int) $idLocal,
                    'identificador_externo' => $externalId,
                    'label' => $tipoLocal === 'conta_pagar'
                        ? (Fornecedor::query()->whereKey($idLocal)->value('nome') ?: 'Fornecedor #' . $idLocal)
                        : (Cliente::query()->whereKey($idLocal)->value('nome') ?: 'Cliente #' . $idLocal),
                ];
            }
        }

        $personPayload = $this->nestedPersonPayload($payload);
        if ($personPayload === [] && $externalId === '') {
            return null;
        }

        return [
            'modo' => 'criar',
            'tipo_local' => $tipoLocal === 'conta_pagar' ? 'fornecedor' : 'cliente',
            'identificador_externo' => $externalId ?: null,
            'dados' => $this->personDataFromPayload($personPayload ?: $payload),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function criarPessoa(string $table, object $row, array $payload, ?int $lojaId, array $request): array
    {
        $tipoLocal = strtolower((string) ($request['tipo_local'] ?? 'cliente'));
        $dados = (array) ($request['dados'] ?? []);

        if ($tipoLocal === 'fornecedor') {
            $fornecedor = $this->createFornecedor($dados);
            $this->saveMapping(ContaAzulEntityType::FORNECEDOR, (int) $fornecedor->id, (string) $row->identificador_externo, $lojaId, $row, 'manual_criacao');
            $this->markStagingCreated($table, $row, (int) $fornecedor->id, 'Fornecedor ' . $fornecedor->nome);

            return ['status' => 'conciliado', 'tipo_local' => 'fornecedor', 'id_local' => (int) $fornecedor->id];
        }

        if ($tipoLocal !== 'cliente') {
            throw ValidationException::withMessages(['tipo_local' => 'Tipo local inválido.']);
        }

        $cliente = $this->createCliente($dados);
        $this->saveMapping(ContaAzulEntityType::PESSOA, (int) $cliente->id, (string) $row->identificador_externo, $lojaId, $row, 'manual_criacao');
        $this->markStagingCreated($table, $row, (int) $cliente->id, 'Cliente ' . $cliente->nome);

        return ['status' => 'conciliado', 'tipo_local' => 'cliente', 'id_local' => (int) $cliente->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function criarContaReceber(string $table, object $row, array $payload, ?int $lojaId, array $request): array
    {
        $dados = $this->validateFinancialData((array) ($request['dados'] ?? []));
        $pessoa = $this->resolvePessoaFinanceira((array) ($request['pessoa'] ?? []), ContaAzulEntityType::PESSOA, $lojaId);
        if ($pessoa) {
            $dados['cliente_id'] = $pessoa['id_local'];
        }
        if ($pessoa && empty($dados['observacoes'])) {
            $dados['observacoes'] = 'Cliente Conta Azul: ' . $pessoa['label'];
        }

        $dados = $this->withReceivableTotals($dados);
        $conta = ContaReceber::create($dados);
        $lancamentoId = null;

        $baixaInput = (array) ($request['baixa'] ?? []);
        $expectedPaid = $this->detectPaidValue($payload, (float) $dados['valor_liquido']);
        if ($expectedPaid > 0 && empty($baixaInput['valor'])) {
            $baixaInput['valor'] = $this->decimal($expectedPaid);
        }
        $baixa = $this->validateBaixa($baixaInput, (float) $dados['valor_liquido']);
        if ($baixa) {
            $pagamento = ContaReceberPagamento::create([
                'conta_receber_id' => $conta->id,
                'data_pagamento' => $baixa['data_pagamento'],
                'valor' => $baixa['valor'],
                'forma_pagamento' => $baixa['forma_pagamento'],
                'observacoes' => 'Baixa criada a partir da Conta Azul',
                'usuario_id' => auth()->id(),
                'conta_financeira_id' => $baixa['conta_financeira_id'],
            ]);
            $this->syncReceberStatus($conta->fresh());
            $lancamento = $this->ledger->criarLancamentoPorPagamento(
                tipo: LancamentoTipo::RECEITA->value,
                descricao: "Recebimento Conta a Receber #{$conta->id} - {$conta->descricao}",
                valor: (float) $pagamento->valor,
                contaFinanceiraId: (int) $pagamento->conta_financeira_id,
                categoriaId: $conta->categoria_id ? (int) $conta->categoria_id : null,
                centroCustoId: $conta->centro_custo_id ? (int) $conta->centro_custo_id : null,
                dataMovimento: $pagamento->data_pagamento,
                referencia: $conta->fresh(),
                pagamento: $pagamento,
            );
            $lancamentoId = (int) $lancamento->id;
        }

        $this->saveMapping(ContaAzulEntityType::TITULO, (int) $conta->id, (string) $row->identificador_externo, $lojaId, $row, 'manual_criacao');
        $this->markStagingCreated($table, $row, (int) $conta->id, 'Conta a receber #' . $conta->id);

        return [
            'status' => 'conciliado',
            'tipo_local' => 'conta_receber',
            'id_local' => (int) $conta->id,
            'visibilidade_financeira' => $this->financialVisibility([
                'contas_receber' => 1,
                'lancamentos_financeiros' => $lancamentoId ? 1 : 0,
            ]),
            'lancamento_financeiro_id' => $lancamentoId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function criarContaPagar(string $table, object $row, array $payload, ?int $lojaId, array $request): array
    {
        $dados = $this->validateFinancialData((array) ($request['dados'] ?? []));
        $pessoa = $this->resolvePessoaFinanceira((array) ($request['pessoa'] ?? []), ContaAzulEntityType::FORNECEDOR, $lojaId);
        if ($pessoa) {
            $dados['fornecedor_id'] = $pessoa['id_local'];
        }

        $conta = ContaPagar::create([
            'fornecedor_id' => $dados['fornecedor_id'] ?? null,
            'descricao' => $dados['descricao'],
            'numero_documento' => $dados['numero_documento'] ?? null,
            'data_emissao' => $dados['data_emissao'] ?? null,
            'data_vencimento' => $dados['data_vencimento'],
            'valor_bruto' => $dados['valor_bruto'],
            'desconto' => $dados['desconto'] ?? 0,
            'juros' => $dados['juros'] ?? 0,
            'multa' => $dados['multa'] ?? 0,
            'status' => ContaStatus::ABERTA->value,
            'forma_pagamento' => $dados['forma_pagamento'] ?? null,
            'categoria_id' => $dados['categoria_id'] ?? null,
            'centro_custo_id' => $dados['centro_custo_id'] ?? null,
            'observacoes' => $dados['observacoes'] ?? null,
        ]);

        $valorLiquido = max(0, (float) $conta->valor_liquido);
        $lancamentoId = null;
        $baixaInput = (array) ($request['baixa'] ?? []);
        $expectedPaid = $this->detectPaidValue($payload, $valorLiquido);
        if ($expectedPaid > 0 && empty($baixaInput['valor'])) {
            $baixaInput['valor'] = $this->decimal($expectedPaid);
        }
        $baixa = $this->validateBaixa($baixaInput, $valorLiquido);
        if ($baixa) {
            $pagamento = ContaPagarPagamento::create([
                'conta_pagar_id' => $conta->id,
                'data_pagamento' => $baixa['data_pagamento'],
                'valor' => $baixa['valor'],
                'forma_pagamento' => $baixa['forma_pagamento'],
                'observacoes' => 'Baixa criada a partir da Conta Azul',
                'usuario_id' => auth()->id(),
                'conta_financeira_id' => $baixa['conta_financeira_id'],
            ]);
            $this->syncPagarStatus($conta->fresh());
            $lancamento = $this->ledger->criarLancamentoPorPagamento(
                tipo: LancamentoTipo::DESPESA->value,
                descricao: "Pagamento Conta a Pagar #{$conta->id} - {$conta->descricao}",
                valor: (float) $pagamento->valor,
                contaFinanceiraId: (int) $pagamento->conta_financeira_id,
                categoriaId: $conta->categoria_id ? (int) $conta->categoria_id : null,
                centroCustoId: $conta->centro_custo_id ? (int) $conta->centro_custo_id : null,
                dataMovimento: $pagamento->data_pagamento,
                referencia: $conta->fresh(),
                pagamento: $pagamento,
            );
            $lancamentoId = (int) $lancamento->id;
        }

        $this->saveMapping(ContaAzulEntityType::CONTA_PAGAR, (int) $conta->id, (string) $row->identificador_externo, $lojaId, $row, 'manual_criacao');
        $this->markStagingCreated($table, $row, (int) $conta->id, 'Conta a pagar #' . $conta->id);

        return [
            'status' => 'conciliado',
            'tipo_local' => 'conta_pagar',
            'id_local' => (int) $conta->id,
            'visibilidade_financeira' => $this->financialVisibility([
                'contas_pagar' => 1,
                'lancamentos_financeiros' => $lancamentoId ? 1 : 0,
            ]),
            'lancamento_financeiro_id' => $lancamentoId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function criarPessoaPadrao(string $table, object $row, array $payload, ?int $lojaId): array
    {
        $preview = $this->previewPessoa($row, $payload);
        $tipoLocal = $this->isSupplierPayload($payload) ? 'fornecedor' : 'cliente';

        $res = $this->criarPessoa($table, $row, $payload, $lojaId, [
            'tipo_local' => $tipoLocal,
            'dados' => $preview['dados'],
        ]);

        return $this->resultFromCreation(ContaAzulEntityType::PESSOA, $res, 'criado');
    }

    /**
     * @return array<string, mixed>
     */
    private function criarContaReceberPadrao(string $table, object $row, array $payload, ?int $lojaId): array
    {
        $preview = $this->previewFinanceiro(ContaAzulEntityType::TITULO, $row, $payload, $lojaId);
        $preview['baixa'] = $this->completeDefaultBaixa((array) ($preview['baixa'] ?? []));
        $res = $this->criarContaReceber($table, $row, $payload, $lojaId, $preview);

        return $this->resultFromCreation(ContaAzulEntityType::TITULO, $res, 'criado');
    }

    /**
     * @return array<string, mixed>
     */
    private function criarContaPagarPadrao(string $table, object $row, array $payload, ?int $lojaId): array
    {
        $preview = $this->previewFinanceiro(ContaAzulEntityType::CONTA_PAGAR, $row, $payload, $lojaId);
        $preview['baixa'] = $this->completeDefaultBaixa((array) ($preview['baixa'] ?? []));
        $res = $this->criarContaPagar($table, $row, $payload, $lojaId, $preview);

        return $this->resultFromCreation(ContaAzulEntityType::CONTA_PAGAR, $res, 'criado');
    }

    /**
     * @return array<string, mixed>
     */
    private function criarProdutoPadrao(string $table, object $row, array $payload, ?int $lojaId): array
    {
        $mapped = $this->mappedLocalId(ContaAzulEntityType::PRODUTO, (string) $row->identificador_externo, $lojaId);
        if ($mapped) {
            $this->markStagingCreated($table, $row, $mapped, 'Produto #' . $mapped);
            return ['acao' => 'vinculado', 'entidade' => ContaAzulEntityType::PRODUTO, 'id_local' => $mapped, 'tipo_local' => 'produto'];
        }

        [$produto, $variacao] = $this->createProdutoFromPayload($payload, (string) $row->identificador_externo);
        $this->saveMapping(ContaAzulEntityType::PRODUTO, (int) $produto->id, (string) $row->identificador_externo, $lojaId, $row, 'manual_criacao', $variacao?->sku_interno);
        $this->markStagingCreated($table, $row, (int) $produto->id, 'Produto ' . $produto->nome);

        return ['acao' => 'criado', 'entidade' => ContaAzulEntityType::PRODUTO, 'tipo_local' => 'produto', 'id_local' => (int) $produto->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function criarVendaPadrao(string $table, object $row, array $payload, ?int $lojaId): array
    {
        $mapped = $this->mappedLocalId(ContaAzulEntityType::VENDA, (string) $row->identificador_externo, $lojaId);
        if ($mapped) {
            $this->markStagingCreated($table, $row, $mapped, 'Pedido #' . $mapped);
            return ['acao' => 'vinculado', 'entidade' => ContaAzulEntityType::VENDA, 'id_local' => $mapped, 'tipo_local' => 'pedido'];
        }

        $clienteId = $this->resolveClienteVenda($payload, $lojaId);
        $pedido = Pedido::create([
            'tipo' => Pedido::TIPO_VENDA,
            'id_cliente' => $clienteId,
            'id_usuario' => $this->usuarioAtualOuSistemaId(),
            'numero_externo' => $this->firstNested($payload, ['numero', 'numeroVenda', 'numeroPedido', 'codigo']) ?: (string) $row->identificador_externo,
            'data_pedido' => $this->date($this->firstNested($payload, ['data', 'dataVenda', 'dataPedido', 'dataCriacao'])) ?: now()->format('Y-m-d H:i:s'),
            'valor_total' => $this->decimal($this->money($this->firstNested($payload, ['valorTotal', 'valor', 'total', 'valorLiquido'])) ?? 0.0),
            'observacoes' => 'Criado a partir da venda Conta Azul #' . $row->identificador_externo,
            'prazo_dias_uteis' => 60,
        ]);

        foreach ($this->payloadItems($payload) as $item) {
            $variacao = $this->resolveVariacaoVendaItem($item, $lojaId);
            if (!$variacao) {
                continue;
            }

            $quantidade = max(1, (int) ($this->money($this->firstNested($item, ['quantidade', 'qtde', 'qtd'])) ?? 1));
            $preco = $this->money($this->firstNested($item, ['valorUnitario', 'precoUnitario', 'preco', 'valor'])) ?? 0.0;

            PedidoItem::create([
                'id_pedido' => $pedido->id,
                'id_variacao' => $variacao->id,
                'quantidade' => $quantidade,
                'preco_unitario' => $this->decimal($preco),
                'subtotal' => $this->decimal($preco * $quantidade),
                'observacoes' => 'Criado a partir do item Conta Azul',
            ]);
        }

        $this->saveMapping(ContaAzulEntityType::VENDA, (int) $pedido->id, (string) $row->identificador_externo, $lojaId, $row, 'manual_criacao');
        $this->markStagingCreated($table, $row, (int) $pedido->id, 'Pedido #' . $pedido->id);

        return ['acao' => 'criado', 'entidade' => ContaAzulEntityType::VENDA, 'tipo_local' => 'pedido', 'id_local' => (int) $pedido->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function processarPorAutoMatch(string $tipo, string $table, object $row, array $payload, ?int $lojaId): array
    {
        $result = $this->autoMatchFor($tipo, $row, $payload, $lojaId);
        if (($result['status'] ?? null) !== 'conciliado' || empty($result['id_local'])) {
            throw new ContaAzulException($result['observacao'] ?? 'Nao foi possivel cadastrar este tipo automaticamente.', 'criacao_lote_sem_candidato');
        }

        $idLocal = (int) $result['id_local'];
        $acao = match ($tipo) {
            ContaAzulEntityType::SALDO_CONTA_FINANCEIRA => 'atualizado',
            ContaAzulEntityType::PARCELA => 'vinculado',
            default => 'criado',
        };
        $this->saveMapping($tipo, $idLocal, (string) $row->identificador_externo, $lojaId, $row, 'manual_criacao', $result['codigo_externo'] ?? null);
        $this->markStagingCreated($table, $row, $idLocal, $this->labelFromAutoMatch($result, $tipo, $idLocal));

        return [
            'acao' => $acao,
            'entidade' => $tipo,
            'tipo_local' => $this->localTypeFor($tipo),
            'id_local' => $idLocal,
            'visibilidade_financeira' => $this->visibilityForAutoMatch($tipo, $result),
            'lancamento_financeiro_id' => $result['lancamento_financeiro_id'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vincularNotaReadOnly(string $table, object $row, array $payload, ?int $lojaId): array
    {
        $vendaExterna = $this->firstNested($payload, ['idVenda', 'vendaId', 'id_venda', 'venda.id', 'pedido.id']);
        $pedidoId = $this->mappedLocalId(ContaAzulEntityType::VENDA, $vendaExterna, $lojaId);
        if (!$pedidoId) {
            throw new ContaAzulException('Nota fiscal sem venda/pedido local mapeado; resolva manualmente.', 'nota_sem_pedido');
        }

        $this->saveMapping(ContaAzulEntityType::NOTA, $pedidoId, (string) $row->identificador_externo, $lojaId, $row, 'manual_criacao');
        $this->markStagingCreated($table, $row, $pedidoId, 'Pedido #' . $pedidoId);

        return ['acao' => 'vinculado', 'entidade' => ContaAzulEntityType::NOTA, 'tipo_local' => 'pedido', 'id_local' => $pedidoId];
    }

    /**
     * @return array{id_local:int, label:string}|null
     */
    private function resolvePessoaFinanceira(array $input, string $mapType, ?int $lojaId): ?array
    {
        $modo = strtolower((string) ($input['modo'] ?? ''));
        if ($modo === 'usar_existente') {
            $id = (int) ($input['id_local'] ?? 0);
            if ($id <= 0) {
                throw ValidationException::withMessages(['pessoa.id_local' => 'Informe o ID local da pessoa.']);
            }

            return ['id_local' => $id, 'label' => 'Registro local #' . $id];
        }

        if ($modo !== 'criar') {
            return null;
        }

        $externalId = (string) ($input['identificador_externo'] ?? '');
        if ($mapType === ContaAzulEntityType::FORNECEDOR) {
            $fornecedor = $this->createFornecedor((array) ($input['dados'] ?? []));
            if ($externalId !== '') {
                $this->saveMapping($mapType, (int) $fornecedor->id, $externalId, $lojaId, null, 'manual_criacao');
            }

            return ['id_local' => (int) $fornecedor->id, 'label' => $fornecedor->nome];
        }

        $cliente = $this->createCliente((array) ($input['dados'] ?? []));
        if ($externalId !== '') {
            $this->saveMapping($mapType, (int) $cliente->id, $externalId, $lojaId, null, 'manual_criacao');
        }

        return ['id_local' => (int) $cliente->id, 'label' => $cliente->nome];
    }

    /**
     * @return array{0: Produto, 1: ProdutoVariacao|null}
     */
    private function createProdutoFromPayload(array $payload, string $fallbackExternalId): array
    {
        $nome = $this->firstNested($payload, ['nome', 'descricao', 'produto.nome', 'produto.descricao']);
        if ($nome === '') {
            $nome = 'Produto Conta Azul ' . $fallbackExternalId;
        }

        $codigo = $this->firstNested($payload, ['sku', 'codigo', 'codigoSKU', 'codigoServico', 'produto.codigo']);
        $categoriaNome = $this->firstNested($payload, ['categoria', 'categoriaNome', 'grupo', 'tipo']);
        $categoria = Categoria::query()->firstOrCreate([
            'nome' => $categoriaNome !== '' ? $categoriaNome : 'Conta Azul - Sem categoria',
        ]);

        $produto = Produto::create([
            'nome' => $nome,
            'descricao' => $this->firstNested($payload, ['descricaoDetalhada', 'descricao', 'observacao']) ?: null,
            'id_categoria' => $categoria->id,
            'codigo_produto' => $codigo ?: null,
            'ativo' => true,
        ]);

        $referencia = $codigo ?: $fallbackExternalId;
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => mb_substr($referencia, 0, 100),
            'nome' => $nome,
            'sku_interno' => $codigo ?: null,
            'preco' => $this->money($this->firstNested($payload, ['preco', 'valor', 'valorUnitario', 'precoVenda'])),
            'custo' => $this->money($this->firstNested($payload, ['custo', 'custoUnitario'])),
        ]);

        return [$produto, $variacao];
    }

    private function resolveClienteVenda(array $payload, ?int $lojaId): ?int
    {
        $externalId = $this->firstNested($payload, ['idCliente', 'clienteId', 'id_cliente', 'cliente.id']);
        $mapped = $this->mappedLocalId(ContaAzulEntityType::PESSOA, $externalId, $lojaId);
        if ($mapped) {
            return $mapped;
        }

        $personPayload = $this->nestedPersonPayload($payload);
        if ($personPayload === [] && isset($payload['cliente']) && is_array($payload['cliente'])) {
            $personPayload = $payload['cliente'];
        }

        if ($personPayload === [] && $externalId === '') {
            return null;
        }

        $cliente = $this->createCliente($this->personDataFromPayload($personPayload ?: $payload));
        if ($externalId !== '') {
            $this->saveMapping(ContaAzulEntityType::PESSOA, (int) $cliente->id, $externalId, $lojaId, null, 'manual_criacao');
        }

        return (int) $cliente->id;
    }

    private function resolveVariacaoVendaItem(array $item, ?int $lojaId): ?ProdutoVariacao
    {
        $produtoExternalId = $this->firstNested($item, ['idProduto', 'produtoId', 'id_produto', 'produto.id', 'id']);
        $produtoId = $this->mappedLocalId(ContaAzulEntityType::PRODUTO, $produtoExternalId, $lojaId);
        if ($produtoId) {
            $variacao = ProdutoVariacao::query()->where('produto_id', $produtoId)->orderBy('id')->first();
            if ($variacao) {
                return $variacao;
            }
        }

        [$produto, $variacao] = $this->createProdutoFromPayload($item['produto'] ?? $item, $produtoExternalId ?: uniqid('item-', false));
        if ($produtoExternalId !== '') {
            $this->saveMapping(ContaAzulEntityType::PRODUTO, (int) $produto->id, $produtoExternalId, $lojaId, null, 'manual_criacao', $variacao?->sku_interno);
        }

        return $variacao;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function payloadItems(array $payload): array
    {
        foreach (['itens', 'items', 'produtos', 'produtosServicos', 'detalhes'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_values(array_filter($payload[$key], 'is_array'));
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function autoMatchFor(string $tipo, object $row, array $payload, ?int $lojaId): array
    {
        return match ($tipo) {
            ContaAzulEntityType::PARCELA => $this->autoMatch->matchParcela($row, $payload, $lojaId),
            ContaAzulEntityType::BAIXA => $this->autoMatch->matchBaixa($row, $payload, $lojaId),
            ContaAzulEntityType::CONTA_FINANCEIRA => $this->autoMatch->matchContaFinanceira($row, $payload, $lojaId),
            ContaAzulEntityType::SALDO_CONTA_FINANCEIRA => $this->autoMatch->matchSaldoContaFinanceira($row, $payload, $lojaId),
            ContaAzulEntityType::CATEGORIA_FINANCEIRA => $this->autoMatch->matchCategoriaFinanceira($row, $payload, $lojaId),
            ContaAzulEntityType::CENTRO_CUSTO => $this->autoMatch->matchCentroCusto($row, $payload, $lojaId),
            ContaAzulEntityType::FORMA_PAGAMENTO => $this->autoMatch->matchFormaPagamento($row, $payload, $lojaId),
            default => throw new ContaAzulException('Entidade Conta Azul invalida para criacao local.', 'entidade_invalida'),
        };
    }

    /**
     * @param  array<string, mixed>  $baixa
     * @return array<string, mixed>
     */
    private function completeDefaultBaixa(array $baixa): array
    {
        if ((float) ($baixa['valor'] ?? 0) <= 0) {
            return $baixa;
        }

        if (empty($baixa['conta_financeira_id'])) {
            $baixa['conta_financeira_id'] = ContaFinanceira::query()
                ->where('ativo', true)
                ->orderByDesc('padrao')
                ->orderBy('id')
                ->value('id') ?: '';
        }
        if (empty($baixa['forma_pagamento'])) {
            $baixa['forma_pagamento'] = 'PIX';
        }

        return $baixa;
    }

    private function usuarioAtualOuSistemaId(): int
    {
        $id = (int) (auth()->id() ?? 0);
        if ($id > 0) {
            return $id;
        }

        $existing = DB::table('acesso_usuarios')->orderBy('id')->value('id');
        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('acesso_usuarios')->insertGetId([
            'nome' => 'Importacao Conta Azul',
            'email' => 'conta-azul-import-' . uniqid() . '@sierra.local',
            'senha' => bcrypt(str()->random(24)),
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mappedLocalId(string $tipo, string $idExterno, ?int $lojaId): ?int
    {
        if ($idExterno === '') {
            return null;
        }

        $id = ContaAzulMapeamento::query()
            ->where('tipo_entidade', $tipo)
            ->where('id_externo', $idExterno)
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->value('id_local');

        return $id ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $res
     * @return array<string, mixed>
     */
    private function resultFromCreation(string $entidade, array $res, string $acao): array
    {
        return [
            'acao' => $acao,
            'entidade' => $entidade,
            'tipo_local' => $res['tipo_local'] ?? null,
            'id_local' => isset($res['id_local']) ? (int) $res['id_local'] : null,
            'visibilidade_financeira' => $res['visibilidade_financeira'] ?? $this->financialVisibility([]),
            'lancamento_financeiro_id' => $res['lancamento_financeiro_id'] ?? null,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptyFinancialVisibility(): array
    {
        return [
            'contas_receber' => 0,
            'contas_pagar' => 0,
            'lancamentos_financeiros' => 0,
            'contas_financeiras' => 0,
            'categorias_financeiras' => 0,
            'centros_custo' => 0,
            'formas_pagamento' => 0,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function financialVisibility(array $counts): array
    {
        $base = $this->emptyFinancialVisibility();
        foreach ($counts as $key => $value) {
            if (array_key_exists($key, $base)) {
                $base[$key] = max(0, (int) $value);
            }
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $resultado
     * @param  array<string, mixed>  $counts
     */
    private function sumFinancialVisibility(array &$resultado, array $counts): void
    {
        if (!isset($resultado['visibilidade_financeira']) || !is_array($resultado['visibilidade_financeira'])) {
            $resultado['visibilidade_financeira'] = $this->emptyFinancialVisibility();
        }

        foreach ($this->financialVisibility($counts) as $key => $value) {
            $resultado['visibilidade_financeira'][$key] = (int) ($resultado['visibilidade_financeira'][$key] ?? 0) + $value;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, int>
     */
    private function visibilityForAutoMatch(string $tipo, array $result): array
    {
        return match ($tipo) {
            ContaAzulEntityType::BAIXA => $this->financialVisibility([
                'lancamentos_financeiros' => !empty($result['lancamento_financeiro_id']) ? 1 : 0,
            ]),
            ContaAzulEntityType::CONTA_FINANCEIRA, ContaAzulEntityType::SALDO_CONTA_FINANCEIRA => $this->financialVisibility([
                'contas_financeiras' => 1,
            ]),
            ContaAzulEntityType::CATEGORIA_FINANCEIRA => $this->financialVisibility([
                'categorias_financeiras' => 1,
            ]),
            ContaAzulEntityType::CENTRO_CUSTO => $this->financialVisibility([
                'centros_custo' => 1,
            ]),
            ContaAzulEntityType::FORMA_PAGAMENTO => $this->financialVisibility([
                'formas_pagamento' => 1,
            ]),
            default => $this->financialVisibility([]),
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function labelFromAutoMatch(array $result, string $tipo, int $idLocal): string
    {
        $candidate = $result['candidato'] ?? null;
        if (is_array($candidate) && !empty($candidate['label'])) {
            return (string) $candidate['label'];
        }

        return ucfirst(str_replace('_', ' ', $this->localTypeFor($tipo))) . ' #' . $idLocal;
    }

    private function localTypeFor(string $tipo): string
    {
        return match ($tipo) {
            ContaAzulEntityType::PARCELA => 'parcela',
            ContaAzulEntityType::BAIXA => 'pagamento',
            ContaAzulEntityType::CONTA_FINANCEIRA, ContaAzulEntityType::SALDO_CONTA_FINANCEIRA => 'conta_financeira',
            ContaAzulEntityType::CATEGORIA_FINANCEIRA => 'categoria_financeira',
            ContaAzulEntityType::CENTRO_CUSTO => 'centro_custo',
            ContaAzulEntityType::FORMA_PAGAMENTO => 'forma_pagamento',
            default => $tipo,
        };
    }

    private function isSupplierPayload(array $payload): bool
    {
        $tipo = mb_strtolower($this->firstNested($payload, ['tipo', 'perfil', 'categoria', 'papel']));
        if (preg_match('/fornecedor|supplier|vendor/', $tipo)) {
            return true;
        }

        return (bool) ($payload['fornecedor'] ?? false);
    }

    private function createCliente(array $dados): Cliente
    {
        $validated = Validator::make($dados, [
            'tipo' => ['nullable', 'in:pf,pj'],
            'nome' => ['required', 'string', 'max:255'],
            'nome_fantasia' => ['nullable', 'string', 'max:255'],
            'documento' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
        ])->validate();

        $validated['tipo'] = $validated['tipo'] ?? $this->tipoPessoaFromDocumento($validated['documento'] ?? '');

        return Cliente::create($validated);
    }

    private function createFornecedor(array $dados): Fornecedor
    {
        $validated = Validator::make($dados, [
            'nome' => ['required', 'string', 'max:255'],
            'cnpj' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'telefone' => ['nullable', 'string', 'max:30'],
            'endereco' => ['nullable', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string'],
        ])->validate();
        $validated['status'] = 1;

        return Fornecedor::create($validated);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFinancialData(array $dados): array
    {
        return Validator::make($dados, [
            'descricao' => ['required', 'string', 'max:180'],
            'numero_documento' => ['nullable', 'string', 'max:80'],
            'data_emissao' => ['nullable', 'date'],
            'data_vencimento' => ['required', 'date'],
            'valor_bruto' => ['required', 'numeric', 'min:0'],
            'desconto' => ['nullable', 'numeric', 'min:0'],
            'juros' => ['nullable', 'numeric', 'min:0'],
            'multa' => ['nullable', 'numeric', 'min:0'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias_financeiras,id'],
            'centro_custo_id' => ['nullable', 'integer', 'exists:centros_custo,id'],
            'observacoes' => ['nullable', 'string'],
        ])->validate();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function validateBaixa(array $baixa, float $valorLiquido): ?array
    {
        $valor = (float) ($baixa['valor'] ?? 0);
        if ($valor <= 0) {
            return null;
        }

        $validated = Validator::make($baixa, [
            'data_pagamento' => ['required', 'date'],
            'valor' => ['required', 'numeric', 'gt:0'],
            'forma_pagamento' => ['required', 'string', 'max:50'],
            'conta_financeira_id' => ['required', 'integer', 'exists:contas_financeiras,id'],
        ])->validate();

        if ((float) $validated['valor'] > $valorLiquido + 0.005) {
            throw ValidationException::withMessages(['baixa.valor' => 'Valor pago não pode exceder o valor líquido.']);
        }

        $forma = (string) $validated['forma_pagamento'];
        $legacy = ['PIX', 'BOLETO', 'TED', 'DINHEIRO', 'CARTAO'];
        if (!in_array(mb_strtoupper($forma), $legacy, true) && !FormaPagamento::query()->where('nome', $forma)->exists()) {
            throw ValidationException::withMessages(['baixa.forma_pagamento' => 'Forma de pagamento inválida.']);
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function withReceivableTotals(array $dados): array
    {
        $valorBruto = (float) $dados['valor_bruto'];
        $desconto = (float) ($dados['desconto'] ?? 0);
        $juros = (float) ($dados['juros'] ?? 0);
        $multa = (float) ($dados['multa'] ?? 0);
        $liquido = max(0, $valorBruto - $desconto + $juros + $multa);

        return array_merge($dados, [
            'valor_liquido' => $this->decimal($liquido),
            'valor_recebido' => '0.00',
            'saldo_aberto' => $this->decimal($liquido),
            'status' => ContaStatus::ABERTA->value,
        ]);
    }

    private function syncReceberStatus(ContaReceber $conta): void
    {
        $liquido = (float) $conta->valor_liquido;
        $recebido = (float) $conta->pagamentos()->sum('valor');
        $saldo = max(0, $liquido - $recebido);

        DB::table('contas_receber')->where('id', $conta->id)->update([
            'valor_recebido' => $this->decimal($recebido),
            'saldo_aberto' => $this->decimal($saldo),
            'status' => $recebido >= $liquido - 0.005 ? ContaStatus::PAGA->value : ContaStatus::PARCIAL->value,
            'updated_at' => now(),
        ]);
    }

    private function syncPagarStatus(ContaPagar $conta): void
    {
        $liquido = (float) $conta->valor_liquido;
        $pago = (float) $conta->pagamentos()->sum('valor');

        $conta->status = $pago >= $liquido - 0.005 ? ContaStatus::PAGA->value : ContaStatus::PARCIAL->value;
        $conta->saveQuietly();
    }

    private function saveMapping(string $tipo, int $idLocal, string $idExterno, ?int $lojaId, ?object $row, string $origin, ?string $codigoExterno = null): void
    {
        ContaAzulMapeamento::updateOrCreate(
            [
                'loja_id' => $lojaId,
                'tipo_entidade' => $tipo,
                'id_local' => $idLocal,
            ],
            [
                'id_externo' => $idExterno,
                'codigo_externo' => $codigoExterno,
                'origem_inicial' => $origin,
                'hash_payload_externo' => $row ? hash('sha256', (string) $row->payload_json) : null,
                'sincronizado_em' => now(),
                'metadata_json' => array_filter([
                    'staging_id' => $row->id ?? null,
                    'origem' => 'criacao_local_conta_azul',
                ]),
            ]
        );
    }

    private function markStagingCreated(string $table, object $row, int $idLocal, string $label): void
    {
        DB::table($table)->where('id', $row->id)->update([
            'status_conciliacao' => 'conciliado',
            'observacao_conciliacao' => 'Registro local criado a partir da Conta Azul',
            'candidato_id_local' => $idLocal,
            'candidato_score' => 100,
            'candidato_motivo' => 'Criado localmente',
            'candidato_json' => json_encode([
                'id_local' => $idLocal,
                'score' => 100,
                'motivo' => 'Criado localmente',
                'label' => $label,
            ], JSON_UNESCAPED_UNICODE),
            'conciliacao_origem' => 'manual_criacao',
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $formas = collect(['PIX', 'BOLETO', 'TED', 'DINHEIRO', 'CARTAO'])
            ->concat(FormaPagamento::query()->where('ativo', true)->orderBy('nome')->pluck('nome'))
            ->unique()
            ->values()
            ->all();

        return [
            'contas_financeiras' => ContaFinanceira::query()
                ->where('ativo', true)
                ->orderByDesc('padrao')
                ->orderBy('nome')
                ->get(['id', 'nome', 'padrao'])
                ->map(fn (ContaFinanceira $conta) => [
                    'id' => (int) $conta->id,
                    'nome' => $conta->nome,
                    'padrao' => (bool) $conta->padrao,
                ])
                ->all(),
            'formas_pagamento' => $formas,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personDataFromPayload(array $payload): array
    {
        $documento = $this->first($payload, ['cpf', 'cnpj', 'documento', 'numeroDocumento', 'cpfCnpj']);

        return [
            'tipo' => $this->tipoPessoaFromDocumento($documento),
            'nome' => $this->first($payload, ['nome', 'razaoSocial', 'nomeFantasia', 'descricao']) ?: 'Pessoa Conta Azul',
            'nome_fantasia' => $this->first($payload, ['nomeFantasia']),
            'documento' => $documento,
            'cnpj' => $documento,
            'email' => $this->first($payload, ['email', 'emailPrincipal']),
            'telefone' => $this->first($payload, ['telefone', 'celular', 'phone', 'mobile']),
            'whatsapp' => $this->first($payload, ['celular', 'whatsapp']),
            'observacoes' => 'Criado a partir da Conta Azul',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nestedPersonPayload(array $payload): array
    {
        foreach (['cliente', 'fornecedor', 'pessoa', 'customer', 'supplier'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return [];
    }

    private function detectPaidValue(array $payload, float $valorBruto): float
    {
        $valor = $this->money($this->first($payload, ['valorPago', 'valor_pago', 'valorBaixado', 'valorRecebido', 'valorPagoTotal']));
        if ($valor !== null) {
            return max(0, $valor);
        }

        $status = mb_strtolower($this->first($payload, ['status', 'situacao']));
        if ($valorBruto > 0 && preg_match('/pago|paga|liquidado|liquidada|baixado|baixada/', $status)) {
            return $valorBruto;
        }

        return 0.0;
    }

    private function suggestPaymentMethod(array $payload): string
    {
        $forma = mb_strtoupper($this->first($payload, ['formaPagamento', 'forma_pagamento', 'meioPagamento', 'metodoPagamento']));
        $legacy = ['PIX', 'BOLETO', 'TED', 'DINHEIRO', 'CARTAO'];

        return in_array($forma, $legacy, true) ? $forma : '';
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function first(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '') {
                return trim((string) $payload[$key]);
            }
        }

        return '';
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function firstNested(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->nestedValue($payload, $key);
            if ($value !== null && $value !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function nestedValue(array $payload, string $key): mixed
    {
        if (array_key_exists($key, $payload)) {
            return $payload[$key];
        }

        if (!str_contains($key, '.')) {
            return null;
        }

        $current = $payload;
        foreach (explode('.', $key) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return is_scalar($current) ? $current : null;
    }

    private function money(string $value): ?float
    {
        if (trim($value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = str_contains($value, ',')
            ? str_replace(',', '.', str_replace(['.', ' '], ['', ''], $value))
            : str_replace([' ', ','], ['', ''], $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function date(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function decimal(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function tipoPessoaFromDocumento(string $documento): string
    {
        $digits = preg_replace('/\D+/', '', $documento) ?? '';

        return strlen($digits) > 11 ? 'pj' : 'pf';
    }

    private function normalizeEntidade(string $entidade): string
    {
        return match (strtolower(trim($entidade))) {
            'pessoa', 'pessoas' => ContaAzulEntityType::PESSOA,
            'produto', 'produtos' => ContaAzulEntityType::PRODUTO,
            'venda', 'vendas' => ContaAzulEntityType::VENDA,
            'titulo', 'titulos', 'financeiro', 'conta_receber', 'contas_receber' => ContaAzulEntityType::TITULO,
            'conta_pagar', 'contas_pagar', 'contas-pagar' => ContaAzulEntityType::CONTA_PAGAR,
            'parcela', 'parcelas' => ContaAzulEntityType::PARCELA,
            'baixa', 'baixas' => ContaAzulEntityType::BAIXA,
            'nota', 'notas' => ContaAzulEntityType::NOTA,
            'conta_financeira', 'contas_financeiras', 'conta-financeira', 'contas-financeiras' => ContaAzulEntityType::CONTA_FINANCEIRA,
            'saldo_conta_financeira', 'saldos_contas_financeiras', 'saldo-conta-financeira', 'saldos-contas-financeiras' => ContaAzulEntityType::SALDO_CONTA_FINANCEIRA,
            'categoria_financeira', 'categorias_financeiras', 'categoria-financeira', 'categorias-financeiras' => ContaAzulEntityType::CATEGORIA_FINANCEIRA,
            'centro_custo', 'centros_custo', 'centro-custo', 'centros-custo', 'centro-de-custo' => ContaAzulEntityType::CENTRO_CUSTO,
            'forma_pagamento', 'formas_pagamento', 'forma-pagamento', 'formas-pagamento', 'formas-de-pagamento' => ContaAzulEntityType::FORMA_PAGAMENTO,
            default => throw new ContaAzulException('Entidade Conta Azul invalida para criacao local.', 'entidade_invalida'),
        };
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStatusFilter(mixed $statuses): array
    {
        if (is_string($statuses)) {
            $statuses = array_filter(array_map('trim', explode(',', $statuses)));
        }
        if (!is_array($statuses)) {
            $statuses = ['novo', 'pendente', 'conflito'];
        }

        $statuses = array_values(array_intersect($statuses, ['novo', 'pendente', 'conflito', 'ignorado', 'conciliado']));

        return $statuses !== [] ? $statuses : ['novo', 'pendente', 'conflito'];
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

    private function stagingTableFor(string $tipo): string
    {
        return $this->stagingTables()[$this->normalizeEntidade($tipo)] ?? throw new ContaAzulException('Entidade Conta Azul invalida para criacao local.', 'entidade_invalida');
    }
}
