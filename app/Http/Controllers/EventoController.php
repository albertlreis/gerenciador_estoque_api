<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Requests\EventoIndexRequest;
use App\Http\Requests\EventoParticipanteRequest;
use App\Http\Requests\EventoStoreRequest;
use App\Http\Requests\EventoUpdateRequest;
use App\Http\Resources\EventoResource;
use App\Models\Evento;
use App\Services\EventoService;
use Illuminate\Http\JsonResponse;

class EventoController extends Controller
{
    public function __construct(private readonly EventoService $service)
    {
    }

    public function index(EventoIndexRequest $request): JsonResponse
    {
        if (!AuthHelper::podeVisualizarEventos()) {
            return response()->json(['message' => 'Sem permissão para visualizar eventos.'], 403);
        }

        return response()->json([
            'data' => EventoResource::collection($this->service->listar($request->validated())),
        ]);
    }

    public function usuarios(): JsonResponse
    {
        if (!AuthHelper::podeVisualizarEventos()) {
            return response()->json(['message' => 'Sem permissão para visualizar usuários de eventos.'], 403);
        }

        return response()->json([
            'data' => $this->service->usuariosDisponiveis(),
        ]);
    }

    public function store(EventoStoreRequest $request): JsonResponse
    {
        if (!AuthHelper::podeGerenciarEventos()) {
            return response()->json(['message' => 'Sem permissão para criar eventos.'], 403);
        }

        $evento = $this->service->criar($request->validated(), (int) auth()->id());

        return response()->json([
            'data' => new EventoResource($evento),
        ], 201);
    }

    public function show(Evento $evento): JsonResponse
    {
        if (!AuthHelper::podeVisualizarEventos()) {
            return response()->json(['message' => 'Sem permissão para visualizar eventos.'], 403);
        }

        $evento->load(['criador:id,nome,email', 'participantes.usuario:id,nome,email']);

        return response()->json([
            'data' => new EventoResource($evento),
        ]);
    }

    public function update(EventoUpdateRequest $request, Evento $evento): JsonResponse
    {
        if (!AuthHelper::podeGerenciarEventos($evento)) {
            return response()->json(['message' => 'Sem permissão para editar este evento.'], 403);
        }

        $evento = $this->service->atualizar($evento, $request->validated());

        return response()->json([
            'data' => new EventoResource($evento),
        ]);
    }

    public function destroy(Evento $evento): JsonResponse
    {
        if (!AuthHelper::podeGerenciarEventos($evento)) {
            return response()->json(['message' => 'Sem permissão para excluir este evento.'], 403);
        }

        $this->service->excluir($evento);

        return response()->json(['message' => 'Evento removido com sucesso.']);
    }

    public function adicionarParticipante(EventoParticipanteRequest $request, Evento $evento): JsonResponse
    {
        if (!AuthHelper::podeGerenciarEventos($evento)) {
            return response()->json(['message' => 'Sem permissão para alterar participantes.'], 403);
        }

        $evento = $this->service->adicionarParticipante($evento, $request->validated());

        return response()->json([
            'data' => new EventoResource($evento),
        ]);
    }

    public function removerParticipante(Evento $evento, int $usuario): JsonResponse
    {
        if (!AuthHelper::podeGerenciarEventos($evento)) {
            return response()->json(['message' => 'Sem permissão para alterar participantes.'], 403);
        }

        $evento = $this->service->removerParticipante($evento, $usuario);

        return response()->json([
            'data' => new EventoResource($evento),
        ]);
    }
}
