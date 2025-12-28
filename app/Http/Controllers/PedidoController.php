<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePedidoRequest;
use App\Http\Resources\PedidoCompletoResource;
use App\Services\ExtratorPedidoPythonService;
use App\Services\PedidoService;
use App\Services\ImportacaoPedidoService;
use App\Services\EstatisticaPedidoService;
use App\Services\PedidoExportService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
            // 1) Extrai via Python
            $dados = $this->service->processar($request->file('arquivo'));

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

            return response()->json([
                "sucesso" => true,
                "mensagem" => "PDF processado com sucesso.",
                "dados" => [
                    "cliente" => $cliente,
                    "pedido"  => $pedidoFormatado,
                    "itens"   => $itens
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                "sucesso" => false,
                "mensagem" => "Erro ao processar PDF.",
                "erro" => $e->getMessage()
            ], 500);
        }
    }
}
