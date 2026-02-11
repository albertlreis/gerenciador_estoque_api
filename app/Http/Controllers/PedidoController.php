<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePedidoRequest;
use App\Http\Resources\PedidoCompletoResource;
use App\Models\Deposito;
use App\Models\Pedido;
use App\Models\PedidoImportacao;
use App\Models\ProdutoImagem;
use App\Services\ExtratorPedidoPythonService;
use App\Services\PedidoService;
use App\Services\ImportacaoPedidoService;
use App\Services\EstatisticaPedidoService;
use App\Services\PedidoExportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Controlador responsável por operações relacionadas a pedidos.
 */
class PedidoController extends Controller
{
    protected PedidoService $pedidoService;
    protected ImportacaoPedidoService $importacaoService;
    protected EstatisticaPedidoService $estatisticaService;
    protected PedidoExportService $exportService;
    protected ExtratorPedidoPythonService $service;

    /**
     * Injeta as dependências necessárias.
     */
    public function __construct(
        PedidoService $pedidoService,
        ImportacaoPedidoService $importacaoService,
        EstatisticaPedidoService $estatisticaService,
        PedidoExportService $exportService,
        ExtratorPedidoPythonService $service
    ) {
        $this->pedidoService = $pedidoService;
        $this->importacaoService = $importacaoService;
        $this->estatisticaService = $estatisticaService;
        $this->exportService = $exportService;
        $this->service = $service;
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
     * Confirma a importação de um pedido previamente lido do PDF.
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
        $request->validate([
            'arquivo' => 'required|file|mimes:pdf|max:10240'
        ]);

        try {
            $arquivo = $request->file('arquivo');
            $hash = hash_file('sha256', $arquivo->getRealPath());

            Log::info('Importação PDF - início', [
                'usuario_id' => auth()->id(),
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
                    'mensagem' => 'Este arquivo jÃ¡ foi importado anteriormente.',
                    'pedido_id' => $importExistente->pedido_id,
                ], 409);
            }

            if ($importExistente && $importExistente->status === 'extraido' && $importExistente->dados_json) {
                Log::info('Importa??o PDF - preview reutilizado', [
                    'usuario_id' => auth()->id(),
                    'importacao_id' => $importExistente->id,
                ]);

                return response()->json([
                    'sucesso' => true,
                    'mensagem' => 'PDF jÃ¡ processado. Usando dados existentes.',
                    'importacao_id' => $importExistente->id,
                    'dados' => $importExistente->dados_json,
                ]);
            }

            // 1) Extrai via Python
            $dados = $this->service->processar($arquivo);

            $pedido = $dados['pedido'] ?? [];
            $itens  = $dados['itens'] ?? [];
            $totais = $dados['totais'] ?? [];

            // 2) Mescla itens com banco
            $itens = app(ImportacaoPedidoService::class)
                ->mesclarItensComVariacoes($itens);

            // 3) Normalização do cliente
            $cliente = [
                "nome" => $pedido["cliente"] ?? "",
                "documento" => "",
                "email" => "",
                "telefone" => "",
                "endereco" => "",
            ];

            // 4) Normalização do pedido
            $pedidoFormatado = [
                "numero_externo" => $pedido["numero_pedido"] ?? "",
                "data_pedido"    => $pedido["data_pedido"] ?? null,
                "data_inclusao"  => $pedido["data_inclusao"] ?? null,
                "data_entrega"   => $pedido["data_entrega"] ?? null,
                "total"          => floatval(str_replace(",", ".", str_replace(".", "", $totais["total_liquido"] ?? "0"))),
                "observacoes"    => "",
            ];

            $payload = [
                "cliente" => $cliente,
                "pedido"  => $pedidoFormatado,
                "itens"   => $itens
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

            Log::info('Importa??o PDF - extra??o conclu?da', [
                'usuario_id' => auth()->id(),
                'importacao_id' => $importacao->id,
                'itens_total' => count($itens),
                'numero_externo' => $pedidoFormatado['numero_externo'] ?? null,
            ]);

            return response()->json([
                "sucesso" => true,
                "mensagem" => "PDF processado com sucesso.",
                "importacao_id" => $importacao->id,
                "dados" => $payload
            ]);

        } catch (Exception $e) {
            $hashErro = $hash ?? hash('sha256', $request->file('arquivo')?->getClientOriginalName() ?? uniqid());
            PedidoImportacao::updateOrCreate(
                ['arquivo_hash' => $hashErro],
                [
                    'arquivo_nome' => $request->file('arquivo')?->getClientOriginalName(),
                    'usuario_id' => auth()->id(),
                    'status' => 'erro',
                    'erro' => $e->getMessage(),
                ]
            );

            Log::error('Importa??o PDF - erro ao processar', [
                'usuario_id' => auth()->id(),
                'arquivo_hash' => $hashErro,
                'mensagem' => $e->getMessage(),
            ]);

            return response()->json([
                "sucesso" => false,
                "mensagem" => "Erro ao processar PDF.",
                "erro" => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Gera PDF de roteiro do pedido.
     * - Se houver consignações: usa o template existente (roteiro-consignacao).
     * - Caso contrário: usa template novo (roteiro-pedido).
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
        $status = $pedidoBase->statusAtual?->status;
        $isConsignado = ($status === 'consignado');

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

                'consignacoes.deposito',
                'consignacoes.produtoVariacao.produto.imagemPrincipal',
                'consignacoes.produtoVariacao.produto',
                'consignacoes.produtoVariacao.atributos',

                // localização
                'consignacoes.produtoVariacao.estoquesComLocalizacao.localizacao.area',
            ])->findOrFail($pedidoId);

            $grupos = $pedido->consignacoes->groupBy(fn($c) => $c->deposito->nome ?? 'Sem depósito');

            logAuditoria('pedido_pdf', 'Geração de PDF (roteiro consignação via pedidos)', [
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
}
