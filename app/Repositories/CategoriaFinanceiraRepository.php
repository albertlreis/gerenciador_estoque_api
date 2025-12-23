<?php

namespace App\Repositories;

use App\Models\CategoriaFinanceira;
use Illuminate\Database\Eloquent\Collection;

class CategoriaFinanceiraRepository
{
    /** @return Collection<int, CategoriaFinanceira> */
    public function listar(array $filtros): Collection
    {
        $q = CategoriaFinanceira::query()
            ->select(['id','nome','slug','tipo','ativo','padrao','categoria_pai_id','ordem'])
            ->orderBy('ordem')
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
