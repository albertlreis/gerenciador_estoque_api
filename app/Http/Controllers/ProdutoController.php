<?php
namespace App\Http\Controllers;

use App\Http\Requests\StoreProdutoRequest;
use App\Http\Requests\UpdateProdutoRequest;
use App\Http\Resources\ProdutoResource;
use App\Models\Produto;
use App\Services\ProdutoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Controlador responsável por gerenciar o CRUD de produtos e suas variações.
 * Cada produto pode ter múltiplas variações, cada uma com seus próprios atributos (ex: cor, material).
 */
class ProdutoController extends Controller
{
    private ProdutoService $produtoService;

    /**
     * Injeta a dependência do service de produtos.
     *
     * @param ProdutoService $produtoService
     */
    public function __construct(ProdutoService $produtoService)
    {
        $this->produtoService = $produtoService;
    }

    /**
     * Lista produtos com paginação e filtros opcionais (nome, categoria).
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Produto::with(['variacoes.atributos']);

        if ($request->filled('nome')) {
            $query->where('nome', 'like', '%' . $request->nome . '%');
        }

        if ($request->filled('id_categoria')) {
            $query->where('id_categoria', $request->id_categoria);
        }

        if ($request->filled('ativo')) {
            $query->where('ativo', (bool) $request->ativo);
        }

        if ($request->filled('fabricante')) {
            $query->where('fabricante', 'like', '%' . $request->fabricante . '%');
        }

        if ($request->boolean('has_variacoes')) {
            $query->has('variacoes');
        }
        
        $query->orderBy('created_at', 'desc');

        $produtos = $query->paginate($request->get('per_page', 15));

        return ProdutoResource::collection($produtos);
    }

    /**
     * Cria um novo produto com suas variações e atributos.
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
                'error'   => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Retorna os dados de um produto específico.
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
     * Atualiza um produto, suas variações e atributos.
     * Variações e atributos removidos no payload são apagados.
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
                'error'   => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Remove um produto e todas as suas variações e atributos vinculados.
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
                'error'   => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }
}
