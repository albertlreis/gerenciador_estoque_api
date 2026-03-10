<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Resources\ProdutoMiniResource;
use App\Http\Resources\ProdutoSimplificadoResource;
use App\Http\Resources\ProdutoVariacaoResource;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Services\AuditoriaEventoService;
use App\Services\ProdutoVariacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ProdutoVariacaoController extends Controller
{
    protected ProdutoVariacaoService $service;

    public function __construct(
        ProdutoVariacaoService $service,
        private readonly AuditoriaEventoService $auditoria
    )
    {
        $this->service = $service;
    }

    public function index(Produto $produto): AnonymousResourceCollection
    {
        $variacoes = $produto->variacoes()
            ->with([
                'atributos',
                'imagem',
                'produto',
                'estoques',
                'outlets.motivo',
                'outlets.formasPagamento.formaPagamento',
            ])
            ->get();

        return ProdutoVariacaoResource::collection($variacoes);
    }

    public function store(Request $request, Produto $produto): JsonResponse
    {
        if ($resposta = $this->autorizarVariacao('criar')) {
            return $resposta;
        }

        $validated = $request->validate([
            'referencia' => 'required|string|max:100|unique:produto_variacoes,referencia',
            'preco' => 'required|numeric',
            'custo' => 'required|numeric',
            'codigo_barras' => 'nullable|string|max:100',
            'atributos' => 'nullable|array',
            'atributos.*.atributo' => 'required_with:atributos.*.valor|string|max:255',
            'atributos.*.valor' => 'required_with:atributos.*.atributo|string|max:255',
        ]);

        $validated['produto_id'] = $produto->id;
        $variacao = ProdutoVariacao::create($validated);

        $atributos = collect($validated['atributos'] ?? [])
            ->filter(fn($attr) => trim((string)($attr['atributo'] ?? '')) !== '' && trim((string)($attr['valor'] ?? '')) !== '')
            ->map(fn($attr) => [
                'atributo' => $attr['atributo'],
                'valor' => $attr['valor'],
            ])
            ->values();

        if ($atributos->isNotEmpty()) {
            $variacao->atributos()->createMany($atributos->all());
        }

        return response()->json($variacao, 201);
    }

    /**
     * Exibe os dados completos de uma variação específica.
     * Aceita view=completa|simplificada|minima
     */
    public function show(Produto $produto, ProdutoVariacao $variacao): JsonResponse
    {
        $view = request('view', 'completa');
        $variacaoCompleta = $this->service->obterVariacaoCompleta($produto->id, $variacao->id);

        return match ($view) {
            'minima' => ProdutoMiniResource::make($variacaoCompleta->produto)->response(),
            'simplificada' => ProdutoSimplificadoResource::make($variacaoCompleta->produto)->response(),
            default => ProdutoVariacaoResource::make($variacaoCompleta)->response(),
        };
    }


    public function update(Request $request, Produto $produto, ProdutoVariacaoService $service, ProdutoVariacao $variacao = null): JsonResponse
    {
        if ($resposta = $this->autorizarVariacao('editar')) {
            return $resposta;
        }

        $dados = $request->all();

        if (!is_array($dados)) {
            return response()->json(['message' => 'Formato inv??lido.'], 400);
        }

        $isList = array_is_list($dados);

        try {
            if ($isList) {
                $service->atualizarLote($produto->id, $dados);
                return response()->json(['message' => 'Varia????es atualizadas com sucesso.']);
            }

            if (!$variacao) {
                return response()->json(['message' => 'Formato inv??lido.'], 400);
            }

            if ($variacao->produto_id !== $produto->id) {
                return response()->json(['error' => 'Varia????o n??o pertence a este produto'], 404);
            }

            $variacaoAtualizada = $service->atualizarIndividual($variacao, $dados);

            return response()->json($variacaoAtualizada);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Erro inesperado.'], 500);
        }
    }


    public function destroy(Produto $produto, ProdutoVariacao $variacao)
    {
        if ($resposta = $this->autorizarVariacao('excluir')) {
            return $resposta;
        }

        if ($variacao->produto_id !== $produto->id) {
            return response()->json(['error' => 'Variação não pertence a este produto'], 404);
        }

        $variacao->delete();
        return response()->json(null, 204);
    }

    public function buscar(Request $request): JsonResponse
    {
        $query = ProdutoVariacao::query()
            ->with(['produto', 'atributos', 'imagem'])
            ->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $busca = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $request->input('search')));

            $query->where(function ($q) use ($busca) {
                $q->whereRaw("LOWER(referencia) COLLATE utf8mb4_general_ci LIKE ?", ["%{$busca}%"])
                    ->orWhereRaw("LOWER(codigo_barras) COLLATE utf8mb4_general_ci LIKE ?", ["%{$busca}%"])
                    ->orWhereHas('produto', function ($qp) use ($busca) {
                        $qp->whereRaw("LOWER(nome) COLLATE utf8mb4_general_ci LIKE ?", ["%{$busca}%"]);
                    });
            });
        }

        $variacoes = $query->get();

        return response()->json(
            $variacoes->map(function ($v) {
                return [
                    'id' => $v->id,
                    'nome_completo' => $v->nome_completo,
                    'referencia' => $v->referencia,
                    'produto_id' => $v->produto_id,
                    'produto_nome' => $v->produto?->nome,
                    'preco' => (float) ($v->preco ?? 0),
                    'imagem_url' => $v->imagem?->url,
                ];
            })
        );
    }

    public function patchGlobal(Request $request, ProdutoVariacao $variacao): JsonResponse
    {
        if ($resposta = $this->autorizarVariacao('editar')) {
            return $resposta;
        }

        $payload = $request->all();
        if (!is_array($payload)) {
            return response()->json(['message' => 'Formato inválido.'], 400);
        }

        $camposPermitidos = ['referencia', 'nome', 'preco', 'custo', 'codigo_barras'];
        $camposRecebidos = array_keys(array_diff_key($payload, ['audit' => true]));
        $naoPermitidos = array_values(array_diff($camposRecebidos, $camposPermitidos));
        if (!empty($naoPermitidos)) {
            return response()->json([
                'message' => 'Campos não permitidos no PATCH.',
                'fields' => $naoPermitidos,
            ], 422);
        }

        $validator = Validator::make($payload, [
            'referencia' => 'sometimes|string|max:100|unique:produto_variacoes,referencia,' . $variacao->id,
            'nome' => 'sometimes|nullable|string|max:255',
            'preco' => 'sometimes|numeric|min:0',
            'custo' => 'sometimes|nullable|numeric|min:0',
            'codigo_barras' => 'sometimes|nullable|string|max:100',
            'audit' => 'sometimes|array',
            'audit.label' => 'sometimes|nullable|string|max:255',
            'audit.motivo' => 'sometimes|nullable|string|max:500',
            'audit.origin' => 'sometimes|nullable|in:checkout,cadastro,importacao',
            'audit.metadata' => 'sometimes|array',
            'audit.metadata.carrinho_id' => 'sometimes|nullable|integer|min:1',
        ]);

        $validator->after(function ($validator) use ($payload): void {
            if (array_key_exists('preco', $payload)) {
                $motivo = trim((string) data_get($payload, 'audit.motivo', ''));
                if ($motivo === '') {
                    $validator->errors()->add('audit.motivo', 'O motivo é obrigatório para alteração de preço.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dados inválidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $auditInput = (array) ($payload['audit'] ?? []);
        $updates = collect($camposPermitidos)
            ->filter(fn (string $campo) => array_key_exists($campo, $payload))
            ->mapWithKeys(fn (string $campo) => [$campo => $payload[$campo]])
            ->all();

        if (empty($updates)) {
            return response()->json($variacao->fresh());
        }

        $before = $variacao->only($camposPermitidos);
        $variacao->fill($updates);

        if (!$variacao->isDirty()) {
            return response()->json($variacao->fresh());
        }

        DB::transaction(function () use ($variacao, $camposPermitidos, $before, $auditInput): void {
            $variacao->save();

            if ($variacao->wasChanged('preco')) {
                $novoPreco = (float) $variacao->preco;

                DB::table('carrinho_itens as ci')
                    ->join('carrinhos as c', 'c.id', '=', 'ci.id_carrinho')
                    ->where('ci.id_variacao', $variacao->id)
                    ->whereNull('ci.outlet_id')
                    ->where('c.status', 'rascunho')
                    ->update([
                        'ci.preco_unitario' => $novoPreco,
                        'ci.subtotal' => DB::raw("ci.quantidade * {$novoPreco}"),
                        'ci.updated_at' => now(),
                    ]);
            }

            $mudancas = [];
            foreach ($camposPermitidos as $campo) {
                if (!$variacao->wasChanged($campo)) {
                    continue;
                }

                $mudancas[] = [
                    'campo' => $campo,
                    'old' => $before[$campo] ?? null,
                    'new' => $variacao->{$campo},
                ];
            }

            $metadataExtra = (array) ($auditInput['metadata'] ?? []);
            $metadata = array_filter([
                'motivo' => $auditInput['motivo'] ?? null,
                'origin' => $auditInput['origin'] ?? null,
                'carrinho_id' => $metadataExtra['carrinho_id'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');

            $this->auditoria->registrar(
                module: 'produto_variacoes',
                action: 'update',
                label: (string) ($auditInput['label'] ?? 'Atualização de variação'),
                auditable: $variacao,
                mudancas: $mudancas,
                metadata: $metadata
            );
        });

        return response()->json($variacao->fresh());
    }


    private function autorizarVariacao(string $acao): ?JsonResponse
    {
        $mapa = [
            'criar' => ['produto_variacoes.criar', 'produtos.editar', 'produtos.gerenciar'],
            'editar' => ['produto_variacoes.editar', 'produtos.editar', 'produtos.gerenciar'],
            'excluir' => ['produto_variacoes.excluir', 'produtos.excluir', 'produtos.gerenciar'],
        ];

        $permissoes = $mapa[$acao] ?? [];

        foreach ($permissoes as $permissao) {
            if (AuthHelper::hasPermissao($permissao)) {
                return null;
            }
        }

        return response()->json(['message' => 'Sem permissão para esta ação.'], 403);
    }

}
