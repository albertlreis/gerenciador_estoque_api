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
use App\Traits\ExtracaoClienteTrait;
use App\Traits\ExtracaoProdutoTrait;
use App\Services\Parsers\PedidoPDFParser;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Serviço responsável pela importação de pedidos via PDF.
 */
class ImportacaoPedidoService
{
    use ExtracaoClienteTrait, ExtracaoProdutoTrait;

    /**
     * Lê o PDF, extrai dados brutos e retorna os campos estruturados.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws ValidationException
     */
    public function importarPDF(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'arquivo' => 'required|file|mimes:pdf',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $parser = new PedidoPDFParser();
        $data = $parser->parse($request->file('arquivo'));

        Log::info('[ImportacaoPDF] Arquivo lido com sucesso.', ['nome' => $request->file('arquivo')->getClientOriginalName()]);

        return response()->json($data);
    }

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
            'cliente.nome' => 'required|string|max:255',
            'cliente.documento' => 'required|string|max:20',
            'pedido.numero_externo' => 'nullable|string|max:50|unique:pedidos,numero_externo',
            'pedido.vendedor' => 'nullable|string|max:255',
            'pedido.total' => 'nullable|numeric',
            'pedido.observacoes' => 'nullable|string',
            'itens' => 'required|array|min:1',
            'itens.*.descricao' => 'required|string',
            'itens.*.quantidade' => 'required|numeric|min:0.01',
            'itens.*.valor' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return DB::transaction(function () use ($request) {
            $usuario = Auth::user();
            $dadosCliente = $request->cliente;
            $dadosPedido = $request->pedido;
            $itens = $request->itens;

            $cliente = Cliente::firstOrCreate(
                ['documento' => $dadosCliente['documento']],
                [
                    'nome' => $dadosCliente['nome'],
                    'email' => $dadosCliente['email'] ?? null,
                    'telefone' => $dadosCliente['telefone'] ?? null,
                    'endereco' => $dadosCliente['endereco'] ?? null,
                ]
            );

            $pedido = Pedido::create([
                'id_cliente' => $cliente->id,
                'id_usuario' => $usuario->id,
                'data_pedido' => now(),
                'numero_externo' => $dadosPedido['numero_externo'] ?? null,
                'valor_total' => $dadosPedido['total'] ?? array_sum(array_map(fn($item) => $item['quantidade'] * $item['valor'], $itens)),
                'observacoes' => $dadosPedido['observacoes'] ?? null,
            ]);

            PedidoStatusHistorico::create([
                'pedido_id' => $pedido->id,
                'status' => PedidoStatus::PEDIDO_CRIADO,
                'data_status' => now(),
                'usuario_id' => $usuario->id
            ]);

            foreach ($itens as $item) {
                $variacao = ProdutoVariacao::query()
                    ->where('referencia', $item['ref'] ?? '')
                    ->when(!empty($item['nome']), fn($q) => $q->whereHas('produto', fn($q2) => $q2->where('nome', 'like', '%' . $item['nome'] . '%')))
                    ->first();

                if (!$variacao && !empty($item['ref']) && !empty($item['nome'])) {
                    if (empty($item['id_categoria'])) {
                        throw new Exception("Item '{$item['descricao']}' está sem categoria definida.");
                    }

                    $produto = Produto::firstOrCreate([
                        'nome' => $item['nome'],
                        'id_categoria' => $item['id_categoria'],
                    ]);

                    $produto->update([
                        'largura' => $item['fixos']['largura'] ?? null,
                        'profundidade' => $item['fixos']['profundidade'] ?? null,
                        'altura' => $item['fixos']['altura'] ?? null,
                    ]);

                    $variacao = ProdutoVariacao::create([
                        'produto_id' => $produto->id,
                        'referencia' => $item['ref'],
                        'nome' => $item['descricao'],
                        'preco' => $item['valor'],
                        'custo' => $item['valor'],
                    ]);

                    foreach ($item['atributos'] as $grupo => $atributosGrupo) {
                        foreach ($atributosGrupo as $atributo => $valor) {
                            if (trim($valor) === '') continue;

                            ProdutoVariacaoAtributo::updateOrCreate(
                                [
                                    'id_variacao' => $variacao->id,
                                    'atributo' => StringHelper::normalizarAtributo("$grupo:$atributo"),
                                ],
                                ['valor' => $valor]
                            );
                        }
                    }
                }

                PedidoItem::create([
                    'id_pedido' => $pedido->id,
                    'id_variacao' => $variacao?->id,
                    'descricao_manual' => $item['descricao'],
                    'quantidade' => $item['quantidade'],
                    'preco_unitario' => $item['valor'],
                    'subtotal' => $item['quantidade'] * $item['valor'],
                    'observacoes' => collect([
                        ...($item['atributos']['observacoes'] ?? []),
                        'observacao_extra' => $item['atributos']['observacoes_observacao_extra'] ?? null
                    ])->filter()->implode("n"),
                ]);
            }

            logAuditoria('pedido', "Pedido importado via PDF para cliente '$cliente->nome'.", [
                'acao' => 'importacao',
                'nivel' => 'info',
                'cliente' => $cliente->nome,
                'numero_pdf' => $request->input('pedido.numero'),
                'valor_total' => $pedido->valor_total,
                'itens' => $itens,
            ], $pedido);

            return response()->json([
                'message' => 'Pedido importado e salvo com sucesso.',
                'pedido_id' => $pedido->id,
            ]);
        });
    }
}
