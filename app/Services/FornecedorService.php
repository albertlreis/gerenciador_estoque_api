<?php

namespace App\Services;

use App\Models\Fornecedor;
use App\Models\Produto;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Serviço de domínio para Fornecedores.
 *
 * Responsável por encapsular regras de listagem, filtro, CRUD e vínculos.
 */
class FornecedorService
{
    /**
     * Lista fornecedores com filtros e paginação.
     *
     * @param array{
     *   q?: string|null,
     *   status?: int|string|null,
     *   order_by?: 'nome'|'created_at'|'updated_at'|null,
     *   order_dir?: 'asc'|'desc'|null,
     *   per_page?: int|null,
     *   page?: int|null,
     *   with_trashed?: bool|null
     * } $filtros
     * @return LengthAwarePaginator
     */
    public function listar(array $filtros): LengthAwarePaginator
    {
        $query = Fornecedor::query()->withCount('produtos');

        $withTrashed = false;
        if (array_key_exists('with_trashed', $filtros)) {
            $withTrashed = filter_var($filtros['with_trashed'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $withTrashed = $withTrashed === true;
        }

        if ($withTrashed) {
            $query->withTrashed();
        }

        if (!empty($filtros['q'])) {
            $q = trim($filtros['q']);
            $query->where(function (Builder $qb) use ($q) {
                $digits = preg_replace('/\D+/', '', $q);
                $qb->where('nome', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('telefone', 'like', "%{$q}%")
                    ->orWhere('cnpj', 'like', "%{$digits}%");
            });
        }

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

    /**
     * Obtém um fornecedor por ID (inclui soft-deleted).
     *
     * @param int $id
     * @return Fornecedor
     */
    public function obter(int $id): Fornecedor
    {
        /** @var Fornecedor $fornecedor */
        $fornecedor = Fornecedor::withTrashed()->withCount('produtos')->findOrFail($id);
        return $fornecedor;
    }

    /**
     * Cria fornecedor.
     *
     * @param array $dados
     * @return Fornecedor
     */
    public function criar(array $dados): Fornecedor
    {
        return Fornecedor::create($dados)->loadCount('produtos');
    }

    /**
     * Atualiza fornecedor.
     *
     * @param int $id
     * @param array $dados
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Model|\Illuminate\Database\Query\Builder|\Illuminate\Database\Query\Builder[]
     */
    public function atualizar(int $id, array $dados): Model|array|Collection|Builder|\Illuminate\Database\Query\Builder
    {
        $fornecedor = Fornecedor::withTrashed()->findOrFail($id);
        $fornecedor->fill($dados);
        $fornecedor->save();

        return $fornecedor->loadCount('produtos');
    }

    /**
     * Soft delete.
     *
     * @param int $id
     * @return void
     */
    public function remover(int $id): void
    {
        $fornecedor = Fornecedor::findOrFail($id);
        $fornecedor->delete();
    }

    /**
     * Restaura fornecedor removido.
     *
     * @param int $id
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Model|\Illuminate\Database\Query\Builder|\Illuminate\Database\Query\Builder[]
     */
    public function restaurar(int $id): Model|array|Collection|Builder|\Illuminate\Database\Query\Builder
    {
        $fornecedor = Fornecedor::withTrashed()->findOrFail($id);
        if ($fornecedor->trashed()) {
            $fornecedor->restore();
        }
        return $fornecedor->loadCount('produtos');
    }

    /**
     * Lista produtos vinculados ao fornecedor (paginado).
     *
     * @param int $id
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function listarProdutos(int $id, int $perPage = 20): LengthAwarePaginator
    {
        return Produto::query()
            ->select('id','nome','sku','id_fornecedor','estoque_total','preco_venda')
            ->where('id_fornecedor', $id)
            ->orderBy('nome')
            ->paginate($perPage);
    }
}
