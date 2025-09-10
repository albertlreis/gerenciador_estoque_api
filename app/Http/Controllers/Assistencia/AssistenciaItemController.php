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
use Illuminate\Http\Request;

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
            $item->load(['defeito','variacao.produto'])
        ))->response();
    }

    /**
     * Coloca o item em 'aguardando_reparo' (fluxo depósito/cliente).
     *
     * Body esperado (JSON):
     * - deposito_entrada_id: int (obrigatório quando local_reparo = 'deposito')
     */
    public function iniciarReparo(int $itemId, Request $request): JsonResponse
    {
        $depositoEntradaId = $request->has('deposito_entrada_id')
            ? (int) $request->input('deposito_entrada_id')
            : null;

        $item = $this->service->iniciarReparo(
            itemId: $itemId,
            usuarioId: auth()->id(),
            depositoEntradaId: $depositoEntradaId
        );

        return (new AssistenciaChamadoItemResource($item))->response();
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

    /**
     * Conclui o reparo sem movimentação de estoque (depósito/cliente) e muda para 'reparo_concluido'.
     */
    public function concluirReparo(int $itemId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'data_conclusao' => ['nullable','date'],
            'observacao'     => ['nullable','string','max:500'],
        ]);

        $item = $this->service->concluirReparoLocal($itemId, $data['data_conclusao'] ?? null, $data['observacao'] ?? null, auth()->id());
        return (new AssistenciaChamadoItemResource($item))->response();
    }

    public function aguardarResposta(int $itemId): JsonResponse
    {
        $item = $this->service->marcarAguardandoRespostaFabrica($itemId, auth()->id());
        return (new AssistenciaChamadoItemResource($item))->response();
    }

    public function aguardarPeca(int $itemId): JsonResponse
    {
        $item = $this->service->marcarAguardandoPeca($itemId, auth()->id());
        return (new AssistenciaChamadoItemResource($item))->response();
    }

    public function saidaFabrica(int $itemId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'rastreio_retorno' => ['nullable','string','max:100'],
            'data_envio_retorno' => ['nullable','date'], // só para log; não persiste em coluna
        ]);

        $item = $this->service->registrarSaidaDaFabrica(
            itemId: $itemId,
            rastreio: $data['rastreio_retorno'] ?? null,
            dataEnvio: $data['data_envio_retorno'] ?? null,
            usuarioId: auth()->id()
        );

        return (new AssistenciaChamadoItemResource($item))->response();
    }

    public function entregar(int $itemId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'deposito_saida_id' => ['required','integer','min:1'],
            'data_entrega'      => ['nullable','date'],
            'observacao'        => ['nullable','string','max:500'],
        ]);

        $item = $this->service->registrarEntregaAoCliente(
            itemId: $itemId,
            depositoSaidaId: (int) $data['deposito_saida_id'],
            dataEntrega: $data['data_entrega'] ?? null,
            observacao: $data['observacao'] ?? null,
            usuarioId: auth()->id()
        );

        return (new AssistenciaChamadoItemResource($item))->response();
    }
}
