<?php

namespace App\Services;

use App\Models\Parceiro;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ParceiroService
{
    /**
     * @param array{
     *   q?: string|null,
     *   status?: int|string|null,
     *   order_by?: 'nome'|'created_at'|'updated_at'|null,
     *   order_dir?: 'asc'|'desc'|null,
     *   per_page?: int|null,
     *   page?: int|null,
     *   with_trashed?: bool|null
     * } $filtros
     */
    public function listar(array $filtros): LengthAwarePaginator
    {
        $query = Parceiro::query();

        // soft-deleted
        $withTrashed = false;
        if (array_key_exists('with_trashed', $filtros)) {
            $withTrashed = filter_var($filtros['with_trashed'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
        }
        if ($withTrashed) {
            $query->withTrashed();
        }

        // busca livre
        if (!empty($filtros['q'])) {
            $q = trim($filtros['q']);
            $query->where(function (Builder $qb) use ($q) {
                $digits = preg_replace('/\D+/', '', $q);
                $qb->where('nome', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('telefone', 'like', "%{$q}%")
                    ->orWhere('documento', 'like', "%{$digits}%");
            });
        }

        // status
        $status = $filtros['status'] ?? null;
        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }

        $orderBy  = $filtros['order_by']  ?? 'nome';
        $orderDir = $filtros['order_dir'] ?? 'asc';
        $perPage  = (int)($filtros['per_page'] ?? 20);

        $query->orderBy($orderBy, $orderDir);

        return $query->paginate($perPage);
    }

    public function obter(int $id): Parceiro
    {
        /** @var Parceiro $parceiro */
        $parceiro = Parceiro::withTrashed()->findOrFail($id);
        return $parceiro;
    }

    public function criar(array $dados): Parceiro
    {
        return Parceiro::create($dados);
    }

    public function atualizar(int $id, array $dados): Model|Collection|Builder
    {
        $parceiro = Parceiro::withTrashed()->findOrFail($id);
        $parceiro->fill($dados)->save();
        return $parceiro;
    }

    public function remover(int $id): void
    {
        $parceiro = Parceiro::findOrFail($id);
        $parceiro->delete();
    }

    public function restaurar(int $id): Model|Collection|Builder
    {
        $parceiro = Parceiro::withTrashed()->findOrFail($id);
        if ($parceiro->trashed()) {
            $parceiro->restore();
        }
        return $parceiro;
    }
}
