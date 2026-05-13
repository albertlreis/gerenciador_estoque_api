<?php

namespace App\Integrations\ContaAzul\Services;

use App\Enums\ContaStatus;
use App\Enums\LancamentoTipo;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Models\CategoriaFinanceira;
use App\Models\Cliente;
use App\Models\CentroCusto;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\FormaPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Services\FinanceiroLedgerService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContaAzulAutoMatchService
{
    public const AUTO_SCORE = 95;
    public const SUGGESTION_SCORE = 80;

    private readonly FinanceiroLedgerService $ledger;

    public function __construct(?FinanceiroLedgerService $ledger = null)
    {
        $this->ledger = $ledger ?? app(FinanceiroLedgerService::class);
    }

    /**
     * @return array{status:string, id_local?:int, observacao?:string, codigo_externo?:string, candidato?:array<string, mixed>, candidatos?:array<int, array<string, mixed>>}
     */
    public function matchPessoa(object $row, array $payload, ?int $lojaId): array
    {
        $extId = (string) $row->identificador_externo;
        $mapped = $this->mappedCandidate(ContaAzulEntityType::PESSOA, $extId, $lojaId, 'Mapeamento existente', 'cliente');
        if ($mapped) {
            return $this->auto($mapped);
        }

        $doc = $this->firstString($payload, ['cpf', 'cnpj', 'documento', 'numeroDocumento', 'cpfCnpj']);
        $norm = $this->normalizeDocumento($doc);
        if ($norm !== '') {
            $matches = Cliente::query()
                ->where(function ($q) use ($doc, $norm) {
                    $q->where('documento', $doc)
                        ->orWhereRaw(
                            'REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(documento, ""), ".", ""), "/", ""), "-", ""), " ", "") = ?',
                            [$norm]
                        );
                })
                ->get();

            $candidate = $this->uniqueCandidate($matches, 100, 'Documento exato', fn (Cliente $cliente) => $cliente->nome ?: 'Cliente #' . $cliente->id);
            if ($candidate['ambiguous']) {
                return $this->conflict($candidate['candidates'], 'Mais de um cliente com o mesmo documento');
            }
            if ($candidate['candidate']) {
                return $this->auto($candidate['candidate']);
            }
        }

        $nome = $this->firstString($payload, ['nome', 'razaoSocial', 'nomeFantasia']);
        $nomeNorm = $this->normalizeNome($nome);
        $email = mb_strtolower($this->firstString($payload, ['email', 'emailPrincipal']));
        if ($email !== '' && $nomeNorm !== '') {
            $matches = Cliente::query()
                ->whereRaw('LOWER(COALESCE(email, "")) = ?', [$email])
                ->get()
                ->filter(fn (Cliente $cliente) => $this->clienteNomeMatch($cliente, $nomeNorm))
                ->values();

            $candidate = $this->uniqueCandidate($matches, 95, 'E-mail e nome equivalentes', fn (Cliente $cliente) => $cliente->nome ?: 'Cliente #' . $cliente->id);
            if ($candidate['ambiguous']) {
                return $this->conflict($candidate['candidates'], 'Mais de um cliente com mesmo e-mail e nome');
            }
            if ($candidate['candidate']) {
                return $this->auto($candidate['candidate']);
            }
        }

        $telefone = $this->normalizeDocumento($this->firstString($payload, ['telefone', 'celular', 'phone', 'mobile']));
        if ($telefone !== '' && $nomeNorm !== '') {
            $matches = Cliente::query()
                ->where(function ($q) use ($telefone) {
                    $q->whereRaw(
                        'REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefone, ""), "(", ""), ")", ""), "-", ""), " ", "") = ?',
                        [$telefone]
                    )->orWhereRaw(
                        'REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(whatsapp, ""), "(", ""), ")", ""), "-", ""), " ", "") = ?',
                        [$telefone]
                    );
                })
                ->get()
                ->filter(fn (Cliente $cliente) => $this->clienteNomeMatch($cliente, $nomeNorm))
                ->values();

            $candidate = $this->uniqueCandidate($matches, 85, 'Telefone e nome equivalentes', fn (Cliente $cliente) => $cliente->nome ?: 'Cliente #' . $cliente->id);
            if ($candidate['ambiguous']) {
                return $this->conflict($candidate['candidates'], 'Mais de um cliente com mesmo telefone e nome');
            }
            if ($candidate['candidate']) {
                return $this->suggest($candidate['candidate'], 'Cliente sugerido por telefone e nome');
            }
        }

        return ['status' => 'pendente', 'observacao' => 'Sem candidato local com confiança suficiente'];
    }

    /**
     * @return array{status:string, id_local?:int, observacao?:string, codigo_externo?:string, candidato?:array<string, mixed>, candidatos?:array<int, array<string, mixed>>}
     */
    public function matchProduto(object $row, array $payload, ?int $lojaId): array
    {
        $extId = (string) $row->identificador_externo;
        $mapped = $this->mappedCandidate(ContaAzulEntityType::PRODUTO, $extId, $lojaId, 'Mapeamento existente', 'produto');
        if ($mapped) {
            return $this->auto($mapped + ['codigo_externo' => $mapped['codigo_externo'] ?? null]);
        }

        $codigo = $this->firstString($payload, ['sku', 'codigo', 'codigoSKU', 'codigoServico']);
        if ($codigo !== '') {
            $variacoes = ProdutoVariacao::query()
                ->where('sku_interno', $codigo)
                ->with('produto')
                ->get()
                ->unique('produto_id')
                ->values();
            $candidate = $this->uniqueCandidate(
                $variacoes,
                100,
                'SKU/código exato da variação',
                fn (ProdutoVariacao $variacao) => $variacao->produto?->nome ?: 'Produto #' . $variacao->produto_id,
                fn (ProdutoVariacao $variacao) => (int) $variacao->produto_id,
                ['codigo_externo' => $codigo]
            );
            if ($candidate['ambiguous']) {
                return $this->conflict($candidate['candidates'], 'Mais de um produto com o mesmo SKU/código');
            }
            if ($candidate['candidate']) {
                return $this->auto($candidate['candidate']);
            }

            $produtos = Produto::query()->where('codigo_produto', $codigo)->get();
            $candidate = $this->uniqueCandidate($produtos, 100, 'Código exato do produto', fn (Produto $produto) => $produto->nome ?: 'Produto #' . $produto->id, null, ['codigo_externo' => $codigo]);
            if ($candidate['ambiguous']) {
                return $this->conflict($candidate['candidates'], 'Mais de um produto com o mesmo código');
            }
            if ($candidate['candidate']) {
                return $this->auto($candidate['candidate']);
            }
        }

        $nome = $this->firstString($payload, ['nome', 'descricao']);
        $norm = $this->normalizeNome($nome);
        if ($norm !== '') {
            $produtos = Produto::query()
                ->whereRaw('LOWER(nome) = ?', [$norm])
                ->orWhere('nome', $nome)
                ->get()
                ->unique('id')
                ->values();

            $candidate = $this->uniqueCandidate($produtos, 85, 'Nome exato único', fn (Produto $produto) => $produto->nome ?: 'Produto #' . $produto->id);
            if ($candidate['ambiguous']) {
                return $this->conflict($candidate['candidates'], 'Mais de um produto com nome equivalente');
            }
            if ($candidate['candidate']) {
                return $this->suggest($candidate['candidate'], 'Produto sugerido por nome exato');
            }
        }

        return ['status' => 'pendente', 'observacao' => 'Produto sem candidato local com confiança suficiente'];
    }

    public function matchVenda(object $row, array $payload, ?int $lojaId): array
    {
        $extId = (string) $row->identificador_externo;
        $mapped = $this->mappedCandidate(ContaAzulEntityType::VENDA, $extId, $lojaId, 'Mapeamento existente', 'pedido');
        if ($mapped) {
            return $this->auto($mapped);
        }

        $numero = $this->firstString($payload, ['numero', 'numeroVenda', 'numeroPedido']);
        if ($numero !== '') {
            $pedidos = Pedido::query()->where('numero_externo', $numero)->get();
            $candidate = $this->uniqueCandidate($pedidos, 100, 'Número externo exato', fn (Pedido $pedido) => 'Pedido #' . $pedido->id);
            if ($candidate['ambiguous']) {
                return $this->conflict($candidate['candidates'], 'Mais de um pedido com mesmo número externo');
            }
            if ($candidate['candidate']) {
                return $this->auto($candidate['candidate']);
            }
        }

        $clienteLocalId = $this->localIdByExternal(ContaAzulEntityType::PESSOA, $this->firstString($payload, ['idCliente', 'clienteId', 'id_cliente']), $lojaId);
        $data = $this->parseDate($this->firstString($payload, ['data', 'dataVenda', 'dataPedido', 'dataCriacao']));
        $valor = $this->parseMoney($this->firstString($payload, ['valorTotal', 'valor', 'total', 'valorLiquido']));
        if ($clienteLocalId && $data && $valor !== null) {
            $pedidos = Pedido::query()
                ->where('id_cliente', $clienteLocalId)
                ->whereDate('data_pedido', $data->format('Y-m-d'))
                ->get()
                ->filter(fn (Pedido $pedido) => $this->moneyClose((float) $pedido->valor_total, $valor))
                ->values();

            $candidate = $this->uniqueCandidate($pedidos, 96, 'Cliente mapeado, data e total únicos', fn (Pedido $pedido) => 'Pedido #' . $pedido->id);
            if ($candidate['ambiguous']) {
                return $this->conflict($candidate['candidates'], 'Mais de um pedido com mesmo cliente, data e total');
            }
            if ($candidate['candidate']) {
                return $this->auto($candidate['candidate']);
            }
        }

        return ['status' => 'pendente', 'observacao' => 'Venda sem candidato local com confiança suficiente'];
    }

    public function matchTitulo(object $row, array $payload, ?int $lojaId): array
    {
        $extId = (string) $row->identificador_externo;
        $mapped = $this->mappedCandidate(ContaAzulEntityType::TITULO, $extId, $lojaId, 'Mapeamento existente', 'conta_receber');
        if ($mapped) {
            return $this->auto($mapped);
        }

        $valor = $this->parseMoney($this->firstString($payload, ['valor', 'valorTitulo', 'valorLiquido', 'valorParcela']));
        $venc = $this->parseDate($this->firstString($payload, ['dataVencimento', 'vencimento', 'data_vencimento']));
        $pedidoId = $this->localIdByExternal(ContaAzulEntityType::VENDA, $this->firstString($payload, ['idVenda', 'vendaId', 'id_venda']), $lojaId);
        if ($pedidoId && $venc) {
            $contas = ContaReceber::query()
                ->where('pedido_id', $pedidoId)
                ->whereDate('data_vencimento', $venc->format('Y-m-d'))
                ->get()
                ->filter(fn (ContaReceber $conta) => $valor === null || $this->moneyClose((float) $conta->valor_liquido, $valor))
                ->values();

            $candidate = $this->uniqueCandidate($contas, 97, 'Venda mapeada, vencimento e valor únicos', fn (ContaReceber $conta) => 'Conta a receber #' . $conta->id);
            if ($candidate['ambiguous']) {
                return $this->conflict($candidate['candidates'], 'Mais de uma conta da venda com mesmo vencimento e valor');
            }
            if ($candidate['candidate']) {
                return $this->auto($candidate['candidate']);
            }
        }

        $clienteId = $this->localIdByExternal(ContaAzulEntityType::PESSOA, $this->firstString($payload, ['idCliente', 'clienteId']), $lojaId);
        if ($clienteId && $valor !== null && $venc) {
            $contas = ContaReceber::query()
                ->whereHas('pedido', fn ($q) => $q->where('id_cliente', $clienteId))
                ->whereDate('data_vencimento', $venc->format('Y-m-d'))
                ->get()
                ->filter(fn (ContaReceber $conta) => $this->moneyClose((float) $conta->valor_liquido, $valor))
                ->values();

            $candidate = $this->uniqueCandidate($contas, 90, 'Cliente, vencimento e valor únicos', fn (ContaReceber $conta) => 'Conta a receber #' . $conta->id);
            if ($candidate['ambiguous']) {
                return $this->conflict($candidate['candidates'], 'Mais de uma conta do cliente com mesmo vencimento e valor');
            }
            if ($candidate['candidate']) {
                return $this->suggest($candidate['candidate'], 'Título sugerido por cliente, vencimento e valor');
            }
        }

        return ['status' => 'pendente', 'observacao' => 'Título sem candidato local com confiança suficiente'];
    }

    public function matchContaPagar(object $row, array $payload, ?int $lojaId): array
    {
        $extId = (string) $row->identificador_externo;
        $mapped = $this->mappedCandidate(ContaAzulEntityType::CONTA_PAGAR, $extId, $lojaId, 'Mapeamento existente', 'conta_pagar');
        if ($mapped) {
            return $this->auto($mapped);
        }

        $valor = $this->parseMoney($this->firstString($payload, ['valor', 'valorTitulo', 'valorLiquido', 'valorParcela']));
        $venc = $this->parseDate($this->firstString($payload, ['dataVencimento', 'vencimento', 'data_vencimento']));
        $fornecedorId = $this->localIdByExternal(
            ContaAzulEntityType::FORNECEDOR,
            $this->firstString($payload, ['idFornecedor', 'fornecedorId', 'idPessoa', 'pessoaId']),
            $lojaId
        );

        if ($fornecedorId && $valor !== null && $venc) {
            $contas = ContaPagar::query()
                ->where('fornecedor_id', $fornecedorId)
                ->whereDate('data_vencimento', $venc->format('Y-m-d'))
                ->get()
                ->filter(fn (ContaPagar $conta) => $this->moneyClose((float) $conta->valor_liquido, $valor))
                ->values();

            $candidate = $this->uniqueCandidate($contas, 90, 'Fornecedor, vencimento e valor únicos', fn (ContaPagar $conta) => 'Conta a pagar #' . $conta->id);
            if ($candidate['ambiguous']) {
                return $this->conflict($candidate['candidates'], 'Mais de uma conta a pagar do fornecedor com mesmo vencimento e valor');
            }
            if ($candidate['candidate']) {
                return $this->suggest($candidate['candidate'], 'Conta a pagar sugerida por fornecedor, vencimento e valor');
            }
        }

        return ['status' => 'pendente', 'observacao' => 'Conta a pagar sem candidato local com confiança suficiente'];
    }

    public function matchBaixa(object $row, array $payload, ?int $lojaId): array
    {
        $extId = (string) $row->identificador_externo;
        $mapped = $this->mappedCandidate(ContaAzulEntityType::BAIXA, $extId, $lojaId, 'Mapeamento existente', 'pagamento');
        if ($mapped) {
            return $this->auto($mapped);
        }

        $target = $this->paymentTarget($payload, $lojaId);
        $valor = $this->parseMoney($this->firstStringNested($payload, ['valor', 'valorBaixa', 'valorPago', 'valor_pago', 'amount']));
        $data = $this->parseDate($this->firstStringNested($payload, ['data', 'dataPagamento', 'data_pagamento', 'dataBaixa', 'paidAt']));
        if (!$target || $valor === null || !$data) {
            return ['status' => 'pendente', 'observacao' => 'Baixa sem parcela/titulo, data ou valor suficientes'];
        }

        $contaFinanceiraId = $this->localIdByExternal(
            ContaAzulEntityType::CONTA_FINANCEIRA,
            $this->firstStringNested($payload, ['idContaFinanceira', 'contaFinanceiraId', 'idConta', 'id_conta_financeira', 'contaFinanceira.id', 'conta.id']),
            $lojaId
        );
        if (!$contaFinanceiraId) {
            return ['status' => 'pendente', 'observacao' => 'Baixa sem conta financeira mapeada'];
        }

        $pagamentos = $this->matchingPayments($target['tipo'], $target['id_local'], $valor, $data, $contaFinanceiraId);
        $candidate = $this->uniqueCandidate($pagamentos, 97, 'Titulo, data, valor e conta financeira unicos', fn ($pagamento) => 'Pagamento #' . $pagamento->id);
        if ($candidate['ambiguous']) {
            return $this->conflict($candidate['candidates'], 'Mais de um pagamento com mesmo titulo, data, valor e conta financeira');
        }
        if ($candidate['candidate']) {
            $pagamento = $this->paymentModel($target['tipo'], (int) $candidate['candidate']['id_local']);
            $result = $this->auto($candidate['candidate']);
            if ($pagamento) {
                $lancamento = $this->criarLancamentoPagamento($target['tipo'], $pagamento);
                $result['lancamento_financeiro_id'] = (int) $lancamento->id;
            }

            return $result;
        }

        $forma = $this->paymentMethodCode($payload) ?: 'OUTROS';
        $this->ensureFormaPagamento($forma);

        $pagamento = $this->createPayment($target['tipo'], $target['id_local'], [
            'data_pagamento' => $data->format('Y-m-d'),
            'valor' => $valor,
            'forma_pagamento' => $forma,
            'conta_financeira_id' => $contaFinanceiraId,
        ]);
        $this->syncPaymentStatus($target['tipo'], $target['id_local']);
        $lancamento = $this->criarLancamentoPagamento($target['tipo'], $pagamento);

        return $this->auto($this->candidate((int) $pagamento->id, 100, 'Criado a partir da baixa Conta Azul', 'Pagamento #' . $pagamento->id)) + [
            'lancamento_financeiro_id' => (int) $lancamento->id,
        ];
    }

    public function matchParcela(object $row, array $payload, ?int $lojaId): array
    {
        $extId = (string) $row->identificador_externo;
        $mapped = $this->mappedCandidate(ContaAzulEntityType::PARCELA, $extId, $lojaId, 'Mapeamento existente', 'parcela');
        if ($mapped) {
            return $this->auto($mapped);
        }

        $target = $this->paymentTarget($payload, $lojaId);
        if (!$target) {
            return ['status' => 'pendente', 'observacao' => 'Parcela sem evento financeiro local mapeado'];
        }

        return $this->auto($this->candidate($target['id_local'], 100, 'Evento financeiro mapeado', ucfirst($target['tipo']) . ' #' . $target['id_local']));
    }

    public function matchSaldoContaFinanceira(object $row, array $payload, ?int $lojaId): array
    {
        $contaId = $this->localIdByExternal(ContaAzulEntityType::CONTA_FINANCEIRA, (string) $row->identificador_externo, $lojaId);
        if (!$contaId) {
            return ['status' => 'pendente', 'observacao' => 'Saldo sem conta financeira local mapeada'];
        }

        $saldo = $this->parseMoney($this->firstStringNested($payload, ['saldo_atual', 'saldoAtual', 'saldo', 'valor', 'balance']));
        if ($saldo === null) {
            return ['status' => 'pendente', 'observacao' => 'Payload de saldo sem valor reconhecido'];
        }

        $conta = ContaFinanceira::query()->find($contaId);
        if (!$conta) {
            return ['status' => 'pendente', 'observacao' => 'Conta financeira local nao encontrada'];
        }

        $meta = is_array($conta->meta_json) ? $conta->meta_json : [];
        $meta['conta_azul_saldo'] = $payload;
        $conta->forceFill([
            'saldo_atual' => $saldo,
            'saldo_atual_em' => $this->parseDate($this->firstStringNested($payload, ['consultado_em', 'dataConsulta', 'updatedAt']))?->format('Y-m-d H:i:s') ?? now(),
            'meta_json' => $meta,
        ])->save();

        return $this->auto($this->candidate((int) $conta->id, 100, 'Saldo atualizado da Conta Azul', $conta->nome ?: 'Conta financeira #' . $conta->id));
    }

    public function matchFormaPagamento(object $row, array $payload, ?int $lojaId): array
    {
        $code = $this->paymentMethodCode($payload) ?: $this->normalizeCode((string) $row->identificador_externo);
        if ($code === '') {
            return ['status' => 'pendente', 'observacao' => 'Forma de pagamento sem codigo'];
        }

        $mapped = $this->mappedCandidate(ContaAzulEntityType::FORMA_PAGAMENTO, $code, $lojaId, 'Mapeamento existente', 'forma de pagamento');
        if ($mapped) {
            return $this->auto($mapped);
        }

        $forma = $this->ensureFormaPagamento($code, $this->firstStringNested($payload, ['nome', 'descricao']));

        return $this->auto($this->candidate((int) $forma->id, 100, 'Criada ou vinculada por metodo_pagamento', $forma->nome, [
            'codigo_externo' => $code,
        ]));
    }

    public function matchContaFinanceira(object $row, array $payload, ?int $lojaId): array
    {
        $extId = (string) $row->identificador_externo;
        $mapped = $this->mappedCandidate(ContaAzulEntityType::CONTA_FINANCEIRA, $extId, $lojaId, 'Mapeamento existente', 'conta financeira');
        if ($mapped) {
            return $this->auto($mapped);
        }

        $nome = $this->catalogName($payload);
        if ($nome === '') {
            return ['status' => 'pendente', 'observacao' => 'Conta financeira sem nome no payload'];
        }

        $matches = ContaFinanceira::query()
            ->whereRaw('LOWER(nome) = ?', [$this->normalizeNome($nome)])
            ->get();
        $candidate = $this->uniqueCandidate($matches, 100, 'Nome local exato', fn (ContaFinanceira $conta) => $conta->nome ?: 'Conta financeira #' . $conta->id);
        if ($candidate['ambiguous']) {
            return $this->conflict($candidate['candidates'], 'Mais de uma conta financeira local com o mesmo nome');
        }
        if ($candidate['candidate']) {
            return $this->auto($candidate['candidate']);
        }

        $conta = ContaFinanceira::create([
            'nome' => $nome,
            'slug' => $this->uniqueSlug(ContaFinanceira::class, $nome),
            'tipo' => $this->financeAccountType($payload),
            'banco_nome' => $this->firstStringNested($payload, ['banco_nome', 'bancoNome', 'nomeBanco', 'bankName', 'bank.name', 'banco.nome']),
            'banco_codigo' => $this->firstStringNested($payload, ['banco_codigo', 'bancoCodigo', 'codigoBanco', 'bankCode', 'bank.code', 'banco.codigo']),
            'agencia' => $this->firstStringNested($payload, ['agencia', 'agency', 'dadosBancarios.agencia']),
            'agencia_dv' => $this->firstStringNested($payload, ['agencia_dv', 'agenciaDv', 'agencyDigit', 'dadosBancarios.agenciaDv']),
            'conta' => $this->firstStringNested($payload, ['conta', 'numeroConta', 'accountNumber', 'dadosBancarios.conta']),
            'conta_dv' => $this->firstStringNested($payload, ['conta_dv', 'contaDv', 'accountDigit', 'dadosBancarios.contaDv']),
            'moeda' => $this->firstStringNested($payload, ['moeda', 'currency']) ?: 'BRL',
            'ativo' => $this->activeFromPayload($payload),
            'saldo_inicial' => $this->parseMoney($this->firstStringNested($payload, ['saldoInicial', 'saldo_inicial', 'saldo', 'balance'])) ?? 0,
            'meta_json' => ['conta_azul_payload' => $payload],
        ]);

        return $this->auto($this->candidate((int) $conta->id, 100, 'Criado a partir da Conta Azul', $conta->nome));
    }

    public function matchCategoriaFinanceira(object $row, array $payload, ?int $lojaId): array
    {
        $extId = (string) $row->identificador_externo;
        $mapped = $this->mappedCandidate(ContaAzulEntityType::CATEGORIA_FINANCEIRA, $extId, $lojaId, 'Mapeamento existente', 'categoria financeira');
        if ($mapped) {
            return $this->auto($mapped);
        }

        $nome = $this->catalogName($payload);
        if ($nome === '') {
            return ['status' => 'pendente', 'observacao' => 'Categoria financeira sem nome no payload'];
        }

        $tipo = $this->financeCategoryType($payload);
        $matches = CategoriaFinanceira::query()
            ->whereRaw('LOWER(nome) = ?', [$this->normalizeNome($nome)])
            ->when($tipo !== null, fn ($q) => $q->where(fn ($inner) => $inner->where('tipo', $tipo)->orWhereNull('tipo')))
            ->get();
        $candidate = $this->uniqueCandidate($matches, 100, 'Nome e tipo locais equivalentes', fn (CategoriaFinanceira $categoria) => $categoria->nome ?: 'Categoria #' . $categoria->id);
        if ($candidate['ambiguous']) {
            return $this->conflict($candidate['candidates'], 'Mais de uma categoria local com o mesmo nome');
        }
        if ($candidate['candidate']) {
            return $this->auto($candidate['candidate']);
        }

        $parentId = $this->localIdByExternal(ContaAzulEntityType::CATEGORIA_FINANCEIRA, $this->parentExternalId($payload), $lojaId);
        $categoria = CategoriaFinanceira::create([
            'nome' => $nome,
            'slug' => $this->uniqueSlug(CategoriaFinanceira::class, $nome),
            'tipo' => $tipo,
            'categoria_pai_id' => $parentId,
            'ativo' => $this->activeFromPayload($payload),
            'meta_json' => ['conta_azul_payload' => $payload],
        ]);

        return $this->auto($this->candidate((int) $categoria->id, 100, 'Criado a partir da Conta Azul', $categoria->nome));
    }

    public function matchCentroCusto(object $row, array $payload, ?int $lojaId): array
    {
        $extId = (string) $row->identificador_externo;
        $mapped = $this->mappedCandidate(ContaAzulEntityType::CENTRO_CUSTO, $extId, $lojaId, 'Mapeamento existente', 'centro de custo');
        if ($mapped) {
            return $this->auto($mapped);
        }

        $nome = $this->catalogName($payload);
        if ($nome === '') {
            return ['status' => 'pendente', 'observacao' => 'Centro de custo sem nome no payload'];
        }

        $matches = CentroCusto::query()
            ->whereRaw('LOWER(nome) = ?', [$this->normalizeNome($nome)])
            ->get();
        $candidate = $this->uniqueCandidate($matches, 100, 'Nome local exato', fn (CentroCusto $centro) => $centro->nome ?: 'Centro de custo #' . $centro->id);
        if ($candidate['ambiguous']) {
            return $this->conflict($candidate['candidates'], 'Mais de um centro de custo local com o mesmo nome');
        }
        if ($candidate['candidate']) {
            return $this->auto($candidate['candidate']);
        }

        $parentId = $this->localIdByExternal(ContaAzulEntityType::CENTRO_CUSTO, $this->parentExternalId($payload), $lojaId);
        $centro = CentroCusto::create([
            'nome' => $nome,
            'slug' => $this->uniqueSlug(CentroCusto::class, $nome),
            'centro_custo_pai_id' => $parentId,
            'ativo' => $this->activeFromPayload($payload),
            'meta_json' => ['conta_azul_payload' => $payload],
        ]);

        return $this->auto($this->candidate((int) $centro->id, 100, 'Criado a partir da Conta Azul', $centro->nome));
    }

    /**
     * @return array{tipo:string, id_local:int}|null
     */
    private function paymentTarget(array $payload, ?int $lojaId): ?array
    {
        $eventId = $this->firstStringNested($payload, [
            'id_evento',
            'evento_identificador_externo',
            'idEvento',
            'idTitulo',
            'tituloId',
            'evento.id',
        ]);
        $eventType = $this->firstStringNested($payload, ['evento_tipo_sierra', 'tipo_evento', 'tipoEvento']);

        $preferred = match ($eventType) {
            ContaAzulEntityType::CONTA_PAGAR, 'conta_pagar', 'pagar' => [ContaAzulEntityType::CONTA_PAGAR],
            ContaAzulEntityType::TITULO, 'titulo', 'receber' => [ContaAzulEntityType::TITULO],
            default => [ContaAzulEntityType::TITULO, ContaAzulEntityType::CONTA_PAGAR],
        };

        foreach ($preferred as $type) {
            $id = $this->localIdByExternal($type, $eventId, $lojaId);
            if ($id) {
                return [
                    'tipo' => $type === ContaAzulEntityType::CONTA_PAGAR ? 'pagar' : 'receber',
                    'id_local' => $id,
                ];
            }
        }

        return null;
    }

    private function matchingPayments(string $tipo, int $contaId, float $valor, \DateTimeImmutable $data, ?int $contaFinanceiraId = null)
    {
        $model = $tipo === 'pagar' ? ContaPagarPagamento::query() : ContaReceberPagamento::query();
        $column = $tipo === 'pagar' ? 'conta_pagar_id' : 'conta_receber_id';

        $query = $model
            ->where($column, $contaId)
            ->whereDate('data_pagamento', $data->format('Y-m-d'));

        if ($contaFinanceiraId !== null) {
            $query->where('conta_financeira_id', $contaFinanceiraId);
        }

        return $query->get()
            ->filter(fn ($pagamento) => $this->moneyClose((float) $pagamento->valor, $valor))
            ->values();
    }

    private function createPayment(string $tipo, int $contaId, array $data): ContaPagarPagamento|ContaReceberPagamento
    {
        $payload = [
            'data_pagamento' => $data['data_pagamento'],
            'valor' => $data['valor'],
            'forma_pagamento' => $data['forma_pagamento'],
            'conta_financeira_id' => $data['conta_financeira_id'],
            'observacoes' => 'Criado automaticamente pela baixa Conta Azul',
            'usuario_id' => auth()->id(),
        ];

        if ($tipo === 'pagar') {
            return ContaPagarPagamento::create($payload + ['conta_pagar_id' => $contaId]);
        }

        return ContaReceberPagamento::create($payload + ['conta_receber_id' => $contaId]);
    }

    private function paymentModel(string $tipo, int $pagamentoId): ContaPagarPagamento|ContaReceberPagamento|null
    {
        return $tipo === 'pagar'
            ? ContaPagarPagamento::query()->find($pagamentoId)
            : ContaReceberPagamento::query()->find($pagamentoId);
    }

    private function criarLancamentoPagamento(string $tipo, ContaPagarPagamento|ContaReceberPagamento $pagamento)
    {
        if ($tipo === 'pagar') {
            $conta = ContaPagar::query()->findOrFail($pagamento->conta_pagar_id);

            return $this->ledger->criarLancamentoPorPagamento(
                tipo: LancamentoTipo::DESPESA->value,
                descricao: "Pagamento Conta a Pagar #{$conta->id} - {$conta->descricao}",
                valor: (float) $pagamento->valor,
                contaFinanceiraId: (int) $pagamento->conta_financeira_id,
                categoriaId: $conta->categoria_id ? (int) $conta->categoria_id : null,
                centroCustoId: $conta->centro_custo_id ? (int) $conta->centro_custo_id : null,
                dataMovimento: $pagamento->data_pagamento,
                referencia: $conta,
                pagamento: $pagamento,
            );
        }

        $conta = ContaReceber::query()->findOrFail($pagamento->conta_receber_id);

        return $this->ledger->criarLancamentoPorPagamento(
            tipo: LancamentoTipo::RECEITA->value,
            descricao: "Recebimento Conta a Receber #{$conta->id} - {$conta->descricao}",
            valor: (float) $pagamento->valor,
            contaFinanceiraId: (int) $pagamento->conta_financeira_id,
            categoriaId: $conta->categoria_id ? (int) $conta->categoria_id : null,
            centroCustoId: $conta->centro_custo_id ? (int) $conta->centro_custo_id : null,
            dataMovimento: $pagamento->data_pagamento,
            referencia: $conta,
            pagamento: $pagamento,
        );
    }

    private function syncPaymentStatus(string $tipo, int $contaId): void
    {
        if ($tipo === 'pagar') {
            $conta = ContaPagar::query()->find($contaId);
            if (!$conta) {
                return;
            }
            $valorPago = (float) $conta->pagamentos()->sum('valor');
            $valorLiquido = (float) $conta->valor_liquido;
            $conta->forceFill([
                'status' => $valorPago <= 0
                    ? ContaStatus::ABERTA->value
                    : ($valorPago + 0.005 >= $valorLiquido ? ContaStatus::PAGA->value : ContaStatus::PARCIAL->value),
            ])->save();

            return;
        }

        $conta = ContaReceber::query()->find($contaId);
        if (!$conta) {
            return;
        }
        $valorRecebido = (float) $conta->pagamentos()->sum('valor');
        $valorLiquido = (float) $conta->valor_liquido;
        $saldo = max(0, $valorLiquido - $valorRecebido);
        $conta->forceFill([
            'valor_recebido' => $valorRecebido,
            'saldo_aberto' => $saldo,
            'status' => $valorRecebido <= 0
                ? ContaStatus::ABERTA->value
                : ($saldo <= 0.005 ? ContaStatus::PAGA->value : ContaStatus::PARCIAL->value),
        ])->save();
    }

    private function paymentMethodCode(array $payload): string
    {
        return $this->normalizeCode($this->firstStringNested($payload, [
            'codigo',
            'metodo_pagamento',
            'metodoPagamento',
            'forma_pagamento',
            'formaPagamento',
            'payment_method',
            'paymentMethod',
        ]));
    }

    private function ensureFormaPagamento(string $code, ?string $name = null): FormaPagamento
    {
        $code = $this->normalizeCode($code);
        $slug = Str::slug($code) ?: 'outros';
        $name = trim((string) $name) ?: $this->paymentMethodName($code);

        return FormaPagamento::query()->firstOrCreate(
            ['slug' => $slug],
            ['nome' => $name, 'ativo' => true]
        );
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

    private function normalizeCode(string $code): string
    {
        $code = Str::ascii(trim($code));
        $code = strtoupper((string) preg_replace('/[^A-Z0-9]+/', '_', $code));

        return trim($code, '_');
    }

    private function auto(array $candidate): array
    {
        return [
            'status' => 'conciliado',
            'id_local' => (int) $candidate['id_local'],
            'codigo_externo' => $candidate['codigo_externo'] ?? null,
            'observacao' => $candidate['motivo'] ?? null,
            'candidato' => $candidate,
        ];
    }

    private function suggest(array $candidate, string $observacao): array
    {
        return [
            'status' => 'pendente',
            'observacao' => $observacao,
            'candidato' => $candidate,
        ];
    }

    private function conflict(array $candidates, string $observacao): array
    {
        return [
            'status' => 'conflito',
            'observacao' => $observacao,
            'candidatos' => $candidates,
            'candidato' => $candidates[0] ?? null,
        ];
    }

    private function mappedCandidate(string $tipo, string $idExterno, ?int $lojaId, string $motivo, string $labelPrefix): ?array
    {
        if ($idExterno === '') {
            return null;
        }

        $map = ContaAzulMapeamento::query()
            ->where('tipo_entidade', $tipo)
            ->where('id_externo', $idExterno)
            ->when($lojaId !== null, fn ($q) => $q->where('loja_id', $lojaId))
            ->first();

        if (!$map || !$map->id_local) {
            return null;
        }

        return $this->candidate((int) $map->id_local, 100, $motivo, ucfirst($labelPrefix) . ' #' . $map->id_local, [
            'codigo_externo' => $map->codigo_externo,
        ]);
    }

    private function localIdByExternal(string $tipo, string $idExterno, ?int $lojaId): ?int
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
     * @param  EloquentCollection<int, mixed>|\Illuminate\Support\Collection<int, mixed>  $items
     * @return array{candidate:?array<string, mixed>, candidates:array<int, array<string, mixed>>, ambiguous:bool}
     */
    private function uniqueCandidate($items, int $score, string $motivo, callable $label, ?callable $id = null, array $extra = []): array
    {
        $candidates = $items->map(function ($item) use ($score, $motivo, $label, $id, $extra) {
            $idLocal = $id ? $id($item) : (int) $item->id;

            return $this->candidate((int) $idLocal, $score, $motivo, (string) $label($item), $extra);
        })->values()->all();

        return [
            'candidate' => count($candidates) === 1 ? $candidates[0] : null,
            'candidates' => $candidates,
            'ambiguous' => count($candidates) > 1,
        ];
    }

    private function candidate(int $idLocal, int $score, string $motivo, string $label, array $extra = []): array
    {
        return array_filter([
            'id_local' => $idLocal,
            'score' => $score,
            'motivo' => $motivo,
            'label' => $label,
            'codigo_externo' => $extra['codigo_externo'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function clienteNomeMatch(Cliente $cliente, string $nomeNorm): bool
    {
        return in_array($nomeNorm, array_filter([
            $this->normalizeNome((string) $cliente->nome),
            $this->normalizeNome((string) $cliente->nome_fantasia),
        ]), true);
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function firstString(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (!empty($payload[$key])) {
                return trim((string) $payload[$key]);
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
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($nome)));
    }

    private function parseMoney(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = str_contains($value, ',')
            ? str_replace(',', '.', str_replace(['.', ' '], ['', ''], $value))
            : str_replace([' ', ','], ['', ''], $value);

        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function moneyClose(float $a, float $b): bool
    {
        return abs($a - $b) < 0.06;
    }

    private function catalogName(array $payload): string
    {
        return $this->firstStringNested($payload, ['nome', 'descricao', 'name', 'description', 'titulo']);
    }

    private function financeAccountType(array $payload): string
    {
        $tipo = mb_strtolower($this->firstStringNested($payload, ['tipo', 'type', 'categoria', 'accountType']));

        if (str_contains($tipo, 'caixa')) {
            return 'caixa';
        }

        if (str_contains($tipo, 'pix')) {
            return 'pix';
        }

        if (str_contains($tipo, 'carteira')) {
            return 'carteira';
        }

        if (str_contains($tipo, 'invest')) {
            return 'investimento';
        }

        return 'banco';
    }

    private function financeCategoryType(array $payload): ?string
    {
        $tipo = mb_strtolower($this->firstStringNested($payload, ['tipo', 'type', 'natureza', 'operacao']));

        if ($tipo === '') {
            return null;
        }

        if (str_contains($tipo, 'receita') || str_contains($tipo, 'income') || str_contains($tipo, 'entrada') || str_contains($tipo, 'credit')) {
            return 'receita';
        }

        if (str_contains($tipo, 'despesa') || str_contains($tipo, 'expense') || str_contains($tipo, 'saida') || str_contains($tipo, 'debit')) {
            return 'despesa';
        }

        return null;
    }

    private function activeFromPayload(array $payload): bool
    {
        $raw = $this->firstScalarNested($payload, ['ativo', 'active', 'habilitado', 'status', 'situacao']);
        if ($raw === null || $raw === '') {
            return true;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        $value = mb_strtolower(trim((string) $raw));

        return !in_array($value, ['0', 'false', 'inativo', 'inactive', 'desativado', 'disabled', 'cancelado'], true);
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
     * @param  class-string<Model>  $modelClass
     */
    private function uniqueSlug(string $modelClass, string $name): string
    {
        $base = Str::slug($name) ?: 'conta-azul';
        $slug = $base;
        $suffix = 2;

        while ($modelClass::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function firstStringNested(array $payload, array $keys): string
    {
        $value = $this->firstScalarNested($payload, $keys);

        return $value === null ? '' : trim((string) $value);
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function firstScalarNested(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = $this->valueAtPath($payload, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
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
}
