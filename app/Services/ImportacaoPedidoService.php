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
            'pedido.total' => 'nullable|numeric',
            'pedido.observacoes' => 'nullable|string',
            'itens' => 'required|array|min:1',
            'itens.*.nome' => 'required|string',
            'itens.*.quantidade' => 'required|numeric|min:0.01',
            'itens.*.valor' => 'required|numeric|min:0',
            'itens.*.id_categoria' => 'required|integer',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return DB::transaction(function () use ($request) {

            $usuario = Auth::user();
            $dadosCliente = $request->cliente;
            $dadosPedido  = $request->pedido;
            $itens        = $request->itens;

            // ---------------------------------------------------------------------
            // 🧍 CLIENTE
            // ---------------------------------------------------------------------
            $dadosCliente['documento'] = preg_replace('/\D/', '', $dadosCliente['documento']);

            $cliente = Cliente::firstOrCreate(
                ['documento' => $dadosCliente['documento']],
                [
                    'nome' => $dadosCliente['nome'],
                    'email' => $dadosCliente['email'] ?? null,
                    'telefone' => $dadosCliente['telefone'] ?? null,
                    'endereco' => $dadosCliente['endereco'] ?? null,
                ]
            );

            // ---------------------------------------------------------------------
            // 🧾 PEDIDO
            // ---------------------------------------------------------------------
            $pedido = Pedido::create([
                'id_cliente'     => $cliente->id,
                'id_usuario'     => $usuario->id,
                'numero_externo' => $dadosPedido['numero_externo'] ?? null,
                'data_pedido'    => now(),
                'valor_total'    => $dadosPedido['total']
                    ?? collect($itens)->sum(fn($i) => $i['quantidade'] * $i['valor']),
                'observacoes'    => $dadosPedido['observacoes'] ?? null,
            ]);

            PedidoStatusHistorico::create([
                'pedido_id'  => $pedido->id,
                'status'      => PedidoStatus::PEDIDO_CRIADO,
                'data_status' => now(),
                'usuario_id'  => $usuario->id,
            ]);

            // ---------------------------------------------------------------------
            // 🧩 ITENS
            // ---------------------------------------------------------------------
            foreach ($itens as $item) {

                // 🟢 1) Tentar reaproveitar variação existente
                $variacao = null;

                if (!empty($item['id_variacao'])) {
                    $variacao = ProdutoVariacao::with('atributos')->find($item['id_variacao']);
                }

                // 🟡 2) Buscar por referência se id_variacao não veio
                if (!$variacao && !empty($item['ref'])) {
                    $variacao = ProdutoVariacao::with('atributos')
                        ->where('referencia', $item['ref'])
                        ->first();
                }

                // 🔴 3) Criar nova variação se não existir
                if (!$variacao) {

                    // criar produto base
                    $produto = Produto::firstOrCreate([
                        'nome' => $item['nome'],
                        'id_categoria' => $item['id_categoria'],
                    ]);

                    // criar variação
                    $variacao = ProdutoVariacao::create([
                        'produto_id' => $produto->id,
                        'referencia' => $item['ref'],
                        'nome'       => $item['nome'],
                        'preco'      => $item['valor'],
                        'custo'      => $item['valor'],
                    ]);

                    // salvar atributos enviados
                    foreach ($item['atributos'] as $atrib => $valor) {

                        // Ignora arrays e valores vazios
                        if (is_array($valor)) {
                            continue;
                        }

                        // Converter numéricos para string
                        if (is_numeric($valor)) {
                            $valor = (string) $valor;
                        }

                        // Ignorar null ou strings vazias
                        if ($valor === null || trim((string)$valor) === '') {
                            continue;
                        }

                        ProdutoVariacaoAtributo::updateOrCreate(
                            [
                                'id_variacao' => $variacao->id,
                                'atributo' => StringHelper::normalizarAtributo($atrib),
                            ],
                            ['valor' => trim((string)$valor)]
                        );
                    }
                }

                // ---------------------------------------------------------------------
                // 📝 Criar item do pedido
                // ---------------------------------------------------------------------
                PedidoItem::create([
                    'id_pedido'       => $pedido->id,
                    'id_variacao'     => $variacao->id,
                    'quantidade'      => $item['quantidade'],
                    'preco_unitario'  => $item['valor'],
                    'subtotal'        => $item['quantidade'] * $item['valor'],
                    'id_deposito'     => $item['id_deposito'] ?? null,
                    'observacoes'     => $item['atributos']['observacao'] ?? null,
                ]);
            }

            // ---------------------------------------------------------------------
            // 🔁 Retorno completo ao front
            // ---------------------------------------------------------------------
            $itensConfirmados = $pedido->itens()
                ->with('variacao.produto', 'variacao.atributos')
                ->get();

            return response()->json([
                'message' => 'Pedido importado e salvo com sucesso.',
                'id'      => $pedido->id,
                'itens'   => $itensConfirmados->map(function ($item) {
                    return [
                        'id_variacao'  => $item->variacao?->id,
                        'referencia'   => $item->variacao?->referencia,
                        'nome_produto' => $item->variacao?->produto?->nome,
                        'nome_completo'=> $item->variacao?->nomeCompleto,
                        'categoria_id' => $item->variacao?->produto?->id_categoria,
                    ];
                }),
            ]);
        });
    }

    /**
     * Mescla itens extraídos do PDF com itens já cadastrados.
     *
     * @param array $itens
     * @return array
     */
    public function mesclarItensComVariacoes(array $itens): array
    {
        return collect($itens)->map(function ($item) {

            $ref = $item['codigo'] ?? $item['ref'] ?? null;
            if (!$ref) return $item;

            $variacao = ProdutoVariacao::with('produto')
                ->where('referencia', $ref)
                ->first();

            if ($variacao) {
                return array_merge($item, [
                    "ref"            => $ref,
                    "nome"           => $variacao->produto->nome,
                    "produto_id"     => $variacao->produto_id,
                    "id_variacao"    => $variacao->id,
                    "variacao_nome"  => $variacao->descricao,
                    "id_categoria"   => $variacao->produto->id_categoria,
                ]);
            }

            // Produto não encontrado → usar dados do PDF
            return array_merge($item, [
                "ref"            => $ref,
                "produto_id"     => null,
                "id_variacao"    => null,
                "variacao_nome"  => null,
                "id_categoria"   => null,
            ]);
        })->toArray();
    }
}
