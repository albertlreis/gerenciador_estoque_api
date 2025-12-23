<?php

namespace App\Repositories;

use App\Models\ContaFinanceira;
use Illuminate\Database\Eloquent\Collection;

class ContaFinanceiraRepository
{
    /** @return Collection<int, ContaFinanceira> */
    public function listar(array $filtros): Collection
    {
        $q = ContaFinanceira::query()
            ->select(['id','nome','slug','tipo','ativo','padrao','moeda'])
            ->orderByDesc('padrao')
            ->orderBy('nome');

        if (!empty($filtros['tipo'])) {
            $q->where('tipo', $filtros['tipo']);
        }

        if (array_key_exists('ativo', $filtros) && $filtros['ativo'] !== null) {
            $q->where('ativo', (bool)$filtros['ativo']);
        }

        if (!empty($filtros['q'])) {
            $term = trim((string)$filtros['q']);
            $q->where(function ($w) use ($term) {
                $w->where('nome', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%");
            });
        }

        return $q->get();
    }
}
