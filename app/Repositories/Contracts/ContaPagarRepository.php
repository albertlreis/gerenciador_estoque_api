<?php

namespace App\Repositories\Contracts;

use App\DTOs\FiltroContaPagarDTO;
use App\Models\ContaPagar;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;


interface ContaPagarRepository
{
    public function listar(FiltroContaPagarDTO $filtro, int $page = 1, int $perPage = 15): LengthAwarePaginator;
    public function encontrar(int $id): ?ContaPagar;
    public function criar(array $dados): ContaPagar;
    public function atualizar(ContaPagar $conta, array $dados): ContaPagar;
    public function deletar(ContaPagar $conta): void;
}
