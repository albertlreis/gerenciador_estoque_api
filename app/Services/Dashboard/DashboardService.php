<?php

namespace App\Services\Dashboard;

use App\Services\Dashboard\Queries\AdminDashboardQuery;
use App\Services\Dashboard\Queries\EstoqueDashboardQuery;
use App\Services\Dashboard\Queries\FinanceiroDashboardQuery;
use App\Services\Dashboard\Queries\SeriesComercialDashboardQuery;
use App\Services\Dashboard\Queries\VendedorDashboardQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DashboardService
{
    public function __construct(
        private readonly AdminDashboardQuery $adminQuery,
        private readonly FinanceiroDashboardQuery $financeiroQuery,
        private readonly EstoqueDashboardQuery $estoqueQuery,
        private readonly VendedorDashboardQuery $vendedorQuery,
        private readonly SeriesComercialDashboardQuery $seriesQuery,
    ) {}

    public function admin(array $filters, int $usuarioId): array
    {
        $resolved = $this->resolveFilters($filters, true);

        $cacheKey = $this->profileCacheKey('admin', $usuarioId, $resolved);

        return $this->remember($cacheKey, $resolved['fresh'], function () use ($resolved, $usuarioId) {
            $current = $this->adminQuery->fetch($resolved['inicio'], $resolved['fim'], $resolved['deposito_id']);

            $previous = null;
            if ($resolved['compare']) {
                $previous = $this->adminQuery->fetch($resolved['inicio_prev'], $resolved['fim_prev'], $resolved['deposito_id']);
            }

            $series = $this->seriesQuery->fetch(
                $resolved['inicio'],
                $resolved['fim'],
                $resolved['period'],
                $resolved['deposito_id'],
                $usuarioId,
                true,
                $resolved['compare'],
                $resolved['compare']
                    ? ['inicio' => $resolved['inicio_prev'], 'fim' => $resolved['fim_prev']]
                    : null
            );

            return [
                'meta' => $this->meta($resolved),
                'kpis' => $this->wrapKpis($current['kpis'], $previous['kpis'] ?? null, $resolved['compare']),
                'pendencias' => $current['pendencias'],
                'series' => $series,
            ];
        });
    }

    public function financeiro(array $filters, int $usuarioId): array
    {
        $resolved = $this->resolveFilters($filters, false);

        $cacheKey = $this->profileCacheKey('financeiro', $usuarioId, $resolved);

        return $this->remember($cacheKey, $resolved['fresh'], function () use ($resolved) {
            $data = $this->financeiroQuery->fetch();

            return [
                'meta' => $this->meta($resolved),
                'kpis' => $this->wrapKpis($data['kpis'], null, false),
                'pendencias' => $data['pendencias'],
                'series' => (object) [],
            ];
        });
    }

    public function estoque(array $filters, int $usuarioId): array
    {
        $resolved = $this->resolveFilters($filters, false);

        $cacheKey = $this->profileCacheKey('estoque', $usuarioId, $resolved);

        return $this->remember($cacheKey, $resolved['fresh'], function () use ($resolved) {
            $data = $this->estoqueQuery->fetch($resolved['inicio'], $resolved['fim'], $resolved['deposito_id']);

            return [
                'meta' => $this->meta($resolved),
                'kpis' => $this->wrapKpis($data['kpis'], null, false),
                'pendencias' => $data['pendencias'],
                'series' => (object) [],
            ];
        });
    }

    public function vendedor(array $filters, int $usuarioId, bool $podeVisualizarTodos): array
    {
        $resolved = $this->resolveFilters($filters, true);

        $cacheKey = $this->profileCacheKey('vendedor', $usuarioId, $resolved);

        return $this->remember($cacheKey, $resolved['fresh'], function () use ($resolved, $usuarioId, $podeVisualizarTodos) {
            $current = $this->vendedorQuery->fetch(
                $resolved['inicio'],
                $resolved['fim'],
                $usuarioId,
                $podeVisualizarTodos,
                $resolved['deposito_id']
            );

            $previous = null;
            if ($resolved['compare']) {
                $previous = $this->vendedorQuery->fetch(
                    $resolved['inicio_prev'],
                    $resolved['fim_prev'],
                    $usuarioId,
                    $podeVisualizarTodos,
                    $resolved['deposito_id']
                );
            }

            $series = $this->seriesQuery->fetch(
                $resolved['inicio'],
                $resolved['fim'],
                $resolved['period'],
                $resolved['deposito_id'],
                $usuarioId,
                $podeVisualizarTodos,
                $resolved['compare'],
                $resolved['compare']
                    ? ['inicio' => $resolved['inicio_prev'], 'fim' => $resolved['fim_prev']]
                    : null
            );

            return [
                'meta' => $this->meta($resolved),
                'kpis' => $this->wrapKpis($current['kpis'], $previous['kpis'] ?? null, $resolved['compare']),
                'pendencias' => $current['pendencias'],
                'series' => $series,
            ];
        });
    }

    public function seriesComercial(array $filters, int $usuarioId, bool $podeVisualizarTodos): array
    {
        $resolved = $this->resolveFilters($filters, true);

        $cacheKey = $this->seriesCacheKey($usuarioId, $resolved);

        return $this->remember($cacheKey, $resolved['fresh'], function () use ($resolved, $usuarioId, $podeVisualizarTodos) {
            $series = $this->seriesQuery->fetch(
                $resolved['inicio'],
                $resolved['fim'],
                $resolved['period'],
                $resolved['deposito_id'],
                $usuarioId,
                $podeVisualizarTodos,
                $resolved['compare'],
                $resolved['compare']
                    ? ['inicio' => $resolved['inicio_prev'], 'fim' => $resolved['fim_prev']]
                    : null
            );

            return [
                'meta' => $this->meta($resolved),
                'kpis' => (object) [],
                'pendencias' => (object) [],
                'series' => $series,
            ];
        });
    }

    private function resolveFilters(array $filters, bool $allowCompare): array
    {
        $period = (string) ($filters['period'] ?? config('dashboard.periods.default', 'month'));
        $now = CarbonImmutable::now();

        [$inicio, $fim] = match ($period) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            '7d' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            '6m' => [$now->subMonths(5)->startOfMonth(), $now->endOfDay()],
            'custom' => [
                CarbonImmutable::parse((string) $filters['inicio'])->startOfDay(),
                CarbonImmutable::parse((string) $filters['fim'])->endOfDay(),
            ],
            default => [$now->startOfMonth(), $now->endOfDay()],
        };

        $compare = $allowCompare ? (bool) ($filters['compare'] ?? false) : false;

        $resolved = [
            'period' => $period,
            'inicio' => $inicio,
            'fim' => $fim,
            'compare' => $compare,
            'deposito_id' => $filters['deposito_id'] ?? null,
            'fresh' => (bool) ($filters['fresh'] ?? false),
        ];

        if ($compare) {
            $seconds = $fim->diffInSeconds($inicio) + 1;
            $fimPrev = $inicio->subSecond();
            $inicioPrev = $fimPrev->subSeconds($seconds - 1);

            $resolved['inicio_prev'] = $inicioPrev;
            $resolved['fim_prev'] = $fimPrev;
        }

        return $resolved;
    }

    private function wrapKpis(array $kpis, ?array $previous, bool $compare): array
    {
        $output = [];

        foreach ($kpis as $key => $value) {
            if ($compare && $previous !== null) {
                $prev = (float) ($previous[$key] ?? 0);
                $current = (float) $value;
                $deltaAbs = $current - $prev;
                $deltaPct = $prev == 0.0 ? null : round(($deltaAbs / $prev) * 100, 2);

                $output[$key] = [
                    'value' => $value,
                    'previous' => $previous[$key] ?? 0,
                    'delta_abs' => $deltaAbs,
                    'delta_pct' => $deltaPct,
                ];

                continue;
            }

            $output[$key] = ['value' => $value];
        }

        return $output;
    }

    private function meta(array $resolved): array
    {
        return [
            'period' => $resolved['period'],
            'inicio' => $resolved['inicio']->toDateString(),
            'fim' => $resolved['fim']->toDateString(),
            'compare' => $resolved['compare'] ? 1 : 0,
            'deposito_id' => $resolved['deposito_id'],
            'updated_at' => now()->toIso8601String(),
        ];
    }

    private function profileCacheKey(string $profile, int $usuarioId, array $resolved): string
    {
        return sprintf(
            'dashboard:%s:%d:%s:%s:%s:%s:%d',
            $profile,
            $usuarioId,
            $resolved['period'],
            $resolved['inicio']->toDateString(),
            $resolved['fim']->toDateString(),
            $resolved['deposito_id'] ?? 'null',
            $resolved['compare'] ? 1 : 0,
        );
    }

    private function seriesCacheKey(int $usuarioId, array $resolved): string
    {
        return sprintf(
            'dashboard:series:comercial:%d:%s:%s:%s:%s:%d',
            $usuarioId,
            $resolved['period'],
            $resolved['inicio']->toDateString(),
            $resolved['fim']->toDateString(),
            $resolved['deposito_id'] ?? 'null',
            $resolved['compare'] ? 1 : 0,
        );
    }

    private function remember(string $cacheKey, bool $fresh, callable $callback): array
    {
        $debug = (bool) env('DASHBOARD_DEBUG', false);

        $run = function () use ($callback, $cacheKey, $debug) {
            $start = microtime(true);
            $result = $callback();
            $elapsedMs = (int) round((microtime(true) - $start) * 1000);

            if ($debug) {
                Log::debug('dashboard.query_timing', [
                    'cache_key' => $cacheKey,
                    'elapsed_ms' => $elapsedMs,
                ]);
            }

            return $result;
        };

        if ($fresh) {
            return $run();
        }

        $ttlSeconds = max((int) config('dashboard.cache.ttl_seconds', 300), 60);

        return Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), $run);
    }
}
