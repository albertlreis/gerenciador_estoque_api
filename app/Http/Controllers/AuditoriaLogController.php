<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\AuditoriaLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditoriaLogController extends Controller
{
    private const ADMIN_VISIBLE_PROFILES = [
        'administrador',
        'financeiro',
        'estoque',
        'estoquista',
        'vendedor',
    ];

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min($this->queryInteger($request, 'per_page', 25), 100));
        $pageNumber = max(1, $this->queryInteger($request, 'page', 1));
        $query = AuditoriaLog::query()
            ->withCount('mudancas')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $this->applyVisibilityScope($query);
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
            $value = $this->queryScalar($request, $field);
            if ($value !== null) {
                $query->where($field, $value);
            }
        }

        $usuarioId = $this->queryScalar($request, 'usuario_id');
        if ($usuarioId !== null) {
            $query->where('actor_id', (int) $usuarioId);
        }

        $entidadeType = $this->queryScalar($request, 'entidade_type');
        if ($entidadeType !== null) {
            $query->where('entity_type', $entidadeType);
        }

        $entidadeId = $this->queryScalar($request, 'entidade_id');
        if ($entidadeId !== null) {
            $query->where('entity_id', $entidadeId);
        }

        $dataInicio = $this->queryScalar($request, 'data_inicio');
        if ($dataInicio !== null) {
            $query->where('occurred_at', '>=', $dataInicio);
        }

        $dataFim = $this->queryScalar($request, 'data_fim');
        if ($dataFim !== null) {
            $query->where('occurred_at', '<=', $dataFim);
        }

        $q = $this->queryScalar($request, 'q') ?? '';
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

        $page = $query->paginate($perPage, ['*'], 'page', $pageNumber);

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

    private function queryScalar(Request $request, string $key): ?string
    {
        return $this->firstScalar($request->query($key));
    }

    private function queryInteger(Request $request, string $key, int $default): int
    {
        $value = $this->queryScalar($request, $key);
        if ($value === null || !preg_match('/^-?\d+$/', $value)) {
            return $default;
        }

        return (int) $value;
    }

    private function firstScalar(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $scalar = $this->firstScalar($item);
                if ($scalar !== null) {
                    return $scalar;
                }
            }

            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            $scalar = trim((string) $value);

            return $scalar === '' ? null : $scalar;
        }

        return null;
    }

    public function filters(): JsonResponse
    {
        $query = AuditoriaLog::query();
        $this->applyVisibilityScope($query);

        return response()->json([
            'data' => [
                'usuarios' => $this->userOptions(),
                'tipo' => $this->fieldOptions($query, 'tipo'),
                'categoria' => $this->fieldOptions($query, 'categoria'),
                'nivel' => $this->fieldOptions($query, 'nivel'),
                'modulo' => $this->fieldOptions($query, 'modulo'),
                'acao' => $this->fieldOptions($query, 'acao'),
                'status' => $this->fieldOptions($query, 'status'),
                'origem' => $this->fieldOptions($query, 'origem'),
                'source_system' => $this->fieldOptions($query, 'source_system'),
                'source_kind' => $this->fieldOptions($query, 'source_kind'),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $query = AuditoriaLog::query()->with('mudancas');
        $this->applyVisibilityScope($query);

        $log = $query->findOrFail($id);

        return response()->json(['data' => $this->formatDetail($log)]);
    }

    private function applyVisibilityScope(Builder $query): void
    {
        if ($this->hasCurrentProfile('desenvolvedor')) {
            return;
        }

        if ($this->hasCurrentProfile('administrador')) {
            $actorIds = $this->adminVisibleActorIds();
            if ($actorIds === []) {
                $query->whereRaw('1 = 0');
                return;
            }

            $query->whereIn('actor_id', $actorIds);
            return;
        }

        $query->where('actor_id', (int) auth()->id());
    }

    /**
     * @return array<int,int>
     */
    private function adminVisibleActorIds(): array
    {
        if (!Schema::hasTable('acesso_usuario_perfil') || !Schema::hasTable('acesso_perfis')) {
            return [];
        }

        $profilesByUser = DB::table('acesso_usuario_perfil')
            ->join('acesso_perfis', 'acesso_usuario_perfil.id_perfil', '=', 'acesso_perfis.id')
            ->get(['acesso_usuario_perfil.id_usuario', 'acesso_perfis.nome'])
            ->groupBy('id_usuario');

        $visible = [];
        foreach ($profilesByUser as $userId => $rows) {
            $profiles = $rows
                ->pluck('nome')
                ->map(fn ($profile) => $this->normalizeProfile((string) $profile))
                ->filter()
                ->values();

            if ($profiles->contains('desenvolvedor')) {
                continue;
            }

            if ($profiles->intersect(self::ADMIN_VISIBLE_PROFILES)->isNotEmpty()) {
                $visible[] = (int) $userId;
            }
        }

        return array_values(array_unique($visible));
    }

    private function hasCurrentProfile(string $profile): bool
    {
        $profile = $this->normalizeProfile($profile);

        return collect(AuthHelper::getPerfis())
            ->map(fn ($current) => $this->normalizeProfile((string) $current))
            ->contains($profile);
    }

    private function normalizeProfile(string $profile): string
    {
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $profile);
        $normalized = strtolower($normalized ?: $profile);
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?: '';

        return trim($normalized, '_');
    }

    /**
     * @return array<int,array{label:string,value:int,email:?string,name:?string}>
     */
    private function userOptions(): array
    {
        $userIds = null;
        if (!$this->hasCurrentProfile('desenvolvedor')) {
            $userIds = $this->hasCurrentProfile('administrador')
                ? $this->adminVisibleActorIds()
                : [(int) auth()->id()];
        }

        if (Schema::hasTable('acesso_usuarios')) {
            $query = DB::table('acesso_usuarios')->select(['id', 'nome', 'email'])->orderBy('nome');
            if (is_array($userIds)) {
                $query->whereIn('id', $userIds);
            }

            return $query->get()
                ->map(fn ($user) => $this->formatUserOption((int) $user->id, $user->nome ?? null, $user->email ?? null))
                ->values()
                ->all();
        }

        $current = auth()->user();

        return [
            $this->formatUserOption(
                (int) auth()->id(),
                $current?->nome ?? $current?->name ?? null,
                $current?->email ?? null
            ),
        ];
    }

    /**
     * @return array{label:string,value:int,email:?string,name:?string}
     */
    private function formatUserOption(int $id, ?string $name, ?string $email): array
    {
        $label = trim((string) ($name ?: $email ?: "Usuario #{$id}"));
        if ($email && $name) {
            $label .= " ({$email})";
        }

        return [
            'label' => $label,
            'value' => $id,
            'email' => $email,
            'name' => $name,
        ];
    }

    /**
     * @return array<int,array{label:string,value:string}>
     */
    private function fieldOptions(Builder $baseQuery, string $field): array
    {
        return (clone $baseQuery)
            ->whereNotNull($field)
            ->where($field, '<>', '')
            ->distinct()
            ->orderBy($field)
            ->limit(300)
            ->pluck($field)
            ->map(fn ($value) => [
                'label' => $this->optionLabel($field, (string) $value),
                'value' => (string) $value,
            ])
            ->values()
            ->all();
    }

    private function optionLabel(string $field, string $value): string
    {
        $labels = [
            'tipo' => [
                'auditoria' => 'Auditoria',
                'evento' => 'Evento',
                'integracao' => 'Integracao',
                'log' => 'Log',
                'metrica' => 'Metrica',
                'request' => 'Request',
            ],
            'categoria' => [
                'negocio' => 'Negocio',
                'integracao' => 'Integracao',
                'tecnico' => 'Tecnico',
                'metrica' => 'Metrica',
                'request' => 'Request',
            ],
            'source_system' => [
                'estoque' => 'Estoque',
                'auth' => 'Autenticacao',
            ],
            'source_kind' => [
                'legacy_table' => 'Tabela legada',
                'log_file' => 'Arquivo de log',
                'monolog' => 'Logger',
                'import_run' => 'Execucao de importacao',
                'sync' => 'Sincronizacao',
                'metric' => 'Metrica',
            ],
        ];

        if (isset($labels[$field][$value])) {
            return $labels[$field][$value];
        }

        return ucwords(str_replace(['_', '-'], ' ', $value));
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
