<?php

namespace App\Integrations\ContaAzul\Services;

use App\Integrations\ContaAzul\ContaAzulEntityType;
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
    /**
     * @return array{conciliados:int, pendentes:int, conflitos:int}
     */
    public function conciliarPessoas(?int $lojaId = null): array
    {
        return $this->conciliarStaging(
            'stg_conta_azul_pessoas',
            ContaAzulEntityType::PESSOA,
            $lojaId,
            fn (object $row, array $payload) => $this->matchPessoa($row, $payload, $lojaId)
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
            fn (object $row, array $payload) => $this->matchProduto($row, $payload, $lojaId)
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
            fn (object $row, array $payload) => $this->matchVenda($row, $payload, $lojaId)
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
            fn (object $row, array $payload) => $this->matchTitulo($row, $payload, $lojaId)
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
            fn (object $row, array $payload) => $this->matchBaixa($row, $payload, $lojaId)
        );
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
            'baixas' => $this->conciliarBaixas($lojaId),
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
            'baixa' => 'stg_conta_azul_baixas',
            'nota' => 'stg_conta_azul_notas',
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
            $out[] = [
                'entidade' => $label,
                'pendentes' => $pend,
                'conflitos' => $conf,
            ];
        }

        return $out;
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
                DB::table($table)->where('id', $row->id)->update([
                    'status_conciliacao' => 'ignorado',
                    'observacao_conciliacao' => 'Payload inválido',
                    'updated_at' => now(),
                ]);
                $pendentes++;
                continue;
            }

            $result = $matcher($row, $payload);
            $status = $result['status'];

            if ($status === 'ignorado') {
                DB::table($table)->where('id', $row->id)->update([
                    'status_conciliacao' => 'ignorado',
                    'observacao_conciliacao' => $result['observacao'] ?? null,
                    'updated_at' => now(),
                ]);
                $pendentes++;
                continue;
            }

            if ($status === 'pendente') {
                DB::table($table)->where('id', $row->id)->update([
                    'status_conciliacao' => 'pendente',
                    'observacao_conciliacao' => $result['observacao'] ?? 'Sem correspondência',
                    'updated_at' => now(),
                ]);
                $pendentes++;
                continue;
            }

            if ($status === 'conflito') {
                DB::table($table)->where('id', $row->id)->update([
                    'status_conciliacao' => 'conflito',
                    'observacao_conciliacao' => $result['observacao'] ?? 'Conflito',
                    'updated_at' => now(),
                ]);
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
                    'metadata_json' => ['staging_id' => $row->id],
                ]
            );

            DB::table($table)->where('id', $row->id)->update([
                'status_conciliacao' => 'conciliado',
                'observacao_conciliacao' => null,
                'updated_at' => now(),
            ]);
            $conciliados++;
        }

        return compact('conciliados', 'pendentes', 'conflitos');
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
