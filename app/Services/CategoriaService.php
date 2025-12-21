<?php

namespace App\Services;

use App\Models\Categoria;
use App\Repositories\CategoriaRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * Camada de negócio para Categoria.
 */
class CategoriaService
{
    public function __construct(
        private readonly CategoriaRepository $repo
    ) {}

    /**
     * @return Collection<int, Categoria>
     */
    public function listar(?string $search): Collection
    {
        return $this->repo->listar($search);
    }

    /**
     * @param array{nome:string,descricao?:string|null,categoria_pai_id?:int|null} $data
     */
    public function criar(array $data): Categoria
    {
        return $this->repo->create($data);
    }

    /**
     * @param array{nome?:string,descricao?:string|null,categoria_pai_id?:int|null} $data
     */
    public function atualizar(Categoria $categoria, array $data): Categoria
    {
        return $this->repo->update($categoria, $data);
    }

    public function remover(Categoria $categoria): void
    {
        $this->repo->delete($categoria);
    }
}
