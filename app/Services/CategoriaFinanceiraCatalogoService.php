<?php

namespace App\Services;

use App\Repositories\CategoriaFinanceiraRepository;

class CategoriaFinanceiraCatalogoService
{
    public function __construct(private CategoriaFinanceiraRepository $repo) {}

    /** @return array<int, mixed> */
    public function listar(array $filtros, bool $tree = false): array
    {
        $items = $this->repo->listar($filtros)->map(fn($c) => $c->toArray())->values()->all();

        if (!$tree) return $items;

        // monta árvore simples por categoria_pai_id
        $byId = [];
        foreach ($items as $it) {
            $it['children'] = [];
            $byId[$it['id']] = $it;
        }

        $roots = [];
        foreach ($byId as $id => $it) {
            $paiId = $it['categoria_pai_id'];
            if ($paiId && isset($byId[$paiId])) {
                $byId[$paiId]['children'][] = &$byId[$id];
            } else {
                $roots[] = &$byId[$id];
            }
        }

        return $roots;
    }
}
