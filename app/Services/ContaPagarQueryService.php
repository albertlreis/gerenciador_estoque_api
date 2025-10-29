<?php

namespace App\Services;

use App\DTOs\FiltroContaPagarDTO;
use App\Http\Resources\ContaPagarResource;
use App\Repositories\Contracts\ContaPagarRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;


class ContaPagarQueryService
{
    public function __construct(private readonly ContaPagarRepository $repo) {}


    public function listar(FiltroContaPagarDTO $filtro, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repo->listar($filtro, $page, $perPage);
    }


    public function encontrarResource(int $id): ContaPagarResource
    {
        $conta = $this->repo->encontrar($id);
        abort_if(!$conta, 404, 'Conta não encontrada');
        return new ContaPagarResource($conta);
    }
}
