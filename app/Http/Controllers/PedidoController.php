<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Requests\StorePedidoRequest;
use App\Http\Requests\UpdatePedidoRequest;
use App\Http\Resources\PedidoCompletoResource;
use App\Enums\TipoImportacao;
use App\Models\Deposito;
use App\Models\Pedido;
use App\Models\PedidoImportacao;
use App\Models\ProdutoImagem;
use App\Services\ExtratorPedidoPythonService;
use App\Services\ImportacaoPedidoService;
use App\Services\NfeXmlParserService;
use App\Services\PedidoService;
use App\Services\PedidoUpdateService;
use App\Services\EstatisticaPedidoService;
use App\Services\PedidoExportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Controlador responsÃ¡vel por operaÃ§Ãµes relacionadas a pedidos.
 */
class PedidoController extends Controller
{
    protected PedidoService $pedidoService;
    protected PedidoUpdateService $pedidoUpdateService;
    protected ImportacaoPedidoService $importacaoService;
    protected EstatisticaPedidoService $estatisticaService;
    protected PedidoExportService $exportService;
    protected ExtratorPedidoPythonService $service;

    /**
     * Injeta as dependÃªncias necessÃ¡rias.
     */
    public function __construct(
        PedidoService $pedidoService,
        ImportacaoPedidoService $importacaoService,
        EstatisticaPedidoService $estatisticaService,
        PedidoExportService $exportService,
        ExtratorPedidoPythonService $service,
        PedidoUpdateService $pedidoUpdateService
    ) {
        $this->pedidoService = $pedidoService;
        $this->importacaoService = $importacaoService;
        $this->estatisticaService = $estatisticaService;
        $this->exportService = $exportService;
        $this->service = $service;
        $this->pedidoUpdateService = $pedidoUpdateService;
    }

    /**
     * Lista pedidos com filtros, paginaÃ§Ã£o e indicadores adicionais.
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
     * Atualiza um pedido existente (cabeÃ§alho + itens).
     */
    public function update(UpdatePedidoRequest $request, Pedido $pedido): JsonResponse
    {
        if (!AuthHelper::hasPermissao('pedidos.editar')) {
            return response()->json(['message' => 'Sem permissÃ£o para editar pedidos.'], 403);
        }

        $updated = $this->pedidoUpdateService->atualizar(
            $pedido,
            $request->validated(),
            auth()->id()
        );

        $pedidoCompleto = $updated->load([
            'cliente:id,nome,email,telefone',
            'parceiro:id,nome',
            'usuario:id,nome',
            'statusAtual',
            'itens.variacao.produto.imagens',
            'itens.variacao.atributos',
            'historicoStatus.usuario:id,nome',
            'devolucoes.itens.pedidoItem.variacao.produto',
            'devolucoes.itens.trocaItens.variacaoNova.produto',
            'devolucoes.credito',
        ]);

        return response()->json([
            'message' => 'Pedido atualizado com sucesso.',
            'data' => new PedidoCompletoResource($pedidoCompleto),
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
     * Retorna estatÃ­sticas de pedidos por mÃªs.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function estatisticas(Request $request): JsonResponse
    {
        return response()->json($this->estatisticaService->obterEstatisticas($request));
    }

    /**
     * Confirma a importaÃ§Ã£o de um pedido previamente lido do PDF.
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
     * Recebe o PDF, envia para a API Python e retorna JSON estruturado.
     */
    public function importar(Request $request): JsonResponse
    {
        $requestId = (string) ($request->header('X-Request-Id') ?: Str::uuid());
        $inicioImportacao = microtime(true);

        $tiposPermitidos = TipoImportacao::valores();
        $tipoImportacao = strtoupper((string) $request->input('tipo_importacao', TipoImportacao::PRODUTOS_PDF_SIERRA->value));

        if (!in_array($tipoImportacao, $tiposPermitidos, true)) {
            return response()->json([
                'sucesso' => false,
                'mensagem' => 'Tipo de importaÃ§Ã£o invÃ¡lido.',
                'errors' => [
                    'tipo_importacao' => [
                        'Informe um tipo vÃ¡lido: PRODUTOS_PDF_SIERRA, PRODUTOS_PDF_AVANTI, PRODUTOS_PDF_QUAKER ou ADORNOS_XML_NFE.',
                    ],
                ],
            ], 422);
        }

        $isXml = $tipoImportacao === 'ADORNOS_XML_NFE';

        $arquivoRules = [
            'required',
            'file',
            $isXml ? 'mimes:xml' : 'mimes:pdf',
            'max:10240',
        ];
        if ($isXml) {
            $arquivoRules[] = 'mimetypes:application/xml,text/xml';
        }
        $request->validate([
            'arquivo' => $arquivoRules,
            'tipo_importacao' => 'nullable|string',
        ], [
            'arquivo.mimes' => $isXml
                ? 'Para ADORNOS_XML_NFE, envie um arquivo XML vÃ¡lido.'
                : 'Para importaÃ§Ã£o de produtos, envie um arquivo PDF vÃ¡lido.',
        ]);

        if ($isXml && str_ends_with(strtolower($request->file('arquivo')->getClientOriginalName()), ':zone.identifier')) {
            return response()->json([
                'sucesso' => false,
                'mensagem' => 'Arquivo de metadados (Zone.Identifier) não é aceito.',
            ], 422);
        }

        try {
            $arquivo = $request->file('arquivo');
            $hashArquivo = hash_file('sha256', $arquivo->getRealPath());
            $hash = hash('sha256', $hashArquivo . '|' . $tipoImportacao);

            Log::info('ImportaÃ§Ã£o de pedido - inÃ­cio', [
                'request_id' => $requestId,
                'etapa' => 'upload',
                'usuario_id' => auth()->id(),
                'tipo_importacao' => $tipoImportacao,
                'arquivo_nome' => $arquivo->getClientOriginalName(),
                'arquivo_tamanho' => $arquivo->getSize(),
                'arquivo_hash' => $hash,
            ]);

            $importExistente = PedidoImportacao::query()
                ->where('arquivo_hash', $hash)
                ->first();

            if ($importExistente && $importExistente->status === 'confirmado') {
                return response()->json([
                    'sucesso' => false,
                    'mensagem' => 'Este arquivo jÃ¡ foi importado anteriormente para este tipo de importaÃ§Ã£o.',
                    'pedido_id' => $importExistente->pedido_id,
                ], 409);
            }

            if ($importExistente && $importExistente->status === 'extraido' && $importExistente->dados_json) {
                $preview = is_array($importExistente->dados_json)
                    ? $importExistente->dados_json
                    : (array) $importExistente->dados_json;
                $itensPreview = data_get($preview, 'itens', []);
                $previewValido = is_array($itensPreview) && count($itensPreview) > 0;

                if ($previewValido || $this->previewTemDadosMinimos($preview)) {
                    Log::info('Importação de pedido - preview reutilizado', [
                        'request_id' => $requestId,
                        'etapa' => 'staging',
                        'usuario_id' => auth()->id(),
                        'importacao_id' => $importExistente->id,
                        'tipo_importacao' => $tipoImportacao,
                        'itens_preview' => count($itensPreview),
                        'tempo_ms' => (int) ((microtime(true) - $inicioImportacao) * 1000),
                    ]);

                    $dadosCached = $this->garantirContratoPreview($preview);
                    return response()->json([
                        'sucesso' => true,
                        'mensagem' => 'Arquivo já processado. Usando dados existentes.',
                        'importacao_id' => $importExistente->id,
                        'dados' => $dadosCached,
                    ]);
                }

                Log::warning('Importação de pedido - preview vazio, reprocessando arquivo', [
                    'request_id' => $requestId,
                    'etapa' => 'staging',
                    'usuario_id' => auth()->id(),
                    'importacao_id' => $importExistente->id,
                    'tipo_importacao' => $tipoImportacao,
                    'tempo_ms' => (int) ((microtime(true) - $inicioImportacao) * 1000),
                ]);
            }

            if ($tipoImportacao === 'ADORNOS_XML_NFE') {
                $dados = app(NfeXmlParserService::class)->extrair($arquivo);
            } else {
                $dados = $this->service->processar($arquivo, $tipoImportacao, $requestId);
            }

            $pedido = $dados['pedido'] ?? [];
            $itens = $dados['itens'] ?? [];
            $totais = $dados['totais'] ?? [];

            $temItens = is_array($itens) && count($itens) > 0;
            $isPdf = $tipoImportacao !== 'ADORNOS_XML_NFE';

            if ($isPdf && !$temItens) {
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
                Log::warning('Importação de pedido - PDF sem itens identificados (preview ok, requer inserção manual)', [
                    'request_id' => $requestId,
                    'tipo_importacao' => $tipoImportacao,
                    'arquivo_nome' => $nomeArquivo,
                    'debug_texto_preview' => $debugTexto ? mb_substr($debugTexto, 0, 2000) : null,
                ]);
            }

            $itens = app(ImportacaoPedidoService::class)
                ->mesclarItensComVariacoes($itens);

            $cliente = [
                'nome' => $pedido['cliente'] ?? '',
                'documento' => '',
                'email' => '',
                'telefone' => '',
                'endereco' => '',
            ];

            $pedidoFormatado = [
                'numero_externo' => $pedido['numero_pedido'] ?? '',
                'data_pedido' => $pedido['data_pedido'] ?? null,
                'data_inclusao' => $pedido['data_inclusao'] ?? null,
                'data_entrega' => $pedido['data_entrega'] ?? null,
                'total' => floatval(str_replace(',', '.', str_replace('.', '', $totais['total_liquido'] ?? '0'))),
                'observacoes' => $pedido['observacoes'] ?? '',
            ];

            $itensExtraidos = $temItens;
            $requerInsercaoManual = !$itensExtraidos;
            $avisos = [];
            if ($requerInsercaoManual && $isPdf) {
                $avisos[] = 'Itens não puderam ser extraídos automaticamente. Insira manualmente.';
            }

            $payload = [
                'tipo_importacao' => $tipoImportacao,
                'cliente' => $cliente,
                'pedido' => $pedidoFormatado,
                'itens' => $itens,
                'totais' => $totais,
                'itens_extraidos' => $itensExtraidos,
                'requer_insercao_manual' => $requerInsercaoManual,
                'avisos' => $avisos,
            ];

            $importacao = PedidoImportacao::updateOrCreate(
                ['arquivo_hash' => $hash],
                [
                    'arquivo_nome' => $arquivo->getClientOriginalName(),
                    'numero_externo' => $pedidoFormatado['numero_externo'] ?: null,
                    'usuario_id' => auth()->id(),
                    'status' => 'extraido',
                    'dados_json' => $payload,
                    'erro' => null,
                ]
            );

            Log::info('ImportaÃ§Ã£o de pedido - extraÃ§Ã£o concluÃ­da', [
                'request_id' => $requestId,
                'etapa' => 'staging',
                'usuario_id' => auth()->id(),
                'importacao_id' => $importacao->id,
                'tipo_importacao' => $tipoImportacao,
                'itens_total' => count($itens),
                'numero_externo' => $pedidoFormatado['numero_externo'] ?? null,
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
                : hash('sha256', ($request->file('arquivo')?->getClientOriginalName() ?? uniqid()) . '|' . $tipoImportacao);

            PedidoImportacao::updateOrCreate(
                ['arquivo_hash' => $hashErro],
                [
                    'arquivo_nome' => $request->file('arquivo')?->getClientOriginalName(),
                    'usuario_id' => auth()->id(),
                    'status' => 'erro',
                    'erro' => $e->getMessage(),
                ]
            );

            Log::error('ImportaÃ§Ã£o de pedido - erro ao processar', [
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

    /**
     * Gera PDF de roteiro do pedido.
     */
    public function roteiroPdf(int $pedidoId): Response
    {
        // 1) Carrega o bÃ¡sico + statusAtual para decidir o tipo sem puxar consignaÃ§Ãµes/itens
        $pedidoBase = Pedido::with([
            'cliente.enderecoPrincipal', // opcional, mas Ãºtil p/ PDF (se quiser)
            'usuario',
            'parceiro',
            'statusAtual',
        ])->findOrFail($pedidoId);

        // Regra de negÃ³cio:
        // - Pedido consignado = status atual consignado (e/ou existe consignaÃ§Ã£o)
        // Eu priorizo statusAtual por ser determinÃ­stico e mais barato.
        $status = $pedidoBase->statusAtual?->status;
        $isConsignado = ($status === 'consignado');

        // Caso queira ser "Ã  prova de inconsistÃªncia" (status divergente),
        // vocÃª pode habilitar esse fallback (roda 1 exists()):
        // if (!$isConsignado) $isConsignado = $pedidoBase->isConsignado();

        $baseFsDir = public_path('storage' . DIRECTORY_SEPARATOR . ProdutoImagem::FOLDER);
        Pdf::setOptions(['isRemoteEnabled' => true]);

        // 2) Se for consignado: carrega APENAS consignaÃ§Ãµes e gera o mesmo PDF existente
        if ($isConsignado) {
            $pedido = Pedido::with([
                'cliente.enderecoPrincipal',
                'usuario',
                'parceiro',

                'consignacoes.deposito',
                'consignacoes.produtoVariacao.produto.imagemPrincipal',
                'consignacoes.produtoVariacao.produto',
                'consignacoes.produtoVariacao.atributos',

                // localizaÃ§Ã£o
                'consignacoes.produtoVariacao.estoquesComLocalizacao.localizacao.area',
            ])->findOrFail($pedidoId);

            $grupos = $pedido->consignacoes->groupBy(fn($c) => $c->deposito->nome ?? 'Sem depÃ³sito');

            logAuditoria('pedido_pdf', 'GeraÃ§Ã£o de PDF (roteiro consignaÃ§Ã£o via pedidos)', [
                'acao' => 'roteiro_pdf',
                'pedido_id' => $pedidoId,
                'tipo' => 'consignacao',
            ]);

            $pdf = Pdf::loadView('exports.roteiro-consignacao', [
                'pedido'     => $pedido,
                'grupos'     => $grupos,
                'baseFsDir'  => $baseFsDir,
                'geradoEm'   => now('America/Belem')->format('d/m/Y H:i'),
            ])->setPaper('a4');

            return $pdf->download("roteiro_consignacao_{$pedidoId}.pdf");
        }

        // 3) Caso contrÃ¡rio: carrega APENAS itens do pedido e gera o template novo
        $pedido = Pedido::with([
            'cliente.enderecoPrincipal',
            'usuario',
            'parceiro',

            'itens.variacao.produto.imagemPrincipal',
            'itens.variacao.produto',
            'itens.variacao.atributos',

            // localizaÃ§Ã£o
            'itens.variacao.estoquesComLocalizacao.localizacao.area',
        ])->findOrFail($pedidoId);

        // DepÃ³sitos: temos id_deposito no item, e model Deposito existe.
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
            if ($id <= 0) return 'Sem depÃ³sito';
            return $depositosMap[$id] ?? ("DepÃ³sito #{$id}");
        });

        logAuditoria('pedido_pdf', 'GeraÃ§Ã£o de PDF (roteiro pedido)', [
            'acao' => 'roteiro_pdf',
            'pedido_id' => $pedidoId,
            'tipo' => 'pedido',
        ]);

        $pdf = Pdf::loadView('exports.roteiro-pedido', [
            'pedido'     => $pedido,
            'grupos'     => $grupos,
            'baseFsDir'  => $baseFsDir,
            'geradoEm'   => now('America/Belem')->format('d/m/Y H:i'),
        ])->setPaper('a4');

        return $pdf->download("roteiro_pedido_{$pedidoId}.pdf");
    }

    /**
     * Verifica se há dados mínimos do pedido (cabeçalho/totais) para aceitar preview sem itens.
     */
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
}
