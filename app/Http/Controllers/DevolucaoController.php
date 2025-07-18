<?php

namespace App\Http\Controllers;

use App\Http\Requests\DevolucaoRequest;
use App\Services\DevolucaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

/**
 * Controller para gerenciar devoluções e trocas de pedidos.
 */
class DevolucaoController extends Controller
{
    protected DevolucaoService $service;

    /**
     * Construtor.
     *
     * @param DevolucaoService $service
     */
    public function __construct(DevolucaoService $service)
    {
        $this->service = $service;
    }

    /**
     * Cria uma devolução ou troca.
     *
     * @param DevolucaoRequest $request
     * @return JsonResponse
     * @throws \Throwable
     */
    public function store(DevolucaoRequest $request): JsonResponse
    {
        $devolucao = $this->service->iniciar($request->validated());
        return response()->json($devolucao->load('itens.trocaItens', 'credito'), ResponseAlias::HTTP_CREATED);
    }

    /**
     * Aprova uma devolução pendente, atualiza estoque e gera crédito/troca.
     *
     * @param int $id
     * @return Response
     * @throws \Throwable
     */
    public function approve(int $id): Response
    {
        $this->service->aprovar($id);
        return response()->noContent();
    }

    /**
     * Recusa uma devolução pendente.
     *
     * @param int $id
     * @return Response
     * @throws \Exception
     */
    public function reject(int $id): Response
    {
        $this->service->recusar($id);
        return response()->noContent(ResponseAlias::HTTP_OK);
    }
}
