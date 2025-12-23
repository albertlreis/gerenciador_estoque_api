<?php

namespace App\Repositories;

use App\Models\DespesaRecorrente;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class DespesaRecorrenteRepository
{
    /** @return Builder<DespesaRecorrente> */
    public function queryBase(array $filtros = []): Builder
    {
        $q = DespesaRecorrente::query()
            ->with(['fornecedor', 'usuario'])
            ->orderByDesc('id');

        if (!empty($filtros['status'])) {
            $q->where('status', $filtros['status']);
        }

        if (!empty($filtros['tipo'])) {
            $q->where('tipo', $filtros['tipo']);
        }

        if (!empty($filtros['frequencia'])) {
            $q->where('frequencia', $filtros['frequencia']);
        }

        if (!empty($filtros['fornecedor_id'])) {
            $q->where('fornecedor_id', (int) $filtros['fornecedor_id']);
        }

        if (!empty($filtros['q'])) {
            $term = trim((string) $filtros['q']);
            $q->where(function (Builder $w) use ($term) {
                $w->where('descricao', 'like', "%{$term}%")
                    ->orWhere('numero_documento', 'like', "%{$term}%")
                    ->orWhere('categoria', 'like', "%{$term}%")
                    ->orWhere('centro_custo', 'like', "%{$term}%");
            });
        }

        if (!empty($filtros['data_inicio_de'])) {
            $q->whereDate('data_inicio', '>=', $filtros['data_inicio_de']);
        }

        if (!empty($filtros['data_inicio_ate'])) {
            $q->whereDate('data_inicio', '<=', $filtros['data_inicio_ate']);
        }

        return $q;
    }

    public function paginate(array $filtros = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->queryBase($filtros)->paginate($perPage);
    }

    public function findOrFail(int $id): Builder|array|Collection|Model
    {
        return DespesaRecorrente::query()->findOrFail($id);
    }

    public function create(array $data): DespesaRecorrente
    {
        return DespesaRecorrente::create($data);
    }

    public function update(DespesaRecorrente $model, array $data): DespesaRecorrente
    {
        $model->fill($data);
        $model->save();
        return $model;
    }
}
