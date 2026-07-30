<?php

namespace App\Services\Dashboard;

use App\Support\Logging\SierraLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DashboardHomePreferenceService
{
    private const HOME_KEY = 'dashboard_home_v1';

    private const LEGACY_HIDDEN_CATEGORIES_KEY = 'dashboard_admin_tempo_estoque_categorias_ocultas';

    private const VERSION = 1;

    private const BREAKPOINT_COLUMNS = [
        'lg' => 12,
        'md' => 8,
        'sm' => 1,
    ];

    public function get(int $usuarioId): array
    {
        if (! $this->tableAvailable()) {
            return $this->defaultPreference();
        }

        $row = DB::table('usuario_preferencias')
            ->where('usuario_id', $usuarioId)
            ->where('chave', self::HOME_KEY)
            ->first(['valor', 'updated_at']);

        if (! $row || $row->valor === null || $row->valor === '') {
            return $this->defaultPreference();
        }

        $decoded = is_array($row->valor)
            ? $row->valor
            : json_decode((string) $row->valor, true);

        if (! is_array($decoded)) {
            return $this->defaultPreference();
        }

        return $this->normalizePreference($decoded, true, $row->updated_at ? (string) $row->updated_at : null);
    }

    public function update(int $usuarioId, array $payload): array
    {
        $this->ensureTableAvailable();

        $current = $this->get($usuarioId);
        $next = [
            'filters' => $current['filters'],
            'layouts' => $current['layouts'],
        ];

        if (array_key_exists('filters', $payload)) {
            $next['filters'] = $payload['filters'];
        }

        if (array_key_exists('layouts', $payload)) {
            $next['layouts'] = $payload['layouts'];
        }

        $normalized = $this->normalizePreference($next, true, null);
        $now = now();

        DB::table('usuario_preferencias')->upsert(
            [[
                'usuario_id' => $usuarioId,
                'chave' => self::HOME_KEY,
                'valor' => json_encode([
                    'version' => self::VERSION,
                    'filters' => $normalized['filters'],
                    'layouts' => $normalized['layouts'],
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['usuario_id', 'chave'],
            ['valor', 'updated_at']
        );

        return $this->normalizePreference($normalized, true, $now->toIso8601String());
    }

    public function delete(int $usuarioId): array
    {
        $this->ensureTableAvailable();

        DB::transaction(function () use ($usuarioId) {
            DB::table('usuario_preferencias')
                ->where('usuario_id', $usuarioId)
                ->whereIn('chave', [self::HOME_KEY, self::LEGACY_HIDDEN_CATEGORIES_KEY])
                ->delete();
        });

        return $this->defaultPreference();
    }

    public function defaultPreference(): array
    {
        return [
            'version' => self::VERSION,
            'customized' => false,
            'filters' => [],
            'layouts' => [
                'lg' => [],
                'md' => [],
                'sm' => [],
            ],
            'updated_at' => null,
        ];
    }

    private function normalizePreference(array $preference, bool $customized, ?string $updatedAt): array
    {
        return [
            'version' => self::VERSION,
            'customized' => $customized,
            'filters' => $this->normalizeFilters($preference['filters'] ?? []),
            'layouts' => $this->normalizeLayouts($preference['layouts'] ?? []),
            'updated_at' => $updatedAt,
        ];
    }

    private function normalizeFilters(mixed $filters): array
    {
        if (! is_array($filters)) {
            return [];
        }

        $normalized = [];
        $period = $filters['period'] ?? null;
        if (is_string($period) && in_array($period, ['today', '7d', 'month', '6m', 'custom'], true)) {
            $normalized['period'] = $period;
        }

        foreach (['inicio', 'fim'] as $field) {
            if (isset($filters[$field]) && is_string($filters[$field])) {
                $normalized[$field] = $filters[$field];
            }
        }

        if (isset($filters['deposito_id']) && is_numeric($filters['deposito_id'])) {
            $depositoId = (int) $filters['deposito_id'];
            if ($depositoId > 0) {
                $normalized['deposito_id'] = $depositoId;
            }
        }

        if (array_key_exists('compare', $filters)) {
            $normalized['compare'] = filter_var($filters['compare'], FILTER_VALIDATE_BOOL) ? 1 : 0;
        }

        return $normalized;
    }

    private function normalizeLayouts(mixed $layouts): array
    {
        $normalized = [];

        foreach (self::BREAKPOINT_COLUMNS as $breakpoint => $columns) {
            $items = is_array($layouts) && is_array($layouts[$breakpoint] ?? null)
                ? $layouts[$breakpoint]
                : [];
            $seen = [];
            $normalized[$breakpoint] = [];

            foreach (array_slice($items, 0, 40) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = (string) ($item['i'] ?? '');
                if (! preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/', $id) || isset($seen[$id])) {
                    continue;
                }

                $width = max(1, min($columns, (int) ($item['w'] ?? 1)));
                $height = max(1, min(20, (int) ($item['h'] ?? 1)));
                $x = max(0, min($columns - $width, (int) ($item['x'] ?? 0)));
                $y = max(0, min(500, (int) ($item['y'] ?? 0)));

                $normalized[$breakpoint][] = [
                    'i' => $id,
                    'x' => $x,
                    'y' => $y,
                    'w' => $width,
                    'h' => $height,
                ];
                $seen[$id] = true;
            }
        }

        return $normalized;
    }

    private function tableAvailable(): bool
    {
        try {
            return Schema::hasTable('usuario_preferencias');
        } catch (\Throwable $exception) {
            SierraLog::system('dashboard.home_preferences.table_check_failed', [
                'operation' => 'schema_check',
                'exception' => $exception,
            ], 'warning');

            return false;
        }
    }

    private function ensureTableAvailable(): void
    {
        if ($this->tableAvailable()) {
            return;
        }

        throw new HttpException(
            503,
            'Preferencias da home ainda nao estao disponiveis. Execute as migrations e tente novamente.'
        );
    }
}
