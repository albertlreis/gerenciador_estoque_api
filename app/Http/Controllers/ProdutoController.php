<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
     * @return LengthAwarePaginator
     */
    public function index(FiltrarProdutosRequest $request): LengthAwarePaginator
    {
        return $this->produtoService->listarProdutosFiltrados($request);
    }

    /**
     * Cadastra um novo produto com suas variações e atributos.
     *
     * @param StoreProdutoRequest $request
     * @return JsonResponse
     */
    public function store(StoreProdutoRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();
            $produto = $this->produtoService->store($request->validated());
            DB::commit();

            return response()->json([
                'message' => 'Produto cadastrado com sucesso.',
                'produto' => new ProdutoResource($produto)
            ], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erro ao cadastrar produto.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Exibe os dados de um produto específico.
     *
     * @param int $id
     * @return ProdutoResource
     */
    public function show(int $id): ProdutoResource
    {
        $produto = Produto::with(['variacoes.atributos'])->findOrFail($id);
        return new ProdutoResource($produto);
    }

    /**
     * Atualiza os dados de um produto e suas variações.
     *
     * @param UpdateProdutoRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateProdutoRequest $request, int $id): JsonResponse
    {
        try {
            DB::beginTransaction();
            $produto = Produto::findOrFail($id);
            $produto = $this->produtoService->update($produto, $request->validated());
            DB::commit();

            return response()->json([
                'message' => 'Produto atualizado com sucesso.',
                'produto' => new ProdutoResource($produto)
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erro ao atualizar produto.',
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
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
                        throw new \Exception("Categoria não informada para o produto: {$item['descricao_xml']}");
                    }

                    $produto = Produto::create([
                        'nome' => $item['descricao_xml'],
                        'descricao' => $item['observacao'] ?? null,
                        'id_categoria' => $item['id_categoria'],
                        'id_fornecedor' => null,
                        'ativo' => true,
                    ]);

                    $variacao = $produto->variacoes()->create([
                        'sku' => 'IMP-' . uniqid(),
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
                    throw new \Exception("Produto não identificado: {$item['descricao_xml']}");
                }

                // Registrar movimentação de entrada
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
}
