<?php

namespace App\Services;

use App\Repositories\ContaFinanceiraRepository;

class ContaFinanceiraCatalogoService
{
    public function __construct(private ContaFinanceiraRepository $repo) {}

    /** @return array<int, mixed> */
    public function listar(array $filtros): array
    {
        return $this->repo->listar($filtros)->map(fn($c) => $c->toArray())->values()->all();
    }
}
