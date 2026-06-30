<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLocalizacaoEstoqueRequest;
use App\Http\Requests\UpdateLocalizacaoEstoqueRequest;
use App\Http\Resources\EstoqueLocalizacaoPendenteResource;
use App\Http\Resources\LocalizacaoEstoqueResource;
use App\Http\Resources\ProdutoEstoqueResource;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\LocalizacaoEstoque;
use App\Services\LocalizacaoEstoqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalizacaoEstoqueController extends Controller
{
    public function __construct(private readonly LocalizacaoEstoqueService $service) {}

    public function index(Request $request, Deposito $deposito): JsonResponse
    {
        $localizacoes = $this->service->listarPorDeposito($deposito, $request->query());

        return LocalizacaoEstoqueResource::collection($localizacoes)->additional([
            'meta' => [
                'current_page' => $localizacoes->currentPage(),
                'per_page' => $localizacoes->perPage(),
                'total' => $localizacoes->total(),
                'last_page' => $localizacoes->lastPage(),
            ],
        ])->response();
    }

    public function pendencias(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'deposito' => ['nullable', 'integer', 'min:1'],
            'produto' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $pendencias = $this->service->listarPendencias($dados);

        return EstoqueLocalizacaoPendenteResource::collection($pendencias)->additional([
            'meta' => [
                'current_page' => $pendencias->currentPage(),
                'per_page' => $pendencias->perPage(),
                'total' => $pendencias->total(),
                'last_page' => $pendencias->lastPage(),
            ],
        ])->response();
    }

    public function atribuirEstoquesEmMassa(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'estoque_ids' => ['required', 'array', 'min:1'],
            'estoque_ids.*' => ['integer', 'distinct', 'exists:estoque,id'],
            'localizacao_id' => ['required', 'integer', 'exists:localizacoes_estoque,id'],
        ]);

        $resultado = $this->service->atribuirEstoquesEmMassa(
            $dados['estoque_ids'],
            (int) $dados['localizacao_id']
        );

        return response()->json([
            'data' => [
                'atualizados' => $resultado['atualizados'],
                'localizacao' => (new LocalizacaoEstoqueResource($resultado['localizacao']))->resolve($request),
            ],
        ]);
    }

    public function store(StoreLocalizacaoEstoqueRequest $request, Deposito $deposito): JsonResponse
    {
        $localizacao = $this->service->criar($deposito, $request->validated());

        return (new LocalizacaoEstoqueResource($localizacao))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Deposito $deposito, LocalizacaoEstoque $localizacao): JsonResponse
    {
        if ((int) $localizacao->deposito_id !== (int) $deposito->id) {
            abort(404);
        }

        return (new LocalizacaoEstoqueResource(
            $localizacao->loadCount('estoques as ocupacao_itens')->loadSum('estoques as ocupacao_pecas', 'quantidade')
        ))->response();
    }

    public function update(
        UpdateLocalizacaoEstoqueRequest $request,
        Deposito $deposito,
        LocalizacaoEstoque $localizacao
    ): JsonResponse {
        $localizacao = $this->service->atualizar($deposito, $localizacao, $request->validated());

        return (new LocalizacaoEstoqueResource($localizacao))->response();
    }

    public function destroy(Deposito $deposito, LocalizacaoEstoque $localizacao): JsonResponse
    {
        $localizacao = $this->service->excluir($deposito, $localizacao);

        if ($localizacao === null) {
            return response()->json(null, 204);
        }

        return (new LocalizacaoEstoqueResource($localizacao))->response();
    }

    public function atribuirEstoque(Request $request, Estoque $estoque): JsonResponse
    {
        $dados = $request->validate([
            'localizacao_id' => ['nullable', 'integer', 'exists:localizacoes_estoque,id'],
        ]);

        $estoque = $this->service->atribuirAoEstoque(
            $estoque,
            array_key_exists('localizacao_id', $dados) ? $dados['localizacao_id'] : null
        );

        $variacao = $estoque->variacao()
            ->with(['produto.categoria', 'produto.fornecedor', 'atributos'])
            ->first();

        if ($variacao) {
            $variacao->setRelation('estoquesComLocalizacao', collect([$estoque]));
            $variacao->setAttribute('quantidade_estoque', $estoque->quantidade);

            return response()->json([
                'data' => new ProdutoEstoqueResource($variacao),
            ]);
        }

        return response()->json([
            'data' => [
                'estoque_id' => $estoque->id,
                'localizacao' => new LocalizacaoEstoqueResource($estoque->localizacao),
            ],
        ]);
    }
}
