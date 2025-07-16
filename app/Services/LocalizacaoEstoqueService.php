<?php

namespace App\Services;

use App\Models\LocalizacaoEstoque;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class LocalizacaoEstoqueService
{
    /**
     * Retorna uma lista paginada de localizações de estoque,
     * incluindo dados da variação, produto, categoria e depósito.
     *
     * @param int $perPage Número de itens por página (default: 20)
     * @return LengthAwarePaginator
     */
    public function listar(int $perPage = 20): LengthAwarePaginator
    {
        return LocalizacaoEstoque::with([
            'estoque.variacao.atributos',
            'estoque.variacao.produto.categoria',
            'estoque.deposito'
        ])->paginate($perPage);
    }

    /**
     * Busca uma localização de estoque específica com os seus relacionamentos.
     *
     * @param int $id ID da localização
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Builder[]
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function visualizar(int $id): Builder|array|Collection|Model
    {
        return LocalizacaoEstoque::with([
            'estoque.variacao.atributos',
            'estoque.variacao.produto.categoria',
            'estoque.deposito'
        ])->findOrFail($id);
    }

    /**
     * Cria uma nova localização de estoque com os dados informados.
     *
     * @param array $dados Dados validados para criação
     * @return LocalizacaoEstoque
     */
    public function criar(array $dados): LocalizacaoEstoque
    {
        return DB::transaction(function () use ($dados) {
            return LocalizacaoEstoque::create($dados);
        });
    }

    /**
     * Atualiza uma localização existente com os dados fornecidos.
     *
     * @param int $id ID da localização
     * @param array $dados Dados validados para atualização
     * @return LocalizacaoEstoque
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function atualizar(int $id, array $dados): LocalizacaoEstoque
    {
        return DB::transaction(function () use ($id, $dados) {
            $localizacao = LocalizacaoEstoque::findOrFail($id);
            $localizacao->update($dados);
            return $localizacao;
        });
    }

    /**
     * Remove uma localização de estoque do banco de dados.
     *
     * @param int $id ID da localização
     * @return void
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function excluir(int $id): void
    {
        DB::transaction(function () use ($id) {
            $localizacao = LocalizacaoEstoque::findOrFail($id);
            $localizacao->delete();
        });
    }
}
