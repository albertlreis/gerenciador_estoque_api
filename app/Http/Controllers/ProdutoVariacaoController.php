<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Resources\ProdutoMiniResource;
use App\Http\Resources\ProdutoSimplificadoResource;
use App\Http\Resources\ProdutoVariacaoResource;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Services\ProdutoVariacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ProdutoVariacaoController extends Controller
{
    protected ProdutoVariacaoService $service;

    public function __construct(ProdutoVariacaoService $service)
    {
        $this->service = $service;
    }

    public function index(Produto $produto): AnonymousResourceCollection
    {
        $variacoes = $produto->variacoes()
            ->with([
                'atributos',
                'codigosHistoricos',
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

        $variacao = $this->service->criarParaProduto($produto, $request->all());

        return ProdutoVariacaoResource::make($variacao)->response()->setStatusCode(201);
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
            return response()->json(['message' => 'Não foi possível ler os dados enviados. Atualize a página e tente novamente.'], 400);
        }

        $isList = array_is_list($dados);

        try {
            if ($isList) {
                $service->atualizarLote($produto->id, $dados);
                return response()->json(['message' => 'Variações salvas com sucesso.']);
            }

            if (!$variacao) {
                return response()->json(['message' => 'Não foi possível identificar a variação que será atualizada.'], 400);
            }

            if ($variacao->produto_id !== $produto->id) {
                return response()->json(['message' => 'Esta variação não pertence ao produto informado.'], 404);
            }

            $variacaoAtualizada = $service->atualizarIndividual($variacao, $dados);

            return response()->json($variacaoAtualizada);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Não foi possível salvar as variações agora. Tente novamente.'], 500);
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

        $this->service->registrarRemocao($variacao);
        $variacao->delete();
        return response()->json(null, 204);
    }

    public function buscar(Request $request): JsonResponse
    {
        $query = ProdutoVariacao::query()
            ->with(['produto', 'atributos', 'codigosHistoricos', 'imagem'])
            ->orderBy('id', 'desc');

        if ($request->filled('search')) {
            $busca = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $request->input('search')));

            $query->where(function ($q) use ($busca) {
                $q->whereRaw("LOWER(referencia) COLLATE utf8mb4_general_ci LIKE ?", ["%{$busca}%"])
                    ->orWhereRaw("LOWER(sku_interno) COLLATE utf8mb4_general_ci LIKE ?", ["%{$busca}%"])
                    ->orWhereRaw("LOWER(chave_variacao) COLLATE utf8mb4_general_ci LIKE ?", ["%{$busca}%"])
                    ->orWhereRaw("LOWER(codigo_barras) COLLATE utf8mb4_general_ci LIKE ?", ["%{$busca}%"])
                    ->orWhereHas('codigosHistoricos', function ($qc) use ($busca) {
                        $qc->whereRaw("LOWER(codigo) COLLATE utf8mb4_general_ci LIKE ?", ["%{$busca}%"])
                            ->orWhereRaw("LOWER(codigo_origem) COLLATE utf8mb4_general_ci LIKE ?", ["%{$busca}%"])
                            ->orWhereRaw("LOWER(codigo_modelo) COLLATE utf8mb4_general_ci LIKE ?", ["%{$busca}%"]);
                    })
                    ->orWhereHas('produto', function ($qp) use ($busca) {
                        $qp->whereRaw("LOWER(nome) COLLATE utf8mb4_general_ci LIKE ?", ["%{$busca}%"])
                            ->orWhereRaw("LOWER(codigo_produto) COLLATE utf8mb4_general_ci LIKE ?", ["%{$busca}%"]);
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
                    'sku_interno' => $v->sku_interno,
                    'chave_variacao' => $v->chave_variacao,
                    'identificador_variacao' => $v->sku_interno ?: ($v->referencia ?: $v->chave_variacao),
                    'produto_id' => $v->produto_id,
                    'produto_nome' => $v->produto?->nome,
                    'codigo_produto' => $v->produto?->codigo_produto,
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
            return response()->json(['message' => 'Não foi possível ler os dados enviados. Atualize a página e tente novamente.'], 400);
        }

        $camposPermitidos = [
            'referencia',
            'nome',
            'preco',
            'custo',
            'codigo_barras',
            'sku_interno',
            'chave_variacao',
            'dimensao_1',
            'dimensao_2',
            'dimensao_3',
            'cor',
            'lado',
            'material_oficial',
            'acabamento_oficial',
            'conflito_codigo',
            'status_revisao',
        ];
        $camposRecebidos = array_keys(array_diff_key($payload, ['audit' => true]));
        $naoPermitidos = array_values(array_diff($camposRecebidos, $camposPermitidos));
        if (!empty($naoPermitidos)) {
            return response()->json([
                'message' => 'Alguns campos enviados não podem ser alterados por esta tela.',
                'fields' => $naoPermitidos,
            ], 422);
        }

        $validator = Validator::make($payload, [
            'referencia' => 'sometimes|nullable|string|max:100',
            'nome' => 'sometimes|nullable|string|max:255',
            'preco' => 'sometimes|numeric|min:0',
            'custo' => 'sometimes|nullable|numeric|min:0',
            'codigo_barras' => 'sometimes|nullable|string|max:100',
            'sku_interno' => 'sometimes|nullable|string|max:120|unique:produto_variacoes,sku_interno,' . $variacao->id,
            'chave_variacao' => 'sometimes|nullable|string|max:255|unique:produto_variacoes,chave_variacao,' . $variacao->id,
            'dimensao_1' => 'sometimes|nullable|numeric|min:0',
            'dimensao_2' => 'sometimes|nullable|numeric|min:0',
            'dimensao_3' => 'sometimes|nullable|numeric|min:0',
            'cor' => 'sometimes|nullable|string|max:150',
            'lado' => 'sometimes|nullable|string|max:120',
            'material_oficial' => 'sometimes|nullable|string|max:180',
            'acabamento_oficial' => 'sometimes|nullable|string|max:180',
            'conflito_codigo' => 'sometimes|boolean',
            'status_revisao' => 'sometimes|nullable|in:nao_revisado,pendente_revisao,aprovado,rejeitado',
            'audit' => 'sometimes|array',
            'audit.label' => 'sometimes|nullable|string|max:255',
            'audit.motivo' => 'sometimes|nullable|string|max:500',
            'audit.origin' => 'sometimes|nullable|in:checkout,cadastro,importacao',
            'audit.metadata' => 'sometimes|array',
            'audit.metadata.carrinho_id' => 'sometimes|nullable|integer|min:1',
        ], [
            'preco.numeric' => 'Informe um preço válido para a variação.',
            'preco.min' => 'O preço da variação não pode ser negativo.',
            'custo.numeric' => 'Informe um custo válido para a variação.',
            'custo.min' => 'O custo da variação não pode ser negativo.',
            'sku_interno.unique' => 'Este SKU interno já está em uso em outra variação.',
            'chave_variacao.unique' => 'Esta chave de variação já está em uso.',
            'audit.motivo.max' => 'O motivo pode ter no máximo 500 caracteres.',
            'audit.origin.in' => 'A origem da alteração de preço é inválida.',
        ]);

        $validator->after(function ($validator) use ($payload): void {
            if (array_key_exists('preco', $payload)) {
                $motivo = trim((string) data_get($payload, 'audit.motivo', ''));
                if ($motivo === '') {
                    $validator->errors()->add('audit.motivo', 'Informe o motivo da alteração de preço.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Revise os campos destacados e tente novamente.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $auditInput = (array) ($payload['audit'] ?? []);
        $updates = collect($camposPermitidos)
            ->filter(fn (string $campo) => array_key_exists($campo, $payload))
            ->mapWithKeys(fn (string $campo) => [$campo => $payload[$campo]])
            ->all();

        if (array_key_exists('referencia', $updates)) {
            $updates['referencia'] = $this->service->gerarReferenciaLegadaFallback(
                $updates,
                $variacao,
                $variacao->produto_id
            );
        }

        if (empty($updates)) {
            return response()->json($variacao->fresh());
        }

        $variacaoAtualizada = $this->service->salvarComAuditoria(
            $variacao,
            $updates,
            $auditInput,
            'Atualização de variação'
        );

        return response()->json($variacaoAtualizada);
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
