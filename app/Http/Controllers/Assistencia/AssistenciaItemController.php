<?php

namespace App\Http\Controllers\Assistencia;

use App\DTOs\Assistencia\AdicionarItemDTO;
use App\DTOs\Assistencia\AprovacaoDTO;
use App\DTOs\Assistencia\EnviarItemAssistenciaDTO;
use App\DTOs\Assistencia\OrcamentoDTO;
use App\DTOs\Assistencia\RetornoDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assistencia\AdicionarItemRequest;
use App\Http\Requests\Assistencia\AprovacaoRequest;
use App\Http\Requests\Assistencia\EnviarItemAssistenciaRequest;
use App\Http\Requests\Assistencia\OrcamentoRequest;
use App\Http\Requests\Assistencia\RetornoRequest;
use App\Http\Resources\AssistenciaChamadoItemResource;
use App\Models\AssistenciaChamado;
use App\Services\Assistencia\AssistenciaItemService;
use Illuminate\Http\JsonResponse;

class AssistenciaItemController extends Controller
{
    public function __construct(
        protected AssistenciaItemService $service
    ) {}

    public function store(int $id, AdicionarItemRequest $request): JsonResponse
    {
        /** @var AssistenciaChamado $chamado */
        $chamado = AssistenciaChamado::findOrFail($id);

        $dto = AdicionarItemDTO::fromArray(array_merge(
            $request->validated(),
            ['chamado_id' => $chamado->id]
        ));

        $item = $this->service->adicionarItem($dto, auth()->id());

        return (new AssistenciaChamadoItemResource(
            $item->load(['defeito'])
        ))->response();
    }

    public function enviar(int $itemId, EnviarItemAssistenciaRequest $request): JsonResponse
    {
        $dto = EnviarItemAssistenciaDTO::fromArray(array_merge(
            $request->validated(),
            ['item_id' => $itemId]
        ));

        $item = $this->service->enviarParaAssistencia($dto, auth()->id());

        return (new AssistenciaChamadoItemResource($item))->response();
    }

    public function orcamento(int $itemId, OrcamentoRequest $request): JsonResponse
    {
        $dto = OrcamentoDTO::fromArray(array_merge(
            $request->validated(),
            ['item_id' => $itemId]
        ));

        $item = $this->service->registrarOrcamento($dto, auth()->id());

        return (new AssistenciaChamadoItemResource($item))->response();
    }

    public function aprovar(int $itemId, AprovacaoRequest $request): JsonResponse
    {
        $dto = AprovacaoDTO::fromArray(array_merge(
            $request->validated(),
            ['item_id' => $itemId, 'aprovado' => true]
        ));

        $item = $this->service->decidirOrcamento($dto, auth()->id());

        return (new AssistenciaChamadoItemResource($item))->response();
    }

    public function reprovar(int $itemId, AprovacaoRequest $request): JsonResponse
    {
        $dto = AprovacaoDTO::fromArray(array_merge(
            $request->validated(),
            ['item_id' => $itemId, 'aprovado' => false]
        ));

        $item = $this->service->decidirOrcamento($dto, auth()->id());

        return (new AssistenciaChamadoItemResource($item))->response();
    }

    public function retorno(int $itemId, RetornoRequest $request): JsonResponse
    {
        $dto = RetornoDTO::fromArray(array_merge(
            $request->validated(),
            ['item_id' => $itemId]
        ));

        $item = $this->service->registrarRetorno($dto, auth()->id());

        return (new AssistenciaChamadoItemResource($item))->response();
    }
}
