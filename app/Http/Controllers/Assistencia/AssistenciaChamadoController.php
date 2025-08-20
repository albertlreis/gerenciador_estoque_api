<?php

namespace App\Http\Controllers\Assistencia;

use App\DTOs\Assistencia\CriarChamadoDTO;
use App\DTOs\Assistencia\AtualizarChamadoDTO;
use App\Enums\AssistenciaStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assistencia\CriarChamadoRequest;
use App\Http\Requests\Assistencia\AtualizarChamadoRequest;
use App\Http\Resources\AssistenciaChamadoResource;
use App\Models\AssistenciaChamado;
use App\Services\Assistencia\AssistenciaChamadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistenciaChamadoController extends Controller
{
    public function __construct(
        protected AssistenciaChamadoService $service
    ) {}

    /**
     * Lista chamados com filtros básicos e paginação.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $q = AssistenciaChamado::query()->with(['assistencia']);

        if ($s = $request->input('busca')) {
            $q->where(function ($w) use ($s) {
                $w->where('numero', 'like', "%{$s}%")
                    ->orWhere('origem_tipo', 'like', "%{$s}%")
                    ->orWhere('observacoes', 'like', "%{$s}%");
            });
        }
        if ($status = $request->input('status')) {
            $q->where('status', $status);
        }
        if ($assistenciaId = $request->input('assistencia_id')) {
            $q->where('assistencia_id', $assistenciaId);
        }
        if ($prioridade = $request->input('prioridade')) {
            $q->where('prioridade', $prioridade);
        }
        if ($request->filled('cliente_id')) {
            $q->where('cliente_id', $request->integer('cliente_id'));
        }
        if ($request->filled('periodo')) {
            $periodo = $request->input('periodo');
            if (is_array($periodo) && count($periodo) === 2) {
                $q->whereBetween('created_at', [$periodo[0], $periodo[1]]);
            }
        }

        $q->orderByDesc('id');

        $perPage = (int) $request->input('per_page', 15);
        $data = $q->paginate($perPage);

        return AssistenciaChamadoResource::collection($data)->response();
    }

    /**
     * Cria (abre) um novo chamado de assistência.
     *
     * @param CriarChamadoRequest $request
     * @return JsonResponse
     */
    public function store(CriarChamadoRequest $request): JsonResponse
    {
        $dto = CriarChamadoDTO::fromArray($request->validated());
        $chamado = $this->service->abrirChamado($dto, auth()->id());

        return (new AssistenciaChamadoResource(
            $chamado->load(['assistencia'])
        ))->response();
    }

    /**
     * Exibe um chamado com relacionamentos necessários ao front.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $chamado = AssistenciaChamado::with([
            'assistencia',
            'itens.defeito',
            'logs'
        ])->findOrFail($id);

        return (new AssistenciaChamadoResource($chamado))->response();
    }

    /**
     * Atualiza campos básicos do chamado (sem alterar status).
     *
     * @param int $id
     * @param AtualizarChamadoRequest $request
     * @return JsonResponse
     */
    public function update(int $id, AtualizarChamadoRequest $request): JsonResponse
    {
        $chamado = AssistenciaChamado::findOrFail($id);
        $dto = AtualizarChamadoDTO::fromArray($request->validated());

        $atualizado = $this->service->atualizarChamado($chamado, $dto, auth()->id());

        return (new AssistenciaChamadoResource(
            $atualizado->load(['assistencia'])
        ))->response();
    }

    /**
     * Cancela um chamado (regras de bloqueio para itens processados).
     *
     * @param int $id
     * @return JsonResponse
     */
    public function cancelar(int $id): JsonResponse
    {
        $chamado = AssistenciaChamado::findOrFail($id);

        $temBloqueio = $chamado->itens()
            ->whereIn('status_item', [
                AssistenciaStatus::ENVIADO_ASSISTENCIA->value,
                AssistenciaStatus::EM_ORCAMENTO->value,
                AssistenciaStatus::EM_REPARO->value,
                AssistenciaStatus::RETORNADO->value,
                AssistenciaStatus::FINALIZADO->value,
            ])
            ->exists();

        if ($temBloqueio) {
            return response()->json(['message' => 'Chamado com itens processados não pode ser cancelado.'], 422);
        }

        $this->service->atualizarStatus($chamado, AssistenciaStatus::CANCELADO, auth()->id());

        return (new AssistenciaChamadoResource($chamado->fresh()))->response();
    }
}
