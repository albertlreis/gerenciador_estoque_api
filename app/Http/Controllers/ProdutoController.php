<?php

namespace App\Http\Controllers;

use App\Services\LogService;
use Exception;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Requests\{
    FiltrarProdutosRequest,
    StoreProdutoRequest,
    UpdateProdutoRequest
};
use App\Http\Resources\ProdutoResource;
use App\Models\{
    Produto,
    ProdutoVariacaoVinculo,
    EstoqueMovimentacao
};
use App\Services\ProdutoService;

class ProdutoController extends Controller
{
    private ProdutoService $produtoService;

    /**
     * Construtor com injeção de dependência do ProdutoService.
     */
    public function __construct(ProdutoService $produtoService)
    {
        $this->produtoService = $produtoService;
    }

    /**
     * Lista produtos com filtros e paginação.
     *
     * @param FiltrarProdutosRequest $request
     * @return JsonResponse
     */
    public function index(FiltrarProdutosRequest $request): JsonResponse
    {
        $produtos = $this->produtoService->listarProdutosFiltrados($request);
        return ProdutoResource::collection($produtos)->response();
    }

    /**
     * Cadastra um novo produto no sistema.
     *
     * Observação: as variações e imagens são cadastradas separadamente
     * via endpoints específicos após o produto base ser criado.
     *
     * @param StoreProdutoRequest $request
     * @return JsonResponse
     */
    public function store(StoreProdutoRequest $request): JsonResponse
    {
        $produto = $this->produtoService->store($request->validated());

        return response()->json([
            'message' => 'Produto cadastrado com sucesso.',
            'id' => $produto->id,
        ], 201);
    }

    /**
     * Exibe os dados de um produto específico.
     *
     * @param int $id
     * @return ProdutoResource
     */
    public function show(int $id): ProdutoResource
    {
        $produto = Produto::with([
            'variacoes.atributos',
            'variacoes.estoque',
            'variacoes.outlets',
            'fornecedor',
            'imagens'
        ])->findOrFail($id);
        return new ProdutoResource($produto);
    }

    /**
     * Atualiza os dados básicos de um produto existente.
     *
     * @param UpdateProdutoRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateProdutoRequest $request, int $id): JsonResponse
    {
        $produto = Produto::findOrFail($id);
        $this->produtoService->update($produto, $request->validated());

        return response()->json([
            'message' => 'Produto atualizado com sucesso.',
            'id' => $produto->id,
        ]);
    }

    /**
     * Remove um produto e todas as suas variações e atributos associados.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $produto = Produto::with('variacoes.atributos')->findOrFail($id);

            // Verificar se há vínculos com vendas
            $variacaoIds = $produto->variacoes->pluck('id')->toArray();

            $existeEmPedidos = DB::table('pedido_itens')
                ->whereIn('id_variacao', $variacaoIds)
                ->exists();

            if ($existeEmPedidos) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Este produto não pode ser excluído pois está vinculado a um pedido.',
                ], 422);
            }

            // Excluir atributos e variações
            foreach ($produto->variacoes as $variacao) {
                $variacao->atributos->each->delete();
                $variacao->delete();
            }

            $produto->delete();
            DB::commit();

            return response()->json(['message' => 'Produto excluído com sucesso.']);

        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erro ao excluir produto.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Processa o upload do XML da NF-e e extrai os produtos e dados da nota.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function importarXML(Request $request): JsonResponse
    {
        $request->validate(['arquivo' => 'required|file|mimes:xml']);

        $xmlString = file_get_contents($request->file('arquivo')->getRealPath());
        $xml = simplexml_load_string($xmlString);
        $ns = $xml->getNamespaces(true);
        $nfe = $xml->children($ns[''])->NFe->infNFe;

        // Informações da nota
        $nota = [
            'numero' => (string) $nfe->ide->nNF,
            'data_emissao' => (string) $nfe->ide->dhEmi,
            'fornecedor_cnpj' => (string) $nfe->emit->CNPJ,
            'fornecedor_nome' => (string) $nfe->emit->xNome,
        ];

        // Produtos extraídos
        $produtos = [];
        foreach ($nfe->det as $det) {
            $produto = $det->prod;
            $descricaoXml = (string) $produto->xProd;

            $vinculo = ProdutoVariacaoVinculo::where('descricao_xml', $descricaoXml)->first();
            $variacao = $vinculo?->variacao;

            $produtos[] = [
                'descricao_xml' => $descricaoXml,
                'ncm' => (string) $produto->NCM,
                'unidade' => (string) $produto->uCom,
                'quantidade' => (float) $produto->qCom,
                'preco_unitario' => (float) $produto->vUnCom,
                'valor_total' => (float) $produto->vProd,
                'observacao' => (string) ($det->infAdProd ?? ''),
                'variacao_id' => $variacao?->id,
                'variacao_nome' => $variacao?->descricao,
            ];
        }

        return response()->json([
            'nota' => $nota,
            'produtos' => $produtos,
        ]);
    }

    /**
     * Confirma a importação da nota fiscal XML, criando produtos ou movimentações.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function confirmarImportacao(Request $request): JsonResponse
    {
        $request->validate([
            'nota' => 'required|array',
            'produtos' => 'required|array',
            'deposito_id' => 'required|integer|exists:depositos,id',
        ]);

        $nota = $request->input('nota');
        $produtos = $request->input('produtos');
        $depositoId = $request->input('deposito_id');

        DB::beginTransaction();

        try {
            foreach ($produtos as $item) {
                $variacaoId = $item['variacao_id'] ?? null;

                // Caso o usuário tenha selecionado manualmente
                if (!$variacaoId && !empty($item['variacao_id_manual'])) {
                    ProdutoVariacaoVinculo::firstOrCreate(
                        ['descricao_xml' => $item['descricao_xml']],
                        ['produto_variacao_id' => $item['variacao_id_manual']]
                    );
                    $variacaoId = $item['variacao_id_manual'];
                }

                // Produto novo (sem vínculo nem variação existente)
                if (!$variacaoId && !empty($item['descricao_xml'])) {
                    if (empty($item['id_categoria'])) {
                        throw new Exception("Categoria não informada para o produto: {$item['descricao_xml']}");
                    }

                    $produto = Produto::create([
                        'nome' => $item['descricao_xml'],
                        'descricao' => $item['observacao'] ?? null,
                        'id_categoria' => $item['id_categoria'],
                        'id_fornecedor' => null,
                        'ativo' => true,
                    ]);

                    $variacao = $produto->variacoes()->create([
                        'referencia' => $item['referencia'],
                        'nome' => $item['descricao_xml'],
                        'preco' => $item['preco_unitario'],
                        'custo' => $item['preco_unitario'],
                        'codigo_barras' => null,
                    ]);

                    ProdutoVariacaoVinculo::create([
                        'descricao_xml' => $item['descricao_xml'],
                        'produto_variacao_id' => $variacao->id,
                    ]);

                    $variacaoId = $variacao->id;
                }

                if (!$variacaoId) {
                    throw new Exception("Produto não identificado e sem variação vinculada: {$item['descricao_xml']}");
                }

                EstoqueMovimentacao::create([
                    'id_variacao' => $variacaoId,
                    'id_deposito_origem' => null,
                    'id_deposito_destino' => $depositoId,
                    'tipo' => 'entrada',
                    'quantidade' => $item['quantidade'],
                    'observacao' => 'Importação NF-e nº ' . $nota['numero'],
                    'data_movimentacao' => $nota['data_emissao'],
                ]);

            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Importação confirmada com sucesso.',
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erro ao confirmar importação.',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function estoqueBaixo(Request $request): JsonResponse
    {
        $inicio = microtime(true);
        $limite = (int) $request->query('limite', 5);
        $cacheKey = "estoque_baixo_limite_{$limite}";

        $dados = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($limite, $cacheKey, $inicio) {
            LogService::debug('EstoqueCache', 'Gerando novo cache', ['cacheKey' => $cacheKey]);

            $resultado = DB::table('estoque')
                ->join('produto_variacoes', 'estoque.id_variacao', '=', 'produto_variacoes.id')
                ->join('produtos', 'produto_variacoes.produto_id', '=', 'produtos.id')
                ->join('depositos', 'estoque.id_deposito', '=', 'depositos.id')
                ->where('estoque.quantidade', '<', $limite)
                ->select(
                    'produtos.nome as produto',
                    'produto_variacoes.nome as variacao',
                    'depositos.nome as deposito',
                    'estoque.quantidade',
                    'produto_variacoes.preco'
                )
                ->orderBy('estoque.quantidade', 'asc')
                ->get();

            $fim = microtime(true);
            LogService::debug('EstoqueCache', 'Estoque crítico calculado', [
                'cacheKey' => $cacheKey,
                'duration_ms' => round(($fim - $inicio) * 1000, 2)
            ]);

            return $resultado;
        });

        $fim = microtime(true);
        LogService::debug('EstoqueCache', 'Estoque crítico carregado via cache ou computado', [
            'cacheKey' => $cacheKey,
            'duration_ms' => round(($fim - $inicio) * 1000, 2)
        ]);

        return response()->json($dados);
    }

}
