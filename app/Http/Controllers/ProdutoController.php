<?php

namespace App\Http\Controllers;

use App\Domain\Importacao\DTO\AtributoDTO;
use App\Domain\Importacao\DTO\NotaDTO;
use App\Domain\Importacao\DTO\ProdutoImportadoDTO;
use App\Domain\Importacao\Services\ImportacaoProdutosService;
use App\Services\LogService;
use App\Services\ProdutoSugestoesOutletService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Requests\{ConfirmarImportacaoRequest,
    FiltrarProdutosRequest,
    ImportarXmlRequest,
    StoreProdutoRequest,
    UpdateProdutoRequest};
use App\Http\Resources\ProdutoResource;
use App\Models\{
    Produto
};
use App\Services\ProdutoService;

class ProdutoController extends Controller
{
    private ProdutoService $produtoService;
    private readonly ImportacaoProdutosService $service;

    /**
     * Construtor com injeção de dependência do ProdutoService.
     */
    public function __construct(ProdutoService $produtoService, ImportacaoProdutosService $service)
    {
        $this->produtoService = $produtoService;
        $this->service = $service;
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
            'variacoes.outlets.motivo',
            'variacoes.outlets.formasPagamento.formaPagamento',
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
     * @throws \Exception
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


    /** Upload e parsing do XML */
    public function importarXML(ImportarXmlRequest $request): JsonResponse
    {
        [$notaDto, $produtosDto, $xmlString] = $this->service->parsearXml($request->file('arquivo'));

        // 🔹 Gera identificador único e salva XML temporário
        $tokenXml = 'xml-' . Str::uuid() . '.xml';
        $path = "importacoes/tmp/{$tokenXml}";
        Storage::disk('local')->put($path, $xmlString);

        $nota = [
            'numero'           => $notaDto->numero,
            'data_emissao'     => $notaDto->dataEmissao,
            'fornecedor_cnpj'  => $notaDto->fornecedorCnpj,
            'fornecedor_nome'  => $notaDto->fornecedorNome,
        ];

        $produtos = $produtosDto->map(function(ProdutoImportadoDTO $p) {
            return [
                'descricao_xml'       => $p->descricaoXml,
                'referencia'          => $p->referencia,
                'unidade'             => $p->unidade,
                'quantidade'          => $p->quantidade,
                'custo_unitario'      => $p->custoUnitXml,
                'valor_total'         => $p->valorTotalXml,
                'observacao'          => $p->observacao,
                'id_categoria'        => $p->idCategoria,
                'variacao_id_manual'  => $p->variacaoIdManual,
                'variacao_id'         => $p->variacaoIdEncontrada,
                'preco'               => $p->precoCadastrado,
                'custo_cadastrado'    => $p->custoCadastrado,
                'descricao_final'     => $p->descricaoFinal,
                'atributos'           => collect($p->atributos)
                    ->map(fn($a) => ['atributo' => $a->atributo, 'valor' => $a->valor])
                    ->values()
                    ->toArray(),
                'pedido_id'           => $p->pedidoId,
            ];
        });

        return response()->json([
            'nota'        => $nota,
            'produtos'    => $produtos,
            'token_xml'   => $tokenXml,
        ]);
    }

    /** Confirma a importação */
    public function confirmarImportacao(ConfirmarImportacaoRequest $request): JsonResponse
    {
        $notaArr    = $request->input('nota');
        $depositoId = (int)$request->input('deposito_id');
        $produtos   = collect($request->input('produtos'));
        $tokenXml   = $request->input('token_xml');

        if (!$tokenXml) {
            return response()->json([
                'success' => false,
                'message' => 'Token do XML não informado. Reimporte o arquivo.',
            ], 422);
        }

        $path = "importacoes/tmp/{$tokenXml}";
        if (!Storage::disk('local')->exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Arquivo XML temporário não encontrado. Reimporte o XML.',
            ], 422);
        }

        $xmlString = Storage::disk('local')->get($path);

        $notaDto = new NotaDTO(
            numero: (string)$notaArr['numero'],
            dataEmissao: $notaArr['data_emissao'] ?? null,
            fornecedorCnpj: $notaArr['fornecedor_cnpj'] ?? null,
            fornecedorNome: $notaArr['fornecedor_nome'] ?? null,
        );

        $itens = $produtos->map(function(array $i) {
            return new ProdutoImportadoDTO(
                descricaoXml: $i['descricao_xml'],
                referencia: $i['referencia'] ?? null,
                unidade: $i['unidade'] ?? null,
                quantidade: (float)$i['quantidade'],
                custoUnitXml: (float)$i['custo_unitario'],
                valorTotalXml: (float)($i['valor_total'] ?? ($i['quantidade'] * $i['custo_unitario'])),
                observacao: $i['observacao'] ?? null,
                idCategoria: $i['id_categoria'] ?? null,
                variacaoIdManual: $i['variacao_id_manual'] ?? null,
                variacaoIdEncontrada: $i['variacao_id'] ?? null,
                precoCadastrado: $i['preco'] ?? null,
                custoCadastrado: $i['custo_cadastrado'] ?? null,
                descricaoFinal: $i['descricao_final'] ?? ($i['descricao_xml'] ?? null),
                atributos: collect($i['atributos'] ?? [])
                    ->map(fn($a) => new AtributoDTO($a['atributo'], $a['valor']))->all(),
                pedidoId: $i['pedido_id'] ?? null,
            );
        });

        $this->service->confirmar($notaDto, $itens, $depositoId, $xmlString);

        // Remove arquivo temporário após uso
        Storage::disk('local')->delete($path);

        return response()->json([
            'success' => true,
            'message' => 'Importação confirmada com sucesso.',
        ]);
    }

    public function estoqueBaixo(Request $request): JsonResponse
    {
        $inicio = microtime(true);
        $limite = (int) $request->query('limite', 5);
        $cacheKey = "estoque_baixo_produto_total_limite_{$limite}";

        $dados = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($limite, $cacheKey, $inicio) {
            LogService::debug('EstoqueCache', 'Gerando cache de estoque baixo por produto', ['cacheKey' => $cacheKey]);

            $subquery = DB::table('estoque')
                ->join('produto_variacoes', 'estoque.id_variacao', '=', 'produto_variacoes.id')
                ->select(
                    'produto_variacoes.produto_id',
                    DB::raw('SUM(estoque.quantidade) as quantidade_total')
                )
                ->groupBy('produto_variacoes.produto_id');

            $resultado = DB::table('produtos')
                ->joinSub($subquery, 'estoque_total', function ($join) {
                    $join->on('produtos.id', '=', 'estoque_total.produto_id');
                })
                ->whereColumn('estoque_total.quantidade_total', '<', 'produtos.estoque_minimo')
                ->select(
                    'produtos.id',
                    'produtos.nome',
                    'produtos.estoque_minimo',
                    'estoque_total.quantidade_total as estoque_atual'
                )
                ->orderBy('estoque_total.quantidade_total', 'asc')
                ->limit($limite)
                ->get();

            $fim = microtime(true);
            LogService::debug('EstoqueCache', 'Estoque crítico calculado', [
                'cacheKey' => $cacheKey,
                'duration_ms' => round(($fim - $inicio) * 1000, 2)
            ]);

            return $resultado;
        });

        return response()->json($dados);
    }

    /**
     * Sugestões de produtos para Outlet.
     *
     * Parâmetros aceitos:
     * - limite (int) : qtd máxima (‘default’ 5)
     * - deposito (int|null) : filtra cálculo pelo depósito
     * - ordenar (‘string’) : dias|quantidade|nome (‘default’: dias)
     * - ordem (‘string’) : asc|desc (‘default’: desc)
     */
    public function sugestoesOutlet(Request $request, ProdutoSugestoesOutletService $service): JsonResponse
    {
        $request->validate([
            'limite'   => 'sometimes|integer|min:1|max:100',
            'deposito' => 'sometimes|integer|min:1',
            'ordenar'  => 'sometimes|in:dias,quantidade,nome',
            'ordem'    => 'sometimes|in:asc,desc',
        ]);

        $result = $service->listarPorVariacao($request);

        return response()->json($result);
    }
}
