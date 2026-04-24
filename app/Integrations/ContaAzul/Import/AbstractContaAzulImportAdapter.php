<?php

namespace App\Integrations\ContaAzul\Import;

use Carbon\CarbonImmutable;

abstract class AbstractContaAzulImportAdapter implements ContaAzulImportAdapter
{
    /**
     * @param  array<string, mixed>  $config
     * @return array{method:string, path:string, query:array<string, mixed>, body:array<string, mixed>}
     */
    public function buildRequest(array $config, int $pagina, int $pageSize): array
    {
        $settings = $this->settings($config);
        $pageParam = (string) ($config['pagination']['page_param'] ?? 'pagina');
        $pageSizeParam = (string) ($config['pagination']['page_size_param'] ?? 'tamanho_pagina');
        $dateRange = $this->buildDateRange($settings);

        return [
            'method' => strtoupper((string) ($settings['method'] ?? $this->defaultMethod())),
            'path' => (string) (($config['paths'][$this->pathKey()] ?? null) ?: $this->defaultPath()),
            'query' => array_merge(
                $this->defaultQuery(),
                (array) ($settings['query'] ?? []),
                $dateRange,
                [
                    $pageParam => $pagina,
                    $pageSizeParam => $pageSize,
                ]
            ),
            'body' => array_merge(
                $this->defaultBody(),
                (array) ($settings['body'] ?? []),
                $dateRange,
                [
                    $pageParam => $pagina,
                    $pageSizeParam => $pageSize,
                ]
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function settings(array $config): array
    {
        return array_merge(
            [
                'method' => $this->defaultMethod(),
                'query' => $this->defaultQuery(),
                'body' => $this->defaultBody(),
            ],
            (array) (($config['import'][$this->settingsKey()] ?? null) ?: [])
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    protected function buildDateRange(array $settings): array
    {
        $days = isset($settings['date_start_days_ago']) ? (int) $settings['date_start_days_ago'] : 0;
        $keys = $settings['date_query_keys'] ?? null;
        if ($days <= 0 || !is_array($keys) || count($keys) < 2) {
            return [];
        }

        $start = CarbonImmutable::now()->subDays($days)->startOfDay()->format('Y-m-d');
        $end = CarbonImmutable::now()->endOfDay()->format('Y-m-d');

        return [
            (string) $keys[0] => $start,
            (string) $keys[1] => $end,
        ];
    }

    protected function defaultMethod(): string
    {
        return 'GET';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [];
    }

    abstract protected function settingsKey(): string;

    abstract protected function pathKey(): string;

    abstract protected function defaultPath(): string;
}
