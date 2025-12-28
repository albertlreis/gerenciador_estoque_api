<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PedidoStatusHistorico;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;
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

            'cliente.id'           => 'nullable|numeric|min:1',

            'pedido.numero_externo'=> 'nullable|string|max:50|unique:pedidos,numero_externo',
            'pedido.total'         => 'nullable|numeric',
            'pedido.observacoes'   => 'nullable|string',

            'itens'                => 'required|array|min:1',
            'itens.*.nome'         => 'required|string',
            'itens.*.quantidade'   => 'required|numeric|min:0.01',
            'itens.*.valor'        => 'required|numeric|min:0',
            'itens.*.id_categoria' => 'required|integer',
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

            $tipo = $dadosPedido['tipo'] ?? Pedido::TIPO_VENDA;

            // -------------------------------------------------------------
            // 🧍 CLIENTE (somente para venda)
            // -------------------------------------------------------------
            $clienteId = null;

            if ($tipo === Pedido::TIPO_VENDA) {
                // Se você realmente quer sempre usar o cliente selecionado, pode buscar direto pelo ID.
                // Mantive sua lógica de normalização + firstOrCreate, mas note: documento vem vazio no front hoje.
                $dadosCliente['documento'] = preg_replace('/\D/', '', $dadosCliente['documento'] ?? '');

                // Preferência: se veio id, usa direto
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

            // -------------------------------------------------------------
            // 🧾 PEDIDO
            // -------------------------------------------------------------
            $valorTotal = $dadosPedido['total']
                ?? collect($itens)->sum(fn($i) => (float)$i['quantidade'] * (float)$i['valor']);

            $pedido = Pedido::create([
                'tipo'          => $tipo,
                'id_cliente'    => $clienteId, // null na reposição
                'id_usuario'    => $usuario->id,
                'id_parceiro'   => $dadosPedido['id_parceiro'] ?? null,
                'numero_externo'=> $dadosPedido['numero_externo'] ?? null,
                'data_pedido'   => now(),
                'valor_total'   => $valorTotal,
                'observacoes'   => $dadosPedido['observacoes'] ?? null,
            ]);

            PedidoStatusHistorico::create([
                'pedido_id'   => $pedido->id,
                'status'      => PedidoStatus::PEDIDO_CRIADO,
                'data_status' => now(),
                'usuario_id'  => $usuario->id,
            ]);

            // -------------------------------------------------------------
            // 🧩 ITENS (igual ao seu fluxo atual)
            // -------------------------------------------------------------
            foreach ($itens as $item) {
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
                    'quantidade'     => $item['quantidade'],
                    'preco_unitario' => $item['valor'],
                    'subtotal'       => (float)$item['quantidade'] * (float)$item['valor'],
                    'id_deposito'    => $item['id_deposito'] ?? null,
                    'observacoes'    => $item['atributos']['observacao'] ?? null,
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

