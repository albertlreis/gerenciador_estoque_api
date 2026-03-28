<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Requests\StorePedidoRequest;
use App\Http\Requests\UpdatePedidoRequest;
use App\Http\Resources\PedidoCompletoResource;
use App\Enums\EstrategiaVinculoImportacao;
use App\Enums\TipoImportacao;
use App\Models\Deposito;
use App\Models\Pedido;
use App\Models\PedidoImportacao;
use App\Models\ProdutoImagem;
use App\Services\FornecedorPedidoXmlParserService;
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
 * Controlador responsável por operações relacionadas a pedidos.
 */
class PedidoController extends Controller
{
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

            $itens = app(ImportacaoPedidoService::class)
                ->mesclarItensComVariacoes($itens, $estrategiaVinculo);

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
                'numero_externo' => $pedidoFormatado['numero_externo'] ?: null,
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

    /**
     * Gera PDF de roteiro do pedido.
     */
    public function roteiroPdf(int $pedidoId): Response
    {
        // 1) Carrega o básico + statusAtual para decidir o tipo sem puxar consignações/itens
        $pedidoBase = Pedido::with([
            'cliente.enderecoPrincipal', // opcional, mas útil p/ PDF (se quiser)
            'usuario',
            'parceiro',
            'statusAtual',
        ])->findOrFail($pedidoId);

        // Regra de negócio:
        // - Pedido consignado = status atual consignado (e/ou existe consignação)
        // Eu priorizo statusAtual por ser determinístico e mais barato.
        $status = $pedidoBase->statusAtual?->status?->value ?? $pedidoBase->statusAtual?->status;
        $isConsignado = in_array($status, ['consignado', 'devolucao_consignacao'], true);

        // Caso queira ser "à prova de inconsistência" (status divergente),
        // você pode habilitar esse fallback (roda 1 exists()):
        // if (!$isConsignado) $isConsignado = $pedidoBase->isConsignado();

        $baseFsDir = public_path('storage' . DIRECTORY_SEPARATOR . ProdutoImagem::FOLDER);
        Pdf::setOptions(['isRemoteEnabled' => true]);

        // 2) Se for consignado: carrega APENAS consignações e gera o mesmo PDF existente
        if ($isConsignado) {
            $pedido = Pedido::with([
                'cliente.enderecoPrincipal',
                'usuario',
                'parceiro',
                'statusAtual',

                'consignacoes.deposito',
                'consignacoes.produtoVariacao.produto.imagemPrincipal',
                'consignacoes.produtoVariacao.produto',
                'consignacoes.produtoVariacao.atributos',

                // localização
                'consignacoes.produtoVariacao.estoquesComLocalizacao.localizacao.area',
            ])->findOrFail($pedidoId);

            $grupos = $pedido->consignacoes->groupBy(fn($c) => $c->deposito->nome ?? 'Sem depósito');
            $isDevolucao = $this->isRoteiroConsignacaoDevolucao($pedido);
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
                'baseFsDir'  => $baseFsDir,
                'geradoEm'   => now('America/Belem')->format('d/m/Y H:i'),
                'tituloRoteiro' => $tituloRoteiro,
            ])->setPaper('a4');

            return $pdf->download($filename);
        }

        // 3) Caso contrário: carrega APENAS itens do pedido e gera o template novo
        $pedido = Pedido::with([
            'cliente.enderecoPrincipal',
            'usuario',
            'parceiro',

            'itens.variacao.produto.imagemPrincipal',
            'itens.variacao.produto',
            'itens.variacao.atributos',

            // localização
            'itens.variacao.estoquesComLocalizacao.localizacao.area',
        ])->findOrFail($pedidoId);

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
}
