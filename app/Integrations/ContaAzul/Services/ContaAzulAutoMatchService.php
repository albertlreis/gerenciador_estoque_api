<?php

namespace App\Integrations\ContaAzul\Services;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulMapeamento;
use App\Models\Cliente;
use App\Models\ContaPagar;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class ContaAzulAutoMatchService
{
    public const AUTO_SCORE = 95;
    public const SUGGESTION_SCORE = 80;

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

        $contaId = $this->localIdByExternal(ContaAzulEntityType::TITULO, $this->firstString($payload, ['idTitulo', 'tituloId', 'idParcela', 'idEvento']), $lojaId);
        $valor = $this->parseMoney($this->firstString($payload, ['valor', 'valorBaixa', 'valorPago']));
        $data = $this->parseDate($this->firstString($payload, ['data', 'dataPagamento', 'dataBaixa']));
        if ($contaId && $valor !== null) {
            $pagamentos = ContaReceberPagamento::query()
                ->where('conta_receber_id', $contaId)
                ->when($data, fn ($q) => $q->whereDate('data_pagamento', $data->format('Y-m-d')))
                ->get()
                ->filter(fn (ContaReceberPagamento $pagamento) => $this->moneyClose((float) $pagamento->valor, $valor))
                ->values();

            $candidate = $this->uniqueCandidate($pagamentos, 97, 'Título mapeado, data e valor únicos', fn (ContaReceberPagamento $pagamento) => 'Pagamento #' . $pagamento->id);
            if ($candidate['ambiguous']) {
                return $this->conflict($candidate['candidates'], 'Mais de um pagamento com mesmo título, data e valor');
            }
            if ($candidate['candidate']) {
                return $this->auto($candidate['candidate']);
            }
        }

        return ['status' => 'pendente', 'observacao' => 'Baixa sem candidato local com confiança suficiente'];
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
}
