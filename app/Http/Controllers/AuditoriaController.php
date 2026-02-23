<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\AuditoriaEvento;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditoriaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($forbidden = $this->autorizarVisualizacao()) {
            return $forbidden;
        }

        $filtros = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'module' => ['nullable', 'string', 'max:40'],
            'action' => ['nullable', 'string', 'max:40'],
            'auditable_type' => ['nullable', 'string', 'max:120'],
            'auditable_id' => ['nullable', 'integer'],
            'actor_id' => ['nullable', 'integer'],
            'actor_name' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AuditoriaEvento::query()->withCount('mudancas');
        $this->aplicarFiltros($query, $filtros);

        $perPage = (int) ($filtros['per_page'] ?? 20);
        $paginated = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json([
            'data' => collect($paginated->items())->map(fn (AuditoriaEvento $evento) => $this->mapEventoResumo($evento)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        if ($forbidden = $this->autorizarVisualizacao()) {
            return $forbidden;
        }

        $evento = AuditoriaEvento::query()
            ->with(['mudancas' => fn ($q) => $q->orderBy('id')])
            ->findOrFail($id);

        return response()->json([
            'data' => [
                ...$this->mapEventoResumo($evento),
                'mudancas' => $evento->mudancas->map(fn ($mudanca) => [
                    'id' => $mudanca->id,
                    'field' => $mudanca->field,
                    'old_value' => $mudanca->old_value,
                    'new_value' => $mudanca->new_value,
                    'value_type' => $mudanca->value_type,
                ])->values(),
            ],
        ]);
    }

    public function entidade(Request $request): JsonResponse
    {
        if ($forbidden = $this->autorizarVisualizacao()) {
            return $forbidden;
        }

        $filtros = $request->validate([
            'type' => ['required', 'string', 'max:120'],
            'id' => ['required', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'action' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AuditoriaEvento::query()
            ->where('auditable_type', $filtros['type'])
            ->where('auditable_id', (int) $filtros['id'])
            ->withCount('mudancas');

        if (!empty($filtros['action'])) {
            $query->where('action', $filtros['action']);
        }

        if (!empty($filtros['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filtros['date_from'])->startOfDay());
        }

        if (!empty($filtros['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filtros['date_to'])->endOfDay());
        }

        $perPage = (int) ($filtros['per_page'] ?? 20);
        $paginated = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json([
            'data' => collect($paginated->items())->map(fn (AuditoriaEvento $evento) => $this->mapEventoResumo($evento)),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    private function aplicarFiltros($query, array $filtros): void
    {
        if (!empty($filtros['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filtros['date_from'])->startOfDay());
        }

        if (!empty($filtros['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filtros['date_to'])->endOfDay());
        }

        if (!empty($filtros['module'])) {
            $query->where('module', $filtros['module']);
        }

        if (!empty($filtros['action'])) {
            $query->where('action', $filtros['action']);
        }

        if (!empty($filtros['auditable_type'])) {
            $query->where('auditable_type', $filtros['auditable_type']);
        }

        if (!empty($filtros['auditable_id'])) {
            $query->where('auditable_id', (int) $filtros['auditable_id']);
        }

        if (!empty($filtros['actor_id'])) {
            $query->where('actor_id', (int) $filtros['actor_id']);
        }

        if (!empty($filtros['actor_name'])) {
            $query->where('actor_name', 'like', '%' . trim((string) $filtros['actor_name']) . '%');
        }

        if (!empty($filtros['q'])) {
            $q = '%' . trim((string) $filtros['q']) . '%';

            $query->where(function ($sub) use ($q) {
                $sub->where('label', 'like', $q)
                    ->orWhere('actor_name', 'like', $q)
                    ->orWhereRaw('CAST(metadata_json AS CHAR) LIKE ?', [$q]);
            });
        }
    }

    private function mapEventoResumo(AuditoriaEvento $evento): array
    {
        return [
            'id' => $evento->id,
            'created_at' => $evento->created_at,
            'actor_type' => $evento->actor_type,
            'actor_id' => $evento->actor_id,
            'actor_name' => $evento->actor_name,
            'auditable_type' => $evento->auditable_type,
            'auditable_id' => $evento->auditable_id,
            'module' => $evento->module,
            'action' => $evento->action,
            'label' => $evento->label,
            'request_id' => $evento->request_id,
            'route' => $evento->route,
            'method' => $evento->method,
            'ip' => $evento->ip,
            'user_agent' => $evento->user_agent,
            'origin' => $evento->origin,
            'metadata_json' => $evento->metadata_json,
            'mudancas_count' => $evento->mudancas_count ?? 0,
        ];
    }

    private function autorizarVisualizacao(): ?JsonResponse
    {
        if (AuthHelper::hasPermissao('auditoria.visualizar')) {
            return null;
        }

        $fallback = [
            'produtos.gerenciar',
            'pedidos.editar',
            'estoque.movimentacao',
            'contas.pagar.view',
            'contas.receber.view',
            'financeiro.lancamentos.visualizar',
        ];

        foreach ($fallback as $permissao) {
            if (AuthHelper::hasPermissao($permissao)) {
                return null;
            }
        }

        return response()->json(['message' => 'Sem permissão para visualizar auditoria.'], 403);
    }
}
