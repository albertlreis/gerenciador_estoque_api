<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;
use App\Models\PedidoImportacao;
use App\Enums\PedidoStatus;
use App\Helpers\StringHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Serviço responsável pela importação de pedidos via PDF.
 */
class ImportacaoPedidoService
{
    /**
     * Confirma os dados da importação de um pedido, salvando no banco.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws ValidationException
     */
    public function confirmarImportacaoPDF(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'pedido.tipo'          => 'required|in:venda,reposicao',
            'importacao_id'        => 'nullable|integer|exists:pedido_importacoes,id',

            'cliente.id'           => 'nullable|numeric|min:1',

            'pedido.numero_externo'=> 'nullable|string|max:50|unique:pedidos,numero_externo',
            'pedido.total'         => 'nullable|numeric',
            'pedido.observacoes'   => 'nullable|string',
            'pedido.data_pedido'   => 'nullable|date',
            'pedido.data_inclusao' => 'nullable|date',
            'pedido.data_entrega'  => 'nullable|date',

            'itens'                => 'required|array|min:1',
            'itens.*.nome'         => 'required|string',
            'itens.*.quantidade'   => 'required|numeric|min:0.01',
            'itens.*.valor'        => 'required|numeric|min:0',
            'itens.*.id_categoria' => 'required|integer',
            'itens.*.id_deposito'  => 'nullable|integer|exists:depositos,id',
        ]);

        // Condicional: se for venda, cliente.id é obrigatório
        $validator->sometimes('cliente.id', 'required|numeric|min:1', function ($input) {
            return data_get($input, 'pedido.tipo') === Pedido::TIPO_VENDA;
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return DB::transaction(function () use ($request) {
            $usuario     = Auth::user();
            $dadosCliente = (array) $request->cliente;
            $dadosPedido  = (array) $request->pedido;
            $itens        = (array) $request->itens;
            $importacaoId = $request->input('importacao_id');

            $tipo = $dadosPedido['tipo'] ?? Pedido::TIPO_VENDA;

            if ($importacaoId) {
                /** @var PedidoImportacao $importacao */
                $importacao = PedidoImportacao::query()
                    ->lockForUpdate()
                    ->findOrFail((int) $importacaoId);

                if ($importacao->status === 'confirmado') {
                    return response()->json([
                        'message' => 'Esta importação já foi confirmada anteriormente.',
                        'pedido_id' => $importacao->pedido_id,
                    ], 409);
                }
            }

            $clienteId = null;

            if ($tipo === Pedido::TIPO_VENDA) {
                $dadosCliente['documento'] = preg_replace('/\D/', '', $dadosCliente['documento'] ?? '');
                $dadosCliente['nome'] = isset($dadosCliente['nome'])
                    ? trim((string) $dadosCliente['nome'])
                    : null;
                $dadosCliente['email'] = isset($dadosCliente['email'])
                    ? trim((string) $dadosCliente['email'])
                    : null;
                $dadosCliente['telefone'] = isset($dadosCliente['telefone'])
                    ? preg_replace('/\D/', '', (string) $dadosCliente['telefone'])
                    : null;

                if (!empty($dadosCliente['id'])) {
                    $cliente = Cliente::findOrFail((int) data_get($request->cliente, 'id'));
                } else {
                    $cliente = Cliente::firstOrCreate(
                        ['documento' => $dadosCliente['documento']],
                        [
                            'nome'     => $dadosCliente['nome'] ?? 'Cliente',
                            'email'    => $dadosCliente['email'] ?? null,
                            'telefone' => $dadosCliente['telefone'] ?? null,
                            'endereco' => $dadosCliente['endereco'] ?? null,
                        ]
                    );
                }

                $clienteId = $cliente->id;
            }

            $valorTotal = $dadosPedido['total']
                ?? collect($itens)->sum(fn($i) => (float)$i['quantidade'] * (float)$i['valor']);

            $numeroExterno = isset($dadosPedido['numero_externo'])
                ? trim((string) $dadosPedido['numero_externo'])
                : null;

            $dataPedido = $this->normalizarData($dadosPedido['data_pedido'] ?? null);
            $dataInclusao = $this->normalizarData($dadosPedido['data_inclusao'] ?? null);
            $dataEntrega = $this->normalizarData($dadosPedido['data_entrega'] ?? null);

            $pedido = Pedido::create([
                'tipo'          => $tipo,
                'id_cliente'    => $clienteId,
                'id_usuario'    => $usuario->id,
                'id_parceiro'   => $dadosPedido['id_parceiro'] ?? null,
                'numero_externo'=> $numeroExterno ?: null,
                'data_pedido'   => $dataPedido ?? now(),
                'valor_total'   => $valorTotal,
                'observacoes'   => $dadosPedido['observacoes'] ?? null,
            ]);

            PedidoStatusHistorico::create([
                'pedido_id'   => $pedido->id,
                'status'      => PedidoStatus::PEDIDO_CRIADO,
                'data_status' => now(),
                'usuario_id'  => $usuario->id,
            ]);

            foreach ($itens as $item) {
                $item['nome'] = trim((string) ($item['nome'] ?? ''));
                $item['ref'] = isset($item['ref']) ? trim((string) $item['ref']) : null;
                $item['id_deposito'] = $item['id_deposito'] ?? null;
                $quantidade = $this->toDecimal($item['quantidade'] ?? 0);
                $valorUnit = $this->toDecimal($item['valor'] ?? 0);

                $variacao = null;

                if (!empty($item['id_variacao'])) {
                    $variacao = ProdutoVariacao::with('atributos')->find($item['id_variacao']);
                }

                if (!$variacao && !empty($item['ref'])) {
                    $variacao = ProdutoVariacao::with('atributos')
                        ->where('referencia', $item['ref'])
                        ->first();
                }

                if (!$variacao) {
                    $produto = Produto::firstOrCreate([
                        'nome'         => $item['nome'],
                        'id_categoria' => $item['id_categoria'],
                    ]);

                    $variacao = ProdutoVariacao::create([
                        'produto_id' => $produto->id,
                        'referencia' => $item['ref'] ?? null,
                        'nome'       => $item['nome'],
                        'preco'      => $item['valor'],
                        'custo'      => $item['valor'],
                    ]);

                    foreach ($item['atributos'] ?? [] as $atrib => $valor) {
                        if (is_array($valor)) continue;
                        if (is_numeric($valor)) $valor = (string) $valor;
                        if ($valor === null || trim((string)$valor) === '') continue;

                        ProdutoVariacaoAtributo::updateOrCreate(
                            [
                                'id_variacao' => $variacao->id,
                                'atributo'    => StringHelper::normalizarAtributo($atrib),
                            ],
                            ['valor' => trim((string)$valor)]
                        );
                    }
                }

                PedidoItem::create([
                    'id_pedido'      => $pedido->id,
                    'id_variacao'    => $variacao->id,
                    'quantidade'     => $quantidade,
                    'preco_unitario' => $valorUnit,
                    'subtotal'       => (float)$quantidade * (float)$valorUnit,
                    'id_deposito'    => $item['id_deposito'] ?? null,
                    'observacoes'    => $item['atributos']['observacao'] ?? null,
                ]);
            }

            if (isset($importacao)) {
                $importacao->update([
                    'status' => 'confirmado',
                    'pedido_id' => $pedido->id,
                    'numero_externo' => $numeroExterno ?: $importacao->numero_externo,
                ]);
            }

            $itensConfirmados = $pedido->itens()
                ->with('variacao.produto', 'variacao.atributos')
                ->get();

            return response()->json([
                'message' => 'Pedido importado e salvo com sucesso.',
                'id'      => $pedido->id,
                'tipo'    => $pedido->tipo,
                'itens'   => $itensConfirmados->map(function ($item) {
                    return [
                        'id_variacao'   => $item->variacao?->id,
                        'referencia'    => $item->variacao?->referencia,
                        'nome_produto'  => $item->variacao?->produto?->nome,
                        'nome_completo' => $item->variacao?->nomeCompleto,
                        'categoria_id'  => $item->variacao?->produto?->id_categoria,
                    ];
                }),
            ]);
        });
    }

    private function normalizarData(mixed $valor): ?string
    {
        if (!$valor) return null;
        try {
            return \Carbon\Carbon::parse((string) $valor)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function toDecimal(mixed $v): float
    {
        if ($v === null || $v === '') return 0.0;
        $s = str_replace(['.', ','], ['', '.'], (string)$v);
        return is_numeric($s) ? (float)$s : 0.0;
    }

    /**
     * Mescla itens extraídos do PDF com itens já cadastrados.
     *
     * - Enriquece com nome_completo
     * - Envia atributos da variação
     * - Envia dimensões do produto (largura, profundidade, altura) em "fixos"
     *
     * @param array $itens
     * @return array
     */
    public function mesclarItensComVariacoes(array $itens): array
    {
        return collect($itens)->map(function ($item) {

            $ref = $item['codigo'] ?? $item['ref'] ?? null;
            if (!$ref) {
                return $item;
            }

            /** @var ProdutoVariacao|null $variacao */
            $variacao = ProdutoVariacao::with(['produto.categoria', 'atributos'])
                ->where('referencia', $ref)
                ->first();

            if ($variacao) {

                $produto = $variacao->produto;

                // Mapeia atributos da variação para array simples chave => valor
                $atributosVariacao = $variacao->atributos
                    ->mapWithKeys(fn($attr) => [$attr->atributo => $attr->valor])
                    ->toArray();

                // Atributos vindos do PDF (se houver) – db sobrescreve o que vier errado
                $atributosPdf = $item['atributos'] ?? [];

                $atributosFinal = array_merge($atributosPdf, $atributosVariacao);

                // Dimensões vindas do produto
                $fixosDb = [
                    'largura'      => $produto?->largura,
                    'profundidade' => $produto?->profundidade,
                    'altura'       => $produto?->altura,
                ];

                $fixosPdf = $item['fixos'] ?? [];

                $fixosFinal = array_merge(
                    $fixosPdf,
                    array_filter($fixosDb, fn($v) => !is_null($v))
                );

                return array_merge($item, [
                    "ref"           => $ref,
                    "nome"          => $produto?->nome ?? $variacao->nome,
                    "produto_id"    => $variacao->produto_id,
                    "id_variacao"   => $variacao->id,
                    "variacao_nome" => $variacao->nome,
                    "nome_completo" => $variacao->nome_completo,
                    "id_categoria"  => $produto?->id_categoria,
                    "categoria"     => $produto?->categoria?->nome,
                    "atributos"     => $atributosFinal,
                    "fixos"         => $fixosFinal,
                ]);
            }

            // Produto não encontrado → usar dados do PDF
            return array_merge($item, [
                "ref"           => $ref,
                "produto_id"    => null,
                "id_variacao"   => null,
                "variacao_nome" => null,
                "id_categoria"  => $item['id_categoria'] ?? null,
                "categoria"  => $item['categoria'] ?? null,
                "atributos"     => $item['atributos'] ?? [],
                "fixos"         => $item['fixos'] ?? [],
            ]);
        })->toArray();
    }
}

