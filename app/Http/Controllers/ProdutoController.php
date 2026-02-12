<?php

namespace App\Http\Controllers;

use App\Domain\Importacao\DTO\AtributoDTO;
use App\Domain\Importacao\DTO\NotaDTO;
use App\Domain\Importacao\DTO\ProdutoImportadoDTO;
use App\Domain\Importacao\Services\ImportacaoProdutosService;
use App\Http\Resources\ProdutoMiniResource;
use App\Http\Resources\ProdutoSimplificadoResource;
use App\Services\LogService;
use App\Services\ProdutoSugestoesOutletService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Requests\{ConfirmarImportacaoRequest,
    ExportarProdutosOutletRequest,
    FiltrarProdutosRequest,
    ImportarXmlRequest,
    StoreProdutoRequest,
    UpdateProdutoRequest};
use App\Http\Resources\ProdutoResource;
use App\Models\{
    Produto,
    ProdutoImagem
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
     * Endpoint unificado de listagem e busca de produtos.
     * Aceita filtros e modos contextuais (com/sem estoque, depósito, outlet, etc).
     * Aceita ?view=completa|simplificada|minima
     *
     * Exemplo:
     *  GET /produtos?q=mesa&deposito_id=2&incluir_estoque=true
     */
    public function index(FiltrarProdutosRequest $request): JsonResponse
    {
        $view = $request->get('view', 'completa');
        $incluirEstoque = in_array($view, ['completa', 'simplificada']);
        $request->merge(['incluir_estoque' => $incluirEstoque]);

        $produtos = $this->produtoService->listarProdutosFiltrados($request);

        return match ($view) {
            'minima' => ProdutoMiniResource::collection($produtos)->response(),
            'simplificada' => ProdutoSimplificadoResource::collection($produtos)->response(),
            default => ProdutoResource::collection($produtos)->response(),
        };
    }

    /**
     * Exporta produtos em outlet selecionados (CSV ou PDF).
     */
    public function exportarOutlet(ExportarProdutosOutletRequest $request)
    {
        $ids = $request->validated()['ids'] ?? [];
        $format = strtolower((string) $request->input('format', 'csv'));
        $format = in_array($format, ['csv', 'pdf'], true) ? $format : 'csv';

        $produtos = Produto::query()
            ->whereIn('id', $ids)
            ->whereHas('variacoes.outlet')
            ->with([
                'categoria',
                'imagemPrincipal',
                'variacoes.outlets.formasPagamento',
            ])
            ->get();

        if ($produtos->isEmpty()) {
            return response()->json([
                'message' => 'Nenhum produto outlet encontrado para exportacao.',
            ], 422);
        }

        if ($format === 'pdf') {
            Pdf::setOptions(['isRemoteEnabled' => true]);
            $baseFsDir = public_path('storage' . DIRECTORY_SEPARATOR . ProdutoImagem::FOLDER);

            $pdf = Pdf::loadView('exports.outlet', [
                'produtos' => $produtos,
                'baseFsDir' => $baseFsDir,
            ])->setPaper('a4', 'landscape');

            $dateRef = now('America/Belem')->format('Y-m-d');
            return $pdf->download("catalogo_outlet_{$dateRef}.pdf");
        }

        $filename = 'catalogo_outlet.csv';

        return response()->streamDownload(function () use ($produtos) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM para melhor compatibilidade com Excel
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'ID',
                'Nome',
                'Categoria',
                'Referencias',
                'Preco_Minimo',
                'Outlet_Restante',
            ], ';');

            foreach ($produtos as $produto) {
                $variacoes = $produto->variacoes ?? collect();
                $referencias = $variacoes
                    ->pluck('referencia')
                    ->filter()
                    ->unique()
                    ->implode(', ');

                $precoMin = $variacoes
                    ->pluck('preco')
                    ->filter(fn ($v) => $v !== null)
                    ->min();

                $outletRestante = $variacoes->sum(function ($v) {
                    return $v->relationLoaded('outlets')
                        ? (int) $v->outlets->sum('quantidade_restante')
                        : 0;
                });

                fputcsv($out, [
                    $produto->id,
                    $produto->nome,
                    $produto->categoria?->nome ?? '',
                    $referencias,
                    $precoMin !== null ? number_format((float) $precoMin, 2, ',', '') : '',
                    $outletRestante,
                ], ';');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
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
     * Permite escolher o formato de resposta:
     *  - default (completo)
     *  - ?view=simplificada
     *  - ?view=minima
     */
    public function show(int $id): JsonResponse
    {
        $view = request('view', 'completa');
        $depositoId = request('deposito_id'); // ✅ novo parâmetro
        $produto = $this->produtoService->obterProdutoCompleto($id, $depositoId);

        return match ($view) {
            'minima' => ProdutoMiniResource::make($produto)->response(),
            'simplificada' => ProdutoSimplificadoResource::make($produto)->response(),
            default => ProdutoResource::make($produto)->response(),
        };
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
        $dataEntrada = $request->input('data_entrada');

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

        $this->service->confirmar($notaDto, $itens, $depositoId, $xmlString, $dataEntrada);

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
