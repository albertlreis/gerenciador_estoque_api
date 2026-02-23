<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePedidoRequest;
use App\Http\Requests\UpdatePedidoRequest;
use App\Http\Resources\PedidoCompletoResource;
use App\Models\Deposito;
use App\Models\Pedido;
use App\Models\PedidoImportacao;
use App\Models\ProdutoImagem;
use App\Services\ExtratorPedidoPythonService;
use App\Services\PedidoService;
use App\Services\PedidoUpdateService;
use App\Services\ImportacaoPedidoService;
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
     * Atualiza um pedido e seus itens.
     *
     * @param UpdatePedidoRequest $request
     * @param Pedido $pedido
     * @return JsonResponse
     */
    public function update(UpdatePedidoRequest $request, Pedido $pedido): JsonResponse
    {
        $pedidoAtualizado = $this->pedidoUpdateService->atualizar($pedido, $request->validated());

        return response()->json(new PedidoCompletoResource($pedidoAtualizado));
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

        $tiposPermitidos = [
            'PRODUTOS_PDF_SIERRA',
            'PRODUTOS_PDF_AVANTI',
            'PRODUTOS_PDF_QUAKER',
            'ADORNOS_XML_NFE',
        ];

        $tipoImportacao = strtoupper((string) $request->input('tipo_importacao', 'PRODUTOS_PDF_SIERRA'));

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

        $request->validate([
            'arquivo' => [
                'required',
                'file',
                $isXml ? 'mimes:xml' : 'mimes:pdf',
                'max:10240',
            ],
            'tipo_importacao' => 'nullable|string',
        ], [
            'arquivo.mimes' => $isXml
                ? 'Para ADORNOS_XML_NFE, envie um arquivo XML vÃ¡lido.'
                : 'Para importaÃ§Ã£o de produtos, envie um arquivo PDF vÃ¡lido.',
        ]);

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

                if ($previewValido) {
                    Log::info('Importação de pedido - preview reutilizado', [
                        'request_id' => $requestId,
                        'etapa' => 'staging',
                        'usuario_id' => auth()->id(),
                        'importacao_id' => $importExistente->id,
                        'tipo_importacao' => $tipoImportacao,
                        'itens_preview' => count($itensPreview),
                        'tempo_ms' => (int) ((microtime(true) - $inicioImportacao) * 1000),
                    ]);

                    return response()->json([
                        'sucesso' => true,
                        'mensagem' => 'Arquivo já processado. Usando dados existentes.',
                        'importacao_id' => $importExistente->id,
                        'dados' => $importExistente->dados_json,
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
            $dados = $this->service->processar($arquivo, $tipoImportacao, $requestId);

            $pedido = $dados['pedido'] ?? [];
            $itens = $dados['itens'] ?? [];
            $totais = $dados['totais'] ?? [];

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

            $payload = [
                'tipo_importacao' => $tipoImportacao,
                'cliente' => $cliente,
                'pedido' => $pedidoFormatado,
                'itens' => $itens,
                'totais' => $totais,
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
}
