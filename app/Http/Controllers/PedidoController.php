<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Requests\StorePedidoRequest;
use App\Http\Requests\UpdatePedidoRequest;
use App\Http\Resources\PedidoCompletoResource;
use App\Enums\EstrategiaVinculoImportacao;
use App\Enums\TipoImportacao;
use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueReserva;
use App\Models\Fornecedor;
use App\Models\Pedido;
use App\Models\PedidoImportacao;
use App\Models\ProdutoEntregaEvento;
use App\Models\ProdutoEntregaItem;
use App\Services\EstoqueDisponibilidadeService;
use App\Services\EntregaProdutoService;
use App\Services\FornecedorPedidoXmlParserService;
use App\Services\ImportacaoPedidoService;
use App\Services\NfeXmlParserService;
use App\Services\PedidoService;
use App\Services\PedidoCancelamentoService;
use App\Services\PedidoUpdateService;
use App\Services\EstatisticaPedidoService;
use App\Services\PedidoExportService;
use App\Services\PdfImageService;
use App\Support\Pdf\ClienteEnderecoPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Controlador responsável por operações relacionadas a pedidos.
 */
class PedidoController extends Controller
{
    private const NS_NFE = 'http://www.portalfiscal.inf.br/nfe';
    private const FORNECEDOR_AVANTI_TOKEN = 'avanti';
    private const FORNECEDOR_AVANTI_CNPJS = ['09341891000467'];
    private const FORNECEDOR_AVANTI_RAZOES_SOCIAIS = ['snl industria e comercio textil'];
    private const CATEGORIA_SUGERIDA_AVANTI = 'Tapete';

    protected PedidoService $pedidoService;
    protected PedidoUpdateService $pedidoUpdateService;
    protected ImportacaoPedidoService $importacaoService;
    protected EstatisticaPedidoService $estatisticaService;
    protected PedidoExportService $exportService;

    /**
     * Injeta as dependências necessárias.
     */
    public function __construct(
        PedidoService $pedidoService,
        ImportacaoPedidoService $importacaoService,
        EstatisticaPedidoService $estatisticaService,
        PedidoExportService $exportService,
        PedidoUpdateService $pedidoUpdateService
    ) {
        $this->pedidoService = $pedidoService;
        $this->importacaoService = $importacaoService;
        $this->estatisticaService = $estatisticaService;
        $this->exportService = $exportService;
        $this->pedidoUpdateService = $pedidoUpdateService;
    }

    /**
     * Lista pedidos com filtros, paginação e indicadores adicionais.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        return $this->pedidoService->listarPedidos($request);
    }

    /**
     * Cria um pedido a partir de um carrinho existente.
     *
     * @param StorePedidoRequest $request
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(StorePedidoRequest $request): JsonResponse
    {
        return $this->pedidoService->criarPedido($request);
    }

    /**
     * Atualiza um pedido existente (cabeçalho + itens).
     */
    public function update(UpdatePedidoRequest $request, Pedido $pedido): JsonResponse
    {
        if (!AuthHelper::hasPermissao('pedidos.editar')) {
            return response()->json(['message' => 'Sem permissão para editar pedidos.'], 403);
        }

        $updated = $this->pedidoUpdateService->atualizar(
            $pedido,
            $request->validated(),
            auth()->id()
        );

        $pedidoCompleto = $updated->load([
            'cliente:id,nome,email,telefone',
            'parceiro:id,nome',
            'fornecedor:id,nome,cnpj',
            'usuario:id,nome',
            'statusAtual',
            'itens.variacao.produto.imagens',
            'itens.variacao.atributos',
            'historicoStatus.usuario:id,nome',
            'entregaItens.variacao.produto',
            'entregaItens.variacao.atributos',
            'entregaItens.depositoDestino:id,nome',
            'devolucoes.itens.pedidoItem.variacao.produto',
            'devolucoes.itens.trocaItens.variacaoNova.produto',
            'devolucoes.credito',
        ]);

        return response()->json([
            'message' => 'Pedido atualizado com sucesso.',
            'data' => new PedidoCompletoResource($pedidoCompleto),
        ]);
    }

    public function cancelar(Request $request, Pedido $pedido, PedidoCancelamentoService $service): JsonResponse
    {
        if (!AuthHelper::hasPermissao('pedidos.editar')) {
            return response()->json(['message' => 'Sem permissÃ£o para cancelar pedidos.'], 403);
        }

        $dados = $request->validate([
            'cancelar_reservas' => 'sometimes|boolean',
            'estornar_estoque' => 'sometimes|boolean',
            'cancelar_financeiro' => 'sometimes|boolean',
            'observacoes' => 'nullable|string',
        ]);

        $resultado = $service->cancelar($pedido, $dados, auth()->id());

        return response()->json([
            'message' => 'Venda cancelada com sucesso.',
            'resultado' => $resultado,
        ]);
    }

    /**
     * Retorna os dados completos de um pedido.
     *
     * @param int $pedidoId
     * @return \App\Http\Resources\PedidoCompletoResource
     */
    public function completo(int $pedidoId): PedidoCompletoResource
    {
        return $this->pedidoService->obterPedidoCompleto($pedidoId);
    }

    /**
     * Exporta pedidos em PDF ou Excel.
     *
     * @param Request $request
     * @return Response|BinaryFileResponse|JsonResponse
     */
    public function exportar(Request $request): Response|BinaryFileResponse|JsonResponse
    {
        return $this->exportService->exportar($request);
    }

    /**
     * Retorna estatísticas de pedidos por mês.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function estatisticas(Request $request): JsonResponse
    {
        return response()->json($this->estatisticaService->obterEstatisticas($request));
    }

    /**
     * Confirma a importação de um pedido previamente lido e armazenado em preview.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function confirmarImportacaoPDF(Request $request): JsonResponse
    {
        return $this->importacaoService->confirmarImportacaoPDF($request);
    }

    /**
     * Recebe o XML, realiza o parse e retorna JSON estruturado.
     */
    public function importar(Request $request): JsonResponse
    {
        $requestId = (string) ($request->header('X-Request-Id') ?: Str::uuid());
        $inicioImportacao = microtime(true);

        $tiposPermitidos = TipoImportacao::valores();
        $tipoImportacao = strtoupper((string) $request->input('tipo_importacao', TipoImportacao::PRODUTOS_XML_FORNECEDORES->value));

        if (!in_array($tipoImportacao, $tiposPermitidos, true)) {
            return response()->json([
                'sucesso' => false,
                'mensagem' => 'Tipo de importação inválido.',
                'errors' => [
                    'tipo_importacao' => [
                        'Informe um tipo válido: PRODUTOS_XML_FORNECEDORES ou ADORNOS_XML_NFE.',
                    ],
                ],
            ], 422);
        }

        $isXml = true;

        $arquivoRules = [
            'required',
            'file',
            'mimes:xml',
            'max:10240',
        ];
        if ($isXml) {
            $arquivoRules[] = 'mimetypes:application/xml,text/xml';
        }
        $request->validate([
            'arquivo' => $arquivoRules,
            'tipo_importacao' => 'nullable|string',
            'estrategia_vinculo' => 'nullable|string',
        ], [
            'arquivo.mimes' => 'Envie um arquivo XML válido para a importação.',
        ]);

        if ($isXml && str_ends_with(strtolower($request->file('arquivo')->getClientOriginalName()), ':zone.identifier')) {
            return response()->json([
                'sucesso' => false,
                'mensagem' => 'Arquivo de metadados (Zone.Identifier) não é aceito.',
            ], 422);
        }

        try {
            $arquivo = $request->file('arquivo');
            $tipoImportacaoSolicitado = $tipoImportacao;
            $tipoDetectado = $this->detectarTipoImportacaoXml($arquivo->getRealPath());

            if ($tipoDetectado !== null && $tipoDetectado !== $tipoImportacao) {
                Log::info('Importação de pedido - tipo ajustado por layout XML', [
                    'request_id' => $requestId,
                    'usuario_id' => auth()->id(),
                    'tipo_importacao_solicitado' => $tipoImportacaoSolicitado,
                    'tipo_importacao_detectado' => $tipoDetectado,
                    'arquivo_nome' => $arquivo->getClientOriginalName(),
                ]);

                $tipoImportacao = $tipoDetectado;
            }

            $hashArquivo = hash_file('sha256', $arquivo->getRealPath());
            // IMPORTANTE:
            // - A mesma importação (mesmo arquivo) deve poder ser reprocessada N vezes.
            // - Mantemos o hash do conteúdo apenas para log/telemetria, mas o identificador da importação
            //   precisa ser único por tentativa (sem travas por hash/nome).
            $hash = hash('sha256', $hashArquivo . '|' . $tipoImportacao . '|' . Str::uuid());

            Log::info('Importação de pedido - início', [
                'request_id' => $requestId,
                'etapa' => 'upload',
                'usuario_id' => auth()->id(),
                'tipo_importacao' => $tipoImportacao,
                'tipo_importacao_solicitado' => $tipoImportacaoSolicitado,
                'arquivo_nome' => $arquivo->getClientOriginalName(),
                'arquivo_tamanho' => $arquivo->getSize(),
                'arquivo_hash' => $hash,
            ]);

            if ($tipoImportacao === TipoImportacao::ADORNOS_XML_NFE->value) {
                $dados = app(NfeXmlParserService::class)->extrair($arquivo);
            } else {
                $dados = app(FornecedorPedidoXmlParserService::class)->extrair($arquivo);
            }

            $pedido = $dados['pedido'] ?? [];
            $itens = $dados['itens'] ?? [];
            $totais = $dados['totais'] ?? [];

            $temItens = is_array($itens) && count($itens) > 0;
            $permitePreviewSemItens = true;

            if ($permitePreviewSemItens && !$temItens) {
                $temPedidoMinimo = $this->temPedidoMinimo($pedido, $totais);
                if (!$temPedidoMinimo) {
                    $nomeArquivo = $arquivo->getClientOriginalName();
                    Log::warning('Importação de pedido - sem dados mínimos do pedido', [
                        'request_id' => $requestId,
                        'tipo_importacao' => $tipoImportacao,
                        'arquivo_nome' => $nomeArquivo,
                    ]);
                    return response()->json([
                        'sucesso' => false,
                        'mensagem' => "Não foi possível extrair os dados mínimos do pedido (cabeçalho/totais). Arquivo: {$nomeArquivo}",
                        'erro' => "Dados mínimos do pedido não identificados.",
                    ], 422);
                }
                $nomeArquivo = $arquivo->getClientOriginalName();
                $debugTexto = $dados['debug_texto_extraido'] ?? null;
                Log::warning('Importação de pedido - XML sem itens identificados (preview ok, requer inserção manual)', [
                    'request_id' => $requestId,
                    'tipo_importacao' => $tipoImportacao,
                    'arquivo_nome' => $nomeArquivo,
                    'debug_texto_preview' => $debugTexto ? mb_substr($debugTexto, 0, 2000) : null,
                ]);
            }

            $estrategiaVinculo = EstrategiaVinculoImportacao::REF_SELECAO->value;
            $fornecedorSugerido = is_array($pedido['fornecedor_sugerido'] ?? null)
                ? $pedido['fornecedor_sugerido']
                : [];
            $fornecedorResolvidoXml = $this->resolverFornecedorSugerido($fornecedorSugerido);
            $fornecedorVinculado = $this->resolverFornecedorImportacao(
                $fornecedorSugerido,
                $fornecedorResolvidoXml,
                $arquivo->getClientOriginalName()
            );
            $categoriaSugerida = $this->resolverCategoriaSugeridaImportacao(
                $fornecedorSugerido,
                $fornecedorVinculado,
                $arquivo->getClientOriginalName()
            );
            $opcoesMesclagem = $categoriaSugerida
                ? ['categoria_sugerida' => [
                    'id' => $categoriaSugerida->id,
                    'nome' => $categoriaSugerida->nome,
                ]]
                : [];

            $itens = app(ImportacaoPedidoService::class)
                ->mesclarItensComVariacoes($itens, $estrategiaVinculo, $opcoesMesclagem);

            $cliente = [
                'nome' => $pedido['cliente'] ?? '',
                'documento' => '',
                'email' => '',
                'telefone' => '',
                'endereco' => '',
            ];

            $numeroExtraido = trim((string) ($pedido['numero_pedido'] ?? ''));

            $pedidoFormatado = [
                'numero_externo' => '',
                'id_fornecedor' => $fornecedorVinculado?->id,
                'fornecedor_sugerido' => [
                    'nome' => $fornecedorSugerido['nome'] ?? null,
                    'cnpj' => $this->normalizarDocumentoFornecedor($fornecedorSugerido['cnpj'] ?? null),
                ],
                'data_pedido' => $pedido['data_pedido'] ?? null,
                'data_inclusao' => $pedido['data_inclusao'] ?? null,
                'data_entrega' => $pedido['data_entrega'] ?? null,
                'total' => floatval(str_replace(',', '.', str_replace('.', '', $totais['total_liquido'] ?? '0'))),
                'observacoes' => $pedido['observacoes'] ?? '',
            ];

            $itensExtraidos = $temItens;
            $requerInsercaoManual = !$itensExtraidos;
            $avisos = [];
            if ($requerInsercaoManual && $permitePreviewSemItens) {
                $avisos[] = 'Itens não puderam ser extraídos automaticamente. Insira manualmente.';
            }

            $payload = [
                'tipo_importacao' => $tipoImportacao,
                'estrategia_vinculo' => $estrategiaVinculo,
                'cliente' => $cliente,
                'pedido' => $pedidoFormatado,
                'itens' => $itens,
                'totais' => $totais,
                'itens_extraidos' => $itensExtraidos,
                'requer_insercao_manual' => $requerInsercaoManual,
                'avisos' => $avisos,
                'debug' => $dados['debug'] ?? null,
                'debug_motivo_itens_zero' => $dados['debug_motivo_itens_zero'] ?? null,
            ];

            $importacao = PedidoImportacao::create([
                'arquivo_hash' => $hash,
                'arquivo_nome' => $arquivo->getClientOriginalName(),
                'numero_externo' => null,
                'usuario_id' => auth()->id(),
                'status' => 'extraido',
                'dados_json' => $payload,
                'erro' => null,
            ]);

            Log::info('Importação de pedido - extração concluída', [
                'request_id' => $requestId,
                'etapa' => 'staging',
                'usuario_id' => auth()->id(),
                'importacao_id' => $importacao->id,
                'tipo_importacao' => $tipoImportacao,
                'estrategia_vinculo' => $estrategiaVinculo,
                'itens_total' => count($itens),
                'numero_extraido' => $numeroExtraido ?: null,
                'tempo_ms' => (int) ((microtime(true) - $inicioImportacao) * 1000),
            ]);

            return response()->json([
                'sucesso' => true,
                'mensagem' => 'Arquivo processado com sucesso.',
                'importacao_id' => $importacao->id,
                'dados' => $payload,
            ]);
        } catch (\InvalidArgumentException $e) {
            Log::warning('Importação de pedido - validação/parse', [
                'request_id' => $requestId,
                'tipo_importacao' => $tipoImportacao,
                'mensagem' => $e->getMessage(),
            ]);
            return response()->json([
                'sucesso' => false,
                'mensagem' => $e->getMessage(),
                'erro' => $e->getMessage(),
            ], 422);
        } catch (Exception $e) {
            $hashErro = isset($hash)
                ? $hash
                : hash('sha256', ($request->file('arquivo')?->getClientOriginalName() ?? uniqid()) . '|' . $tipoImportacao . '|' . Str::uuid());

            PedidoImportacao::create([
                'arquivo_hash' => $hashErro,
                'arquivo_nome' => $request->file('arquivo')?->getClientOriginalName(),
                'usuario_id' => auth()->id(),
                'status' => 'erro',
                'erro' => $e->getMessage(),
            ]);

            Log::error('Importação de pedido - erro ao processar', [
                'request_id' => $requestId,
                'etapa' => 'importar',
                'usuario_id' => auth()->id(),
                'tipo_importacao' => $tipoImportacao,
                'arquivo_hash' => $hashErro,
                'mensagem' => $e->getMessage(),
                'tempo_ms' => (int) ((microtime(true) - $inicioImportacao) * 1000),
            ]);

            return response()->json([
                'sucesso' => false,
                'mensagem' => 'Erro ao processar arquivo.',
                'erro' => $e->getMessage(),
            ], 500);
        }
    }

    private function detectarTipoImportacaoXml(?string $path): ?string
    {
        if ($path === null || !is_file($path)) {
            return null;
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        libxml_use_internal_errors(true);
        $loaded = $dom->load($path, LIBXML_NONET);
        libxml_clear_errors();

        if (!$loaded || !$dom->documentElement) {
            return null;
        }

        $root = $dom->documentElement;
        $localName = $root->localName ?: $root->nodeName;

        if (strtoupper($localName) === 'LISTING') {
            return TipoImportacao::PRODUTOS_XML_FORNECEDORES->value;
        }

        if (
            in_array($localName, ['nfeProc', 'NFe'], true)
            && $root->namespaceURI === self::NS_NFE
        ) {
            return TipoImportacao::ADORNOS_XML_NFE->value;
        }

        return null;
    }

    /**
     * Gera PDF de roteiro do pedido.
     */
    public function roteiroPdf(int $pedidoId, Request $request): Response
    {
        // 1) Carrega o básico + statusAtual para decidir o tipo sem puxar consignações/itens
        $pedidoBase = Pedido::with([
            'cliente.enderecoPrincipal', // opcional, mas útil p/ PDF (se quiser)
            'cliente.enderecos',
            'usuario',
            'parceiro',
            'fornecedor',
            'statusAtual',
        ])->findOrFail($pedidoId);
        $enderecoEntrega = ClienteEnderecoPdf::resolverParaPedido(
            $pedidoBase,
            $request->query('cliente_endereco_id')
        );

        // Regra de negócio:
        // - Pedido consignado = status atual consignado (e/ou existe consignação)
        // Eu priorizo statusAtual por ser determinístico e mais barato.
        $status = $pedidoBase->statusAtual?->status?->value ?? $pedidoBase->statusAtual?->status;
        $tipoRoteiro = $this->normalizarTipoRoteiro($request->query('tipo_roteiro'));
        $isConsignado = in_array($status, ['consignado', 'devolucao_consignacao'], true);
        if (!$isConsignado) {
            $isConsignado = $pedidoBase->isConsignado();
        }

        // Caso queira ser "à prova de inconsistência" (status divergente),
        // você pode habilitar esse fallback (roda 1 exists()):
        // if (!$isConsignado) $isConsignado = $pedidoBase->isConsignado();

        $pdfImageService = app(PdfImageService::class);
        Pdf::setOptions(['isRemoteEnabled' => true]);

        // 2) Se for consignado: carrega APENAS consignações e gera o mesmo PDF existente
        if ($isConsignado) {
            $pedido = Pedido::with([
                'cliente.enderecoPrincipal',
                'cliente.enderecos',
                'usuario',
                'parceiro',
                'fornecedor',
                'statusAtual',

                'consignacoes.deposito',
                'consignacoes.produtoVariacao.imagem',
                'consignacoes.produtoVariacao.produto.imagemPrincipal',
                'consignacoes.produtoVariacao.produto',
                'consignacoes.produtoVariacao.atributos',

                // localização
                'consignacoes.produtoVariacao.estoquesComLocalizacao.localizacao.area',
            ])->findOrFail($pedidoId);

            $consignacaoIds = collect((array) $request->query('consignacao_ids', []))
                ->merge((array) $request->query('consignacoes', []))
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values();

            if ($consignacaoIds->isNotEmpty()) {
                $pedido->setRelation(
                    'consignacoes',
                    $pedido->consignacoes->whereIn('id', $consignacaoIds)->values()
                );
            }

            $pedido->consignacoes->each(function ($consignacao) use ($pdfImageService) {
                $consignacao->setAttribute(
                    'pdf_imagem_data_uri',
                    $pdfImageService->fromProdutoVariacaoOrPlaceholder($consignacao->produtoVariacao)
                );
            });

            $grupos = $pedido->consignacoes->groupBy(fn($c) => $c->deposito->nome ?? 'Sem depósito');
            $isDevolucao = $tipoRoteiro
                ? $tipoRoteiro === 'devolucao'
                : $this->isRoteiroConsignacaoDevolucao($pedido);
            $tituloRoteiro = $isDevolucao ? 'Roteiro de devolução' : 'Roteiro de consignação';
            $filename = $isDevolucao
                ? "roteiro-de-devolucao-{$pedidoId}.pdf"
                : "roteiro-de-consignacao-{$pedidoId}.pdf";

            logAuditoria('pedido_pdf', 'Geração de PDF (roteiro consignação via pedidos)', [
                'acao' => 'roteiro_pdf',
                'pedido_id' => $pedidoId,
                'tipo' => 'consignacao',
                'documento' => $tituloRoteiro,
            ]);

            $pdf = Pdf::loadView('exports.roteiro-consignacao', [
                'pedido'     => $pedido,
                'grupos'     => $grupos,
                'geradoEm'   => now('America/Belem')->format('d/m/Y H:i'),
                'tituloRoteiro' => $tituloRoteiro,
                'enderecoEntrega' => $enderecoEntrega,
            ])->setPaper('a4');

            return $pdf->download($filename);
        }

        // 3) Caso contrário: carrega APENAS itens do pedido e gera o template novo
        $pedido = Pedido::with([
            'cliente.enderecoPrincipal',
            'cliente.enderecos',
            'usuario',
            'parceiro',
            'fornecedor',

            'itens.variacao.imagem',
            'itens.variacao.produto.imagemPrincipal',
            'itens.variacao.produto',
            'itens.variacao.atributos',

            // localização
            'itens.variacao.estoquesComLocalizacao.localizacao.area',
        ])->findOrFail($pedidoId);

        $itemIds = collect((array) $request->query('item_ids', []))
            ->merge((array) $request->query('itens', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($itemIds->isNotEmpty()) {
            $pedido->setRelation(
                'itens',
                $pedido->itens->whereIn('id', $itemIds)->values()
            );
        }

        $pedido->itens->each(function ($item) use ($pdfImageService) {
            $item->setAttribute(
                'pdf_imagem_data_uri',
                $pdfImageService->fromProdutoVariacaoOrPlaceholder($item->variacao)
            );
        });

        // Depósitos: temos id_deposito no item, e model Deposito existe.
        $depositoIds = $pedido->itens
            ->pluck('id_deposito')
            ->filter(fn($id) => !is_null($id) && (int)$id > 0)
            ->unique()
            ->values();

        $depositosMap = $depositoIds->isNotEmpty()
            ? Deposito::whereIn('id', $depositoIds)->pluck('nome', 'id')
            : collect();

        $grupos = $pedido->itens->groupBy(function ($item) use ($depositosMap) {
            $id = (int)($item->id_deposito ?? 0);
            if ($id <= 0) return 'Sem depósito';
            return $depositosMap[$id] ?? ("Depósito #{$id}");
        });

        logAuditoria('pedido_pdf', 'Geração de PDF (roteiro pedido)', [
            'acao' => 'roteiro_pdf',
            'pedido_id' => $pedidoId,
            'tipo' => 'pedido',
        ]);

        $pdf = Pdf::loadView('exports.roteiro-pedido', [
            'pedido'     => $pedido,
            'grupos'     => $grupos,
            'geradoEm'   => now('America/Belem')->format('d/m/Y H:i'),
            'enderecoEntrega' => $enderecoEntrega,
        ])->setPaper('a4');

        return $pdf->download("roteiro_pedido_{$pedidoId}.pdf");
    }

    /**
     * Retorna os itens que podem compor uma nota de entrega.
     */
    public function notaEntregaItens(
        int $pedidoId,
        EstoqueDisponibilidadeService $disponibilidade,
        EntregaProdutoService $entregaService
    ): JsonResponse
    {
        $pedido = Pedido::with('cliente.enderecos')->findOrFail($pedidoId);

        if (
            $pedido->isVenda()
            && ! ProdutoEntregaItem::query()->where('pedido_id', $pedido->id)->exists()
        ) {
            $usuarioId = auth()->id();
            $entregaService->criarDemandaPedido($pedido, $usuarioId ? (int) $usuarioId : null, false);
        }

        $queryBase = fn () => ProdutoEntregaItem::query()
            ->with([
                'pedidoItem',
                'variacao.imagem',
                'variacao.produto.imagemPrincipal',
                'variacao.produto',
                'variacao.atributos',
                'depositoOrigem:id,nome',
                'depositoDestino:id,nome',
            ])
            ->where('pedido_id', $pedido->id);

        $itens = $queryBase()
            ->whereNotIn('status', [
                ProdutoEntregaItem::STATUS_CANCELADO,
                ProdutoEntregaItem::STATUS_RECEBIDO,
                ProdutoEntregaItem::STATUS_ENTREGUE,
            ])
            ->whereColumn('quantidade_entregue', '<', 'quantidade_total')
            ->orderBy('id')
            ->get()
            ->map(fn (ProdutoEntregaItem $item) => $this->formatarItemNotaEntrega($item, $disponibilidade))
            ->values();

        if ($itens->isEmpty()) {
            $itens = $queryBase()
                ->where('status', ProdutoEntregaItem::STATUS_ENTREGUE)
                ->where('quantidade_entregue', '>', 0)
                ->orderBy('id')
                ->get()
                ->map(fn (ProdutoEntregaItem $item) => $this->formatarItemNotaEntrega($item, $disponibilidade, 'reimpressao'))
                ->values();
        }

        return response()->json([
            'data' => $itens,
            'cliente_enderecos' => ClienteEnderecoPdf::paraResposta($pedido->cliente),
        ]);
    }

    /**
     * Gera nota de entrega para o cliente e, opcionalmente, registra expedição e entrega central.
     */
    public function notaEntregaPdf(
        int $pedidoId,
        Request $request,
        EntregaProdutoService $entregaService,
        EstoqueDisponibilidadeService $disponibilidade
    ): Response {
        $data = $request->validate([
            'registrar_entrega' => ['sometimes', 'boolean'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'observacao' => ['nullable', 'string', 'max:1000'],
            'cliente_endereco_id' => ['nullable', 'integer'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.produto_entrega_item_id' => ['required', 'integer'],
            'itens.*.quantidade' => ['nullable', 'integer', 'min:1'],
            'itens.*.entregar_expedido' => ['nullable', 'integer', 'min:0'],
            'itens.*.alocacoes' => ['nullable', 'array'],
            'itens.*.alocacoes.*.deposito_id' => ['required', 'integer', 'exists:depositos,id'],
            'itens.*.alocacoes.*.quantidade' => ['required', 'integer', 'min:1'],
        ]);

        $registrarEntrega = (bool) ($data['registrar_entrega'] ?? false);
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));

        if ($registrarEntrega && $idempotencyKey === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Informe a chave de idempotencia para registrar a entrega.'],
            ]);
        }

        $pedido = Pedido::with([
            'cliente.enderecoPrincipal',
            'cliente.enderecos',
            'usuario',
            'parceiro',
            'fornecedor',
        ])->findOrFail($pedidoId);
        $enderecoEntrega = ClienteEnderecoPdf::resolverParaPedido(
            $pedido,
            $data['cliente_endereco_id'] ?? null
        );

        $selecionados = $this->normalizarItensNotaEntrega($data['itens']);

        if ($selecionados->isEmpty()) {
            throw ValidationException::withMessages([
                'itens' => ['Selecione ao menos um item com quantidade para a nota de entrega.'],
            ]);
        }

        $entregas = ProdutoEntregaItem::query()
            ->with([
                'pedidoItem',
                'variacao.imagem',
                'variacao.produto.imagemPrincipal',
                'variacao.produto',
                'variacao.atributos',
            ])
            ->where('pedido_id', $pedido->id)
            ->whereIn('id', $selecionados->pluck('id'))
            ->get()
            ->keyBy('id');

        if ($entregas->count() !== $selecionados->count()) {
            throw ValidationException::withMessages([
                'itens' => ['Selecione apenas itens de entrega vinculados a este pedido.'],
            ]);
        }

        $notaItens = $this->validarItensNotaEntrega(
            $selecionados,
            $entregas,
            $registrarEntrega,
            $idempotencyKey,
            $disponibilidade
        );

        $pdfImageService = app(PdfImageService::class);
        $notaItens->each(function (ProdutoEntregaItem $item) use ($pdfImageService) {
            $item->setAttribute(
                'pdf_imagem_data_uri',
                $pdfImageService->fromProdutoVariacaoOrPlaceholder($item->variacao)
            );
        });

        Pdf::setOptions(['isRemoteEnabled' => true]);
        $pdfOutput = Pdf::loadView('exports.nota-entrega-pedido', [
            'pedido' => $pedido,
            'itens' => $notaItens,
            'geradoEm' => now('America/Belem')->format('d/m/Y H:i'),
            'observacaoNota' => $data['observacao'] ?? null,
            'registrarEntrega' => $registrarEntrega,
            'enderecoEntrega' => $enderecoEntrega,
        ])->setPaper('a4')->output();

        if ($registrarEntrega) {
            DB::transaction(function () use ($notaItens, $entregaService, $idempotencyKey, $data) {
                $notaItens->each(function (ProdutoEntregaItem $item) use ($entregaService, $idempotencyKey, $data) {
                    $entregarExpedido = (int) $item->getAttribute('nota_entregar_expedido');
                    $entregarExpedidoRegistrado = (bool) $item->getAttribute('nota_entregar_expedido_registrado');

                    if ($entregarExpedido > 0 && ! $entregarExpedidoRegistrado) {
                        $entregaService->entregarItem(
                            $item,
                            $entregarExpedido,
                            auth()->id(),
                            $data['observacao'] ?? 'Entrega registrada via nota de entrega.',
                            $this->notaEntregaEventoKey($idempotencyKey, (int) $item->id)
                        );
                    }

                    foreach ((array) $item->getAttribute('nota_alocacoes') as $alocacao) {
                        if (! empty($alocacao['entrega_registrada'])) {
                            continue;
                        }

                        $depositoId = (int) $alocacao['deposito_id'];
                        $quantidade = (int) $alocacao['quantidade'];

                        if (empty($alocacao['expedicao_registrada'])) {
                            $entregaService->expedirItem(
                                $item,
                                $depositoId,
                                $quantidade,
                                auth()->id(),
                                $data['observacao'] ?? 'Expedicao registrada via nota de entrega.',
                                ProdutoEntregaEvento::EXPEDIDO_CLIENTE,
                                $this->notaEntregaEventoKey($idempotencyKey, (int) $item->id, 'expedir', $depositoId)
                            );
                        }

                        $entregaService->entregarItem(
                            $item,
                            $quantidade,
                            auth()->id(),
                            $data['observacao'] ?? 'Entrega registrada via nota de entrega.',
                            $this->notaEntregaEventoKey($idempotencyKey, (int) $item->id, 'entregar', $depositoId)
                        );
                    }
                });
            });
        }

        logAuditoria('pedido_pdf', 'Geração de PDF (nota de entrega)', [
            'acao' => 'nota_entrega_pdf',
            'pedido_id' => $pedido->id,
            'registrar_entrega' => $registrarEntrega,
            'itens' => $notaItens->map(fn (ProdutoEntregaItem $item) => [
                'produto_entrega_item_id' => $item->id,
                'pedido_item_id' => $item->pedido_item_id,
                'quantidade' => (int) $item->getAttribute('nota_quantidade'),
                'entregar_expedido' => (int) $item->getAttribute('nota_entregar_expedido'),
                'alocacoes' => $item->getAttribute('nota_alocacoes') ?? [],
            ])->values()->all(),
        ]);

        $filename = "nota-entrega-pedido-{$pedido->id}.pdf";

        return response($pdfOutput, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function formatarItemNotaEntrega(
        ProdutoEntregaItem $item,
        EstoqueDisponibilidadeService $disponibilidade,
        string $modoNota = 'pendente'
    ): array {
        $pendenteTotal = max(0, (int) $item->quantidade_total - (int) $item->quantidade_entregue);
        $pendenteExpedido = max(0, (int) $item->quantidade_expedida - (int) $item->quantidade_entregue);
        $pendenteExpedicao = max(0, (int) $item->quantidade_total - (int) $item->quantidade_expedida);
        $reimpressao = $modoNota === 'reimpressao';

        return [
            'id' => $item->id,
            'modo_nota' => $modoNota,
            'pode_registrar_entrega' => ! $reimpressao,
            'tipo_origem' => $item->tipo_origem,
            'origem_id' => $item->origem_id,
            'pedido_id' => $item->pedido_id,
            'pedido_item_id' => $item->pedido_item_id,
            'id_variacao' => $item->id_variacao,
            'quantidade_total' => (int) $item->quantidade_total,
            'quantidade_reservada' => (int) $item->quantidade_reservada,
            'quantidade_expedida' => (int) $item->quantidade_expedida,
            'quantidade_entregue' => (int) $item->quantidade_entregue,
            'quantidade_pendente_total' => $pendenteTotal,
            'quantidade_pendente_entrega' => $pendenteExpedido,
            'quantidade_pendente_expedicao_nota' => $pendenteExpedicao,
            'quantidade_reimpressao' => $reimpressao ? (int) $item->quantidade_entregue : null,
            'id_deposito_origem' => $item->id_deposito_origem,
            'id_deposito_destino' => $item->id_deposito_destino,
            'status' => $item->status,
            'pedido_item' => $item->pedidoItem,
            'variacao' => $item->variacao,
            'deposito_origem' => $item->depositoOrigem,
            'deposito_destino' => $item->depositoDestino,
            'depositos_disponiveis' => $this->depositosDisponiveisNotaEntrega(
                $item,
                $disponibilidade,
                $pendenteExpedicao
            ),
        ];
    }

    private function normalizarItensNotaEntrega(array $itens): Collection
    {
        return collect($itens)
            ->map(function (array $item) {
                $alocacoes = collect((array) ($item['alocacoes'] ?? []))
                    ->map(fn (array $alocacao) => [
                        'deposito_id' => (int) ($alocacao['deposito_id'] ?? 0),
                        'quantidade' => (int) ($alocacao['quantidade'] ?? 0),
                    ])
                    ->filter(fn (array $alocacao) => $alocacao['deposito_id'] > 0 && $alocacao['quantidade'] > 0)
                    ->groupBy('deposito_id')
                    ->map(fn (Collection $grupo, int $depositoId) => [
                        'deposito_id' => $depositoId,
                        'quantidade' => (int) $grupo->sum('quantidade'),
                    ])
                    ->values();

                $entregarExpedido = array_key_exists('entregar_expedido', $item)
                    ? (int) ($item['entregar_expedido'] ?? 0)
                    : 0;
                $quantidade = array_key_exists('quantidade', $item)
                    ? max(0, (int) ($item['quantidade'] ?? 0))
                    : null;

                if ($entregarExpedido <= 0 && $alocacoes->isEmpty() && array_key_exists('quantidade', $item)) {
                    $entregarExpedido = (int) ($item['quantidade'] ?? 0);
                }

                return [
                    'id' => (int) ($item['produto_entrega_item_id'] ?? 0),
                    'quantidade' => $quantidade,
                    'entregar_expedido' => max(0, $entregarExpedido),
                    'alocacoes' => $alocacoes,
                ];
            })
            ->filter(fn (array $item) => $item['id'] > 0)
            ->groupBy('id')
            ->map(function (Collection $grupo, int $id) {
                $quantidades = $grupo
                    ->pluck('quantidade')
                    ->filter(fn ($quantidade) => $quantidade !== null);

                return [
                    'id' => $id,
                    'quantidade' => $quantidades->isEmpty() ? null : (int) $quantidades->sum(),
                    'entregar_expedido' => (int) $grupo->sum('entregar_expedido'),
                    'alocacoes' => $grupo
                        ->flatMap(fn (array $item) => $item['alocacoes'])
                        ->groupBy('deposito_id')
                        ->map(fn (Collection $alocacoes, int $depositoId) => [
                            'deposito_id' => $depositoId,
                            'quantidade' => (int) $alocacoes->sum('quantidade'),
                        ])
                        ->values(),
                ];
            })
            ->values();
    }

    private function validarItensNotaEntrega(
        Collection $selecionados,
        Collection $entregas,
        bool $registrarEntrega,
        string $idempotencyKey,
        EstoqueDisponibilidadeService $disponibilidade
    ): Collection {
        $itens = collect();

        foreach ($selecionados as $selecionado) {
            /** @var ProdutoEntregaItem $item */
            $item = $entregas->get($selecionado['id']);
            $selecaoJaRegistrada = $registrarEntrega
                && $this->notaEntregaSelecaoJaRegistrada($item, $selecionado, $idempotencyKey);
            $reimpressao = $item
                ? $this->isReimpressaoNotaEntrega($item, $registrarEntrega, $selecaoJaRegistrada)
                : false;
            $bloqueio = $this->validarItemSelecionavelNotaEntrega($item, $selecaoJaRegistrada || $reimpressao);

            if ($bloqueio !== null) {
                throw ValidationException::withMessages(['itens' => [$bloqueio]]);
            }

            if ($reimpressao) {
                $quantidadeReimpressao = (int) ($selecionado['quantidade'] ?? $item->quantidade_entregue);
                $quantidadeEntregue = (int) $item->quantidade_entregue;

                if ($quantidadeReimpressao <= 0) {
                    throw ValidationException::withMessages([
                        'itens' => ["Informe uma quantidade para reimprimir o item de entrega #{$item->id}."],
                    ]);
                }

                if ($quantidadeReimpressao > $quantidadeEntregue) {
                    throw ValidationException::withMessages([
                        'itens' => ["A quantidade de reimpressao do item de entrega #{$item->id} excede o total entregue ({$quantidadeEntregue})."],
                    ]);
                }

                $item->setAttribute('nota_quantidade', $quantidadeReimpressao);
                $item->setAttribute('nota_entregar_expedido', 0);
                $item->setAttribute('nota_entregar_expedido_registrado', false);
                $item->setAttribute('nota_alocacoes', []);
                $item->setAttribute('nota_modo', 'reimpressao');
                $itens->push($item);

                continue;
            }

            $entregarExpedidoSolicitado = (int) ($selecionado['entregar_expedido'] ?? 0);
            $entregarExpedidoRegistrado = false;
            $eventoEntregaExpedido = $registrarEntrega && $entregarExpedidoSolicitado > 0
                ? ProdutoEntregaEvento::query()
                    ->where('idempotency_key', $this->notaEntregaEventoKey($idempotencyKey, (int) $item->id))
                    ->first()
                : null;

            if ($eventoEntregaExpedido) {
                $entregarExpedido = (int) $eventoEntregaExpedido->quantidade;
                $entregarExpedidoNovo = 0;
                $entregarExpedidoRegistrado = true;
            } else {
                $entregarExpedido = $entregarExpedidoSolicitado;
                $entregarExpedidoNovo = $entregarExpedido;
            }

            $pendenteTotal = max(0, (int) $item->quantidade_total - (int) $item->quantidade_entregue);
            $pendenteExpedido = max(0, (int) $item->quantidade_expedida - (int) $item->quantidade_entregue);

            if ($entregarExpedidoNovo > $pendenteExpedido) {
                throw ValidationException::withMessages([
                    'itens' => ["A quantidade ja expedida do item de entrega #{$item->id} excede o pendente de entrega ({$pendenteExpedido})."],
                ]);
            }

            $alocacoesEfetivas = collect();
            $alocacoesParaValidarSaldo = collect();
            $quantidadeAindaNaoEntregue = $entregarExpedidoNovo;

            foreach (collect($selecionado['alocacoes'] ?? []) as $alocacao) {
                $depositoId = (int) ($alocacao['deposito_id'] ?? 0);
                $quantidade = (int) ($alocacao['quantidade'] ?? 0);

                if ($depositoId <= 0 || $quantidade <= 0) {
                    continue;
                }

                $eventoEntrega = $registrarEntrega
                    ? ProdutoEntregaEvento::query()
                        ->where('idempotency_key', $this->notaEntregaEventoKey($idempotencyKey, (int) $item->id, 'entregar', $depositoId))
                        ->first()
                    : null;
                $eventoExpedicao = $registrarEntrega
                    ? ProdutoEntregaEvento::query()
                        ->where('idempotency_key', $this->notaEntregaEventoKey($idempotencyKey, (int) $item->id, 'expedir', $depositoId))
                        ->first()
                    : null;

                $quantidadeEfetiva = $eventoEntrega
                    ? (int) $eventoEntrega->quantidade
                    : ($eventoExpedicao ? (int) $eventoExpedicao->quantidade : $quantidade);

                $alocacoesEfetivas->push([
                    'deposito_id' => $depositoId,
                    'quantidade' => $quantidadeEfetiva,
                    'expedicao_registrada' => (bool) $eventoExpedicao,
                    'entrega_registrada' => (bool) $eventoEntrega,
                ]);

                if (! $eventoEntrega) {
                    $quantidadeAindaNaoEntregue += $quantidadeEfetiva;
                }

                if (! $eventoExpedicao && ! $eventoEntrega) {
                    $alocacoesParaValidarSaldo->push([
                        'deposito_id' => $depositoId,
                        'quantidade' => $quantidadeEfetiva,
                    ]);
                }
            }

            if ($quantidadeAindaNaoEntregue > $pendenteTotal) {
                throw ValidationException::withMessages([
                    'itens' => ["A quantidade do item de entrega #{$item->id} excede o pendente total de entrega ({$pendenteTotal})."],
                ]);
            }

            if ($alocacoesParaValidarSaldo->isNotEmpty()) {
                $alocacoesOrdenadas = $this->validarAlocacoesNotaEntrega(
                    $item,
                    $alocacoesParaValidarSaldo,
                    $disponibilidade
                );
                $ordem = $alocacoesOrdenadas->pluck('deposito_id')->values()->all();

                $alocacoesEfetivas = $alocacoesEfetivas
                    ->sortBy(fn (array $alocacao) => array_search($alocacao['deposito_id'], $ordem, true) === false
                        ? PHP_INT_MAX
                        : array_search($alocacao['deposito_id'], $ordem, true))
                    ->values();
            }

            $quantidadeTotalNota = $entregarExpedido + (int) $alocacoesEfetivas->sum('quantidade');

            if ($quantidadeTotalNota <= 0) {
                throw ValidationException::withMessages([
                    'itens' => ["Informe uma quantidade para o item de entrega #{$item->id}."],
                ]);
            }

            $item->setAttribute('nota_quantidade', $quantidadeTotalNota);
            $item->setAttribute('nota_entregar_expedido', $entregarExpedido);
            $item->setAttribute('nota_entregar_expedido_registrado', $entregarExpedidoRegistrado);
            $item->setAttribute('nota_alocacoes', $alocacoesEfetivas->values()->all());
            $itens->push($item);
        }

        return $itens;
    }

    private function isReimpressaoNotaEntrega(
        ProdutoEntregaItem $item,
        bool $registrarEntrega,
        bool $selecaoJaRegistrada
    ): bool {
        return ! $registrarEntrega
            && ! $selecaoJaRegistrada
            && $item->status === ProdutoEntregaItem::STATUS_ENTREGUE
            && (int) $item->quantidade_entregue > 0;
    }

    private function notaEntregaSelecaoJaRegistrada(
        ?ProdutoEntregaItem $item,
        array $selecionado,
        string $idempotencyKey
    ): bool {
        if (! $item || $idempotencyKey === '') {
            return false;
        }

        $temQuantidade = false;
        $entregarExpedido = (int) ($selecionado['entregar_expedido'] ?? 0);

        if ($entregarExpedido > 0) {
            $temQuantidade = true;

            if (! ProdutoEntregaEvento::query()
                ->where('idempotency_key', $this->notaEntregaEventoKey($idempotencyKey, (int) $item->id))
                ->exists()) {
                return false;
            }
        }

        foreach (collect($selecionado['alocacoes'] ?? []) as $alocacao) {
            $depositoId = (int) ($alocacao['deposito_id'] ?? 0);
            $quantidade = (int) ($alocacao['quantidade'] ?? 0);

            if ($depositoId <= 0 || $quantidade <= 0) {
                continue;
            }

            $temQuantidade = true;

            if (! ProdutoEntregaEvento::query()
                ->where('idempotency_key', $this->notaEntregaEventoKey($idempotencyKey, (int) $item->id, 'entregar', $depositoId))
                ->exists()) {
                return false;
            }
        }

        return $temQuantidade;
    }

    private function validarItemSelecionavelNotaEntrega(
        ?ProdutoEntregaItem $item,
        bool $permitirEntregueRegistrado = false
    ): ?string
    {
        if (! $item) {
            return 'Item de entrega nao encontrado.';
        }

        if (in_array($item->status, [
            ProdutoEntregaItem::STATUS_CANCELADO,
            ProdutoEntregaItem::STATUS_RECEBIDO,
        ], true)) {
            return "O item de entrega #{$item->id} nao pode compor nota de entrega.";
        }

        if ($item->status === ProdutoEntregaItem::STATUS_ENTREGUE && ! $permitirEntregueRegistrado) {
            return "O item de entrega #{$item->id} nao pode compor nota de entrega.";
        }

        $pendente = max(0, (int) $item->quantidade_total - (int) $item->quantidade_entregue);
        if ($pendente <= 0 && ! $permitirEntregueRegistrado) {
            return "O item de entrega #{$item->id} nao possui quantidade pendente de entrega.";
        }

        return null;
    }

    private function validarAlocacoesNotaEntrega(
        ProdutoEntregaItem $item,
        Collection $alocacoes,
        EstoqueDisponibilidadeService $disponibilidade
    ): Collection {
        $pendenteExpedicao = max(0, (int) $item->quantidade_total - (int) $item->quantidade_expedida);
        $depositos = collect($this->depositosDisponiveisNotaEntrega($item, $disponibilidade, $pendenteExpedicao))
            ->keyBy('id');

        foreach ($alocacoes as $alocacao) {
            $depositoId = (int) $alocacao['deposito_id'];
            $quantidade = (int) $alocacao['quantidade'];
            $deposito = $depositos->get($depositoId);

            if (! $deposito) {
                throw ValidationException::withMessages([
                    'itens' => ["O deposito #{$depositoId} nao possui saldo utilizavel para o item de entrega #{$item->id}."],
                ]);
            }

            $maximo = (int) ($deposito['quantidade_utilizavel'] ?? 0);
            if ($quantidade > $maximo) {
                throw ValidationException::withMessages([
                    'itens' => ["A quantidade do deposito {$deposito['nome']} excede o saldo utilizavel ({$maximo})."],
                ]);
            }
        }

        $reservasPorDeposito = $this->reservasAtivasNotaEntrega($item)
            ->groupBy('id_deposito')
            ->map(fn (Collection $reservas) => (int) $reservas->sum(fn (EstoqueReserva $reserva) => $reserva->pendente()));
        $reservaTotal = (int) $reservasPorDeposito->sum();
        $totalAlocado = (int) $alocacoes->sum('quantidade');
        $reservadoAlocado = (int) $alocacoes
            ->filter(fn (array $alocacao) => $reservasPorDeposito->has($alocacao['deposito_id']))
            ->sum('quantidade');
        $foraReserva = (int) $alocacoes
            ->reject(fn (array $alocacao) => $reservasPorDeposito->has($alocacao['deposito_id']))
            ->sum('quantidade');

        if ($reservaTotal > 0 && $foraReserva > 0 && $reservadoAlocado < min($reservaTotal, $totalAlocado)) {
            throw ValidationException::withMessages([
                'itens' => ["Use primeiro a reserva existente do item de entrega #{$item->id} antes de expedir por outro deposito."],
            ]);
        }

        return $alocacoes
            ->sortBy(fn (array $alocacao) => $reservasPorDeposito->has($alocacao['deposito_id']) ? 0 : 1)
            ->values();
    }

    private function depositosDisponiveisNotaEntrega(
        ProdutoEntregaItem $item,
        EstoqueDisponibilidadeService $disponibilidade,
        int $pendenteExpedicao
    ): array {
        if ($pendenteExpedicao <= 0 || ! $item->id_variacao) {
            return [];
        }

        $reservasPorDeposito = $this->reservasAtivasNotaEntrega($item)
            ->filter(fn (EstoqueReserva $reserva) => (int) $reserva->id_deposito > 0)
            ->groupBy('id_deposito')
            ->map(fn (Collection $reservas) => (int) $reservas->sum(fn (EstoqueReserva $reserva) => $reserva->pendente()));

        $depositoIds = Estoque::query()
            ->where('id_variacao', $item->id_variacao)
            ->where('quantidade', '>', 0)
            ->pluck('id_deposito')
            ->merge($reservasPorDeposito->keys())
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($depositoIds->isEmpty()) {
            return [];
        }

        $nomes = Deposito::query()
            ->whereIn('id', $depositoIds)
            ->pluck('nome', 'id');

        return $depositoIds
            ->map(function (int $depositoId) use ($item, $disponibilidade, $pendenteExpedicao, $reservasPorDeposito, $nomes) {
                $disponivel = max(0, $disponibilidade->getDisponivel((int) $item->id_variacao, $depositoId));
                $reservado = (int) ($reservasPorDeposito[$depositoId] ?? 0);
                $utilizavel = max(0, $disponivel + $reservado);

                if ($utilizavel <= 0) {
                    return null;
                }

                return [
                    'id' => $depositoId,
                    'nome' => $nomes[$depositoId] ?? "Deposito #{$depositoId}",
                    'disponivel' => $disponivel,
                    'reservado' => $reservado,
                    'quantidade_utilizavel' => min($utilizavel, $pendenteExpedicao),
                    'is_reserva' => $reservado > 0,
                ];
            })
            ->filter()
            ->sortBy(fn (array $deposito) => ($deposito['is_reserva'] ? '0' : '1') . '|' . $deposito['nome'])
            ->values()
            ->all();
    }

    private function reservasAtivasNotaEntrega(ProdutoEntregaItem $item): Collection
    {
        return EstoqueReserva::query()
            ->where('id_variacao', $item->id_variacao)
            ->where('status', 'ativa')
            ->where(function ($q) {
                $q->whereNull('data_expira')
                    ->orWhere('data_expira', '>', now());
            })
            ->when($item->pedido_id, fn ($q) => $q->where('pedido_id', $item->pedido_id))
            ->when($item->pedido_item_id, fn ($q) => $q->where('pedido_item_id', $item->pedido_item_id))
            ->get();
    }

    private function notaEntregaEventoKey(
        string $idempotencyKey,
        int $itemId,
        string $acao = 'entregar',
        ?int $depositoId = null
    ): string {
        $base = "nota-entrega:{$idempotencyKey}:item:{$itemId}";

        if ($depositoId) {
            $base .= ":deposito:{$depositoId}";
        }

        return "{$base}:{$acao}";
    }

    private function resolverCategoriaSugeridaImportacao(
        array $fornecedorSugerido,
        ?Fornecedor $fornecedorVinculado,
        ?string $nomeArquivo
    ): ?Categoria {
        if (!$this->isContextoFornecedorAvanti($fornecedorSugerido, $fornecedorVinculado, $nomeArquivo)) {
            return null;
        }

        $categoria = Categoria::query()
            ->where('nome', self::CATEGORIA_SUGERIDA_AVANTI)
            ->first();

        if (!$categoria) {
            Log::info('Importacao de pedido - categoria sugerida Avanti indisponivel', [
                'categoria_sugerida' => self::CATEGORIA_SUGERIDA_AVANTI,
                'fornecedor_sugerido' => $fornecedorSugerido['nome'] ?? null,
                'fornecedor_vinculado_id' => $fornecedorVinculado?->id,
                'arquivo_nome' => $nomeArquivo,
            ]);
        }

        return $categoria;
    }

    private function isContextoFornecedorAvanti(
        array $fornecedorSugerido,
        ?Fornecedor $fornecedorVinculado,
        ?string $nomeArquivo
    ): bool {
        $documentos = [
            $fornecedorSugerido['cnpj'] ?? null,
            $fornecedorVinculado?->cnpj,
        ];

        foreach ($documentos as $documento) {
            $normalizado = $this->normalizarDocumentoFornecedor($documento);
            if ($normalizado && in_array($normalizado, self::FORNECEDOR_AVANTI_CNPJS, true)) {
                return true;
            }
        }

        $nomes = [
            $fornecedorSugerido['nome'] ?? null,
            $fornecedorVinculado?->nome,
            $nomeArquivo,
        ];

        foreach ($nomes as $nome) {
            $normalizado = $this->normalizarNomeFornecedor($nome);
            if (
                $normalizado
                && (
                    str_contains(" {$normalizado} ", ' ' . self::FORNECEDOR_AVANTI_TOKEN . ' ')
                    || $this->isRazaoSocialAvanti($normalizado)
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function isRazaoSocialAvanti(string $nomeNormalizado): bool
    {
        foreach (self::FORNECEDOR_AVANTI_RAZOES_SOCIAIS as $razaoSocial) {
            if (str_contains(" {$nomeNormalizado} ", " {$razaoSocial} ")) {
                return true;
            }
        }

        return false;
    }

    private function resolverFornecedorImportacao(
        array $fornecedorSugerido,
        ?Fornecedor $fornecedorResolvidoXml,
        ?string $nomeArquivo
    ): ?Fornecedor {
        if ($this->isContextoFornecedorAvanti($fornecedorSugerido, $fornecedorResolvidoXml, $nomeArquivo)) {
            return $this->resolverFornecedorAvantiExistente($fornecedorSugerido, $fornecedorResolvidoXml, $nomeArquivo);
        }

        if ($fornecedorResolvidoXml) {
            return $fornecedorResolvidoXml;
        }

        return $this->resolverFornecedorPorNomeArquivo($fornecedorSugerido, $nomeArquivo);
    }

    private function resolverFornecedorAvantiExistente(
        array $fornecedorSugerido,
        ?Fornecedor $fornecedorResolvidoXml,
        ?string $nomeArquivo
    ): ?Fornecedor {
        $fornecedoresAtivos = Fornecedor::query()
            ->where('status', 1)
            ->get();

        $matchesExatos = $fornecedoresAtivos
            ->filter(fn (Fornecedor $fornecedor) => $this->normalizarNomeFornecedor($fornecedor->nome) === self::FORNECEDOR_AVANTI_TOKEN)
            ->values();

        if ($matchesExatos->count() === 1) {
            return $matchesExatos->first();
        }

        if ($matchesExatos->count() > 1) {
            $this->logFornecedorAvantiNaoSelecionado(
                'multiplos_matches_exatos',
                $fornecedorSugerido,
                $fornecedorResolvidoXml,
                $nomeArquivo,
                $matchesExatos->pluck('id')->all()
            );

            return null;
        }

        $matchesPorToken = $fornecedoresAtivos
            ->filter(function (Fornecedor $fornecedor) {
                $normalizado = $this->normalizarNomeFornecedor($fornecedor->nome);
                return $normalizado && str_contains(" {$normalizado} ", ' ' . self::FORNECEDOR_AVANTI_TOKEN . ' ');
            })
            ->values();

        if ($matchesPorToken->count() === 1) {
            return $matchesPorToken->first();
        }

        $this->logFornecedorAvantiNaoSelecionado(
            $matchesPorToken->isEmpty() ? 'nao_encontrado' : 'multiplos_matches_por_token',
            $fornecedorSugerido,
            $fornecedorResolvidoXml,
            $nomeArquivo,
            $matchesPorToken->pluck('id')->all()
        );

        return null;
    }

    private function logFornecedorAvantiNaoSelecionado(
        string $motivo,
        array $fornecedorSugerido,
        ?Fornecedor $fornecedorResolvidoXml,
        ?string $nomeArquivo,
        array $fornecedorIds
    ): void {
        Log::info('Importacao de pedido - fornecedor Avanti nao selecionado automaticamente', [
            'motivo' => $motivo,
            'fornecedor_sugerido' => $fornecedorSugerido['nome'] ?? null,
            'fornecedor_resolvido_xml_id' => $fornecedorResolvidoXml?->id,
            'arquivo_nome' => $nomeArquivo,
            'fornecedor_ids_candidatos' => $fornecedorIds,
        ]);
    }

    private function resolverFornecedorSugerido(array $fornecedorSugerido): ?Fornecedor
    {
        $cnpj = $this->normalizarDocumentoFornecedor($fornecedorSugerido['cnpj'] ?? null);
        if ($cnpj) {
            $porCnpj = Fornecedor::query()
                ->where('status', 1)
                ->where('cnpj', $cnpj)
                ->get();

            if ($porCnpj->count() === 1) {
                return $porCnpj->first();
            }

            if ($porCnpj->count() > 1) {
                $this->logFornecedorNaoSelecionado(
                    'cnpj_ambiguo',
                    $fornecedorSugerido,
                    null,
                    $porCnpj->pluck('id')->all()
                );

                return null;
            }
        }

        $nomeNormalizado = $this->normalizarNomeFornecedor($fornecedorSugerido['nome'] ?? null);
        if (!$nomeNormalizado) {
            return null;
        }

        $fornecedoresAtivos = Fornecedor::query()
            ->where('status', 1)
            ->get();

        $matchesExatos = $fornecedoresAtivos
            ->filter(fn (Fornecedor $fornecedor) => $this->normalizarNomeFornecedor($fornecedor->nome) === $nomeNormalizado)
            ->values();

        if ($matchesExatos->count() === 1) {
            return $matchesExatos->first();
        }

        if ($matchesExatos->count() > 1) {
            $this->logFornecedorNaoSelecionado(
                'nome_estruturado_exato_ambiguo',
                $fornecedorSugerido,
                null,
                $matchesExatos->pluck('id')->all()
            );

            return null;
        }

        $matchesPorNome = $this->filtrarFornecedoresPorTexto($fornecedoresAtivos, $nomeNormalizado);
        if ($matchesPorNome->count() === 1) {
            return $matchesPorNome->first();
        }

        if ($matchesPorNome->count() > 1) {
            $this->logFornecedorNaoSelecionado(
                'nome_estruturado_ambiguo',
                $fornecedorSugerido,
                null,
                $matchesPorNome->pluck('id')->all()
            );
        }

        $matchesPorToken = $this->filtrarFornecedoresPorPrimeiroToken($fornecedoresAtivos, $nomeNormalizado);
        if ($matchesPorToken->count() === 1) {
            return $matchesPorToken->first();
        }

        if ($matchesPorToken->count() > 1) {
            $this->logFornecedorNaoSelecionado(
                'nome_estruturado_token_ambiguo',
                $fornecedorSugerido,
                null,
                $matchesPorToken->pluck('id')->all()
            );
        }

        return null;
    }

    private function resolverFornecedorPorNomeArquivo(array $fornecedorSugerido, ?string $nomeArquivo): ?Fornecedor
    {
        $nomeArquivoNormalizado = $this->normalizarNomeFornecedor($nomeArquivo);
        if (!$nomeArquivoNormalizado) {
            return null;
        }

        $fornecedoresAtivos = Fornecedor::query()
            ->where('status', 1)
            ->get();

        $matchesPorNomeCompleto = $fornecedoresAtivos
            ->filter(function (Fornecedor $fornecedor) use ($nomeArquivoNormalizado) {
                $fornecedorNormalizado = $this->normalizarNomeFornecedor($fornecedor->nome);
                if (!$fornecedorNormalizado) {
                    return false;
                }

                return str_contains(" {$nomeArquivoNormalizado} ", " {$fornecedorNormalizado} ");
            })
            ->values();

        if ($matchesPorNomeCompleto->count() === 1) {
            return $matchesPorNomeCompleto->first();
        }

        if ($matchesPorNomeCompleto->count() > 1) {
            $this->logFornecedorNaoSelecionado(
                'nome_arquivo_ambiguo',
                $fornecedorSugerido,
                $nomeArquivo,
                $matchesPorNomeCompleto->pluck('id')->all()
            );

            return null;
        }

        $matchesPorToken = $fornecedoresAtivos
            ->filter(function (Fornecedor $fornecedor) use ($nomeArquivoNormalizado) {
                $fornecedorNormalizado = $this->normalizarNomeFornecedor($fornecedor->nome);
                if (!$fornecedorNormalizado) {
                    return false;
                }

                return str_contains(" {$nomeArquivoNormalizado} ", ' ' . $this->primeiroTokenFornecedor($fornecedorNormalizado) . ' ');
            })
            ->values();

        if ($matchesPorToken->count() === 1) {
            return $matchesPorToken->first();
        }

        $this->logFornecedorNaoSelecionado(
            $matchesPorToken->isEmpty() ? 'nome_arquivo_nao_encontrado' : 'nome_arquivo_token_ambiguo',
            $fornecedorSugerido,
            $nomeArquivo,
            $matchesPorToken->pluck('id')->all()
        );

        return null;
    }

    private function filtrarFornecedoresPorTexto(Collection $fornecedores, string $textoNormalizado): Collection
    {
        return $fornecedores
            ->filter(function (Fornecedor $fornecedor) use ($textoNormalizado) {
                $fornecedorNormalizado = $this->normalizarNomeFornecedor($fornecedor->nome);
                if (!$fornecedorNormalizado) {
                    return false;
                }

                return str_contains(" {$fornecedorNormalizado} ", " {$textoNormalizado} ")
                    || str_contains(" {$textoNormalizado} ", " {$fornecedorNormalizado} ");
            })
            ->values();
    }

    private function filtrarFornecedoresPorPrimeiroToken(Collection $fornecedores, string $textoNormalizado): Collection
    {
        $token = $this->primeiroTokenFornecedor($textoNormalizado);

        return $fornecedores
            ->filter(function (Fornecedor $fornecedor) use ($token) {
                $fornecedorNormalizado = $this->normalizarNomeFornecedor($fornecedor->nome);
                if (!$fornecedorNormalizado) {
                    return false;
                }

                return str_contains(" {$fornecedorNormalizado} ", " {$token} ");
            })
            ->values();
    }

    private function primeiroTokenFornecedor(string $nomeNormalizado): string
    {
        $tokens = preg_split('/\s+/', trim($nomeNormalizado)) ?: [];
        $primeiro = $tokens[0] ?? '';

        return mb_strlen($primeiro) >= 3 ? $primeiro : $nomeNormalizado;
    }

    private function logFornecedorNaoSelecionado(
        string $motivo,
        array $fornecedorSugerido,
        ?string $nomeArquivo,
        array $fornecedorIds
    ): void {
        Log::info('Importacao de pedido - fornecedor nao selecionado automaticamente', [
            'motivo' => $motivo,
            'fornecedor_sugerido' => $fornecedorSugerido['nome'] ?? null,
            'fornecedor_sugerido_cnpj' => $this->normalizarDocumentoFornecedor($fornecedorSugerido['cnpj'] ?? null),
            'arquivo_nome' => $nomeArquivo,
            'fornecedor_ids_candidatos' => $fornecedorIds,
        ]);
    }

    private function normalizarDocumentoFornecedor(mixed $value): ?string
    {
        $documento = preg_replace('/\D+/', '', (string) ($value ?? ''));
        return $documento !== '' ? $documento : null;
    }

    private function normalizarNomeFornecedor(mixed $value): ?string
    {
        $nome = trim((string) ($value ?? ''));
        if ($nome === '') {
            return null;
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome);
        $normalized = strtolower($ascii !== false ? $ascii : $nome);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized);
        $normalized = trim(preg_replace('/\s+/', ' ', (string) $normalized));

        return $normalized !== '' ? $normalized : null;
    }

    private function temPedidoMinimo(array $pedido, array $totais): bool
    {
        $temNumero = !empty(trim((string) ($pedido['numero_pedido'] ?? '')));
        $temData = !empty(trim((string) ($pedido['data_pedido'] ?? '')));
        $temTotal = isset($totais['total_liquido']) && trim((string) $totais['total_liquido']) !== ''
            || isset($totais['total_bruto']) && trim((string) $totais['total_bruto']) !== '';
        return $temNumero || $temData || $temTotal;
    }

    /**
     * Verifica se o preview em cache tem dados mínimos (para reutilizar mesmo com itens vazios).
     */
    private function previewTemDadosMinimos(array $preview): bool
    {
        $pedido = $preview['pedido'] ?? [];
        $totais = $preview['totais'] ?? [];
        return $this->temPedidoMinimo($pedido, $totais);
    }

    /**
     * Garante que o payload de preview contenha os campos do contrato (retrocompatibilidade).
     */
    private function garantirContratoPreview(array $preview): array
    {
        $itens = $preview['itens'] ?? [];
        $temItens = is_array($itens) && count($itens) > 0;
        if (!array_key_exists('itens_extraidos', $preview)) {
            $preview['itens_extraidos'] = $temItens;
        }
        if (!array_key_exists('requer_insercao_manual', $preview)) {
            $preview['requer_insercao_manual'] = !$temItens;
        }
        if (!array_key_exists('avisos', $preview)) {
            $preview['avisos'] = $temItens ? [] : ['Itens não puderam ser extraídos automaticamente. Insira manualmente.'];
        }
        return $preview;
    }

    private function isRoteiroConsignacaoDevolucao(Pedido $pedido): bool
    {
        $statusAtual = $pedido->statusAtual?->status?->value ?? $pedido->statusAtual?->status;
        if ($statusAtual === 'devolucao_consignacao') {
            return true;
        }

        if ($pedido->consignacoes->isEmpty()) {
            return false;
        }

        return $pedido->consignacoes->every(function ($item) {
            $status = strtolower((string) ($item->status ?? ''));
            return in_array($status, ['devolvido', 'comprado', 'finalizado'], true);
        });
    }

    private function normalizarTipoRoteiro(mixed $tipo): ?string
    {
        if ($tipo === null || $tipo === '') {
            return null;
        }

        $tipo = strtolower((string) $tipo);
        abort_unless(in_array($tipo, ['consignacao', 'devolucao'], true), 422, 'Tipo de roteiro invalido.');

        return $tipo;
    }
}
