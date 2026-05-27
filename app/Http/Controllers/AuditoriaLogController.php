<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\AuditoriaLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditoriaLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!$this->canView()) {
            return response()->json(['message' => 'Sem permissao para visualizar auditoria e logs.'], 403);
        }

        $perPage = max(1, min((int) $request->query('per_page', 25), 100));
        $query = AuditoriaLog::query()
            ->withCount('mudancas')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        foreach ([
            'tipo',
            'categoria',
            'nivel',
            'modulo',
            'acao',
            'status',
            'origem',
            'source_system',
            'source_kind',
            'source_table',
            'source_id',
        ] as $field) {
            if ($request->filled($field)) {
                $query->where($field, (string) $request->query($field));
            }
        }

        if ($request->filled('usuario_id')) {
            $query->where('actor_id', (int) $request->query('usuario_id'));
        }

        if ($request->filled('entidade_type')) {
            $query->where('entity_type', (string) $request->query('entidade_type'));
        }

        if ($request->filled('entidade_id')) {
            $query->where('entity_id', (string) $request->query('entidade_id'));
        }

        if ($request->filled('data_inicio')) {
            $query->where('occurred_at', '>=', (string) $request->query('data_inicio'));
        }

        if ($request->filled('data_fim')) {
            $query->where('occurred_at', '<=', (string) $request->query('data_fim'));
        }

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(function ($inner) use ($q): void {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
                $inner->where('label', 'like', $like)
                    ->orWhere('message', 'like', $like)
                    ->orWhere('modulo', 'like', $like)
                    ->orWhere('acao', 'like', $like)
                    ->orWhere('status', 'like', $like)
                    ->orWhere('actor_name', 'like', $like)
                    ->orWhere('entity_id', 'like', $like)
                    ->orWhere('source_table', 'like', $like)
                    ->orWhere('source_id', 'like', $like);
                if (ctype_digit($q)) {
                    $inner->orWhere('id', (int) $q);
                }
            });
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditoriaLog $log) => $this->formatList($log))->values(),
            'meta' => [
                'total' => (int) $page->total(),
                'page' => (int) $page->currentPage(),
                'per_page' => (int) $page->perPage(),
                'last_page' => (int) $page->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        if (!$this->canView()) {
            return response()->json(['message' => 'Sem permissao para visualizar auditoria e logs.'], 403);
        }

        $log = AuditoriaLog::query()->with('mudancas')->findOrFail($id);

        return response()->json(['data' => $this->formatDetail($log)]);
    }

    private function canView(): bool
    {
        return AuthHelper::hasPermissao('auditoria.logs.visualizar')
            || AuthHelper::hasPerfil('Desenvolvedor');
    }

    /**
     * @return array<string,mixed>
     */
    private function formatList(AuditoriaLog $log): array
    {
        return [
            'id' => $log->id,
            'occurred_at' => optional($log->occurred_at)->toISOString(),
            'tipo' => $log->tipo,
            'categoria' => $log->categoria,
            'nivel' => $log->nivel,
            'modulo' => $log->modulo,
            'acao' => $log->acao,
            'status' => $log->status,
            'label' => $log->label,
            'message' => $log->message,
            'actor' => [
                'type' => $log->actor_type,
                'id' => $log->actor_id,
                'name' => $log->actor_name,
            ],
            'entity' => [
                'type' => $log->entity_type,
                'id' => $log->entity_id,
            ],
            'source' => [
                'system' => $log->source_system,
                'kind' => $log->source_kind,
                'table' => $log->source_table,
                'id' => $log->source_id,
            ],
            'route' => $log->route,
            'method' => $log->method,
            'ip' => $log->ip,
            'mudancas_count' => $log->mudancas_count ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function formatDetail(AuditoriaLog $log): array
    {
        return $this->formatList($log) + [
            'user_agent' => $log->user_agent,
            'origem' => $log->origem,
            'metadata_json' => $log->metadata_json,
            'context_json' => $log->context_json,
            'raw_excerpt' => $log->raw_excerpt,
            'retention_days' => $log->retention_days,
            'mudancas' => $log->mudancas->map(fn ($mudanca) => [
                'id' => $mudanca->id,
                'campo' => $mudanca->campo,
                'old_value' => $mudanca->old_value,
                'new_value' => $mudanca->new_value,
                'value_type' => $mudanca->value_type,
            ])->values(),
        ];
    }
}
