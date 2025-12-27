<?php

namespace App\Repositories\Eloquent;

use App\DTOs\FiltroContaPagarDTO;
use App\Models\ContaPagar;
use App\Repositories\Contracts\ContaPagarRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ContaPagarRepositoryEloquent implements ContaPagarRepository
{
    public function listar(FiltroContaPagarDTO $filtro, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $q = ContaPagar::query()->with(['fornecedor']);

        if ($filtro->busca) {
            $busca = "%{$filtro->busca}%";
            $q->where(fn($w) => $w
                ->where('descricao','like',$busca)
                ->orWhere('numero_documento','like',$busca)
            );
        }
        if ($filtro->fornecedor_id) $q->where('fornecedor_id', $filtro->fornecedor_id);
        if ($filtro->status) $q->where('status', $filtro->status);
        if ($filtro->centro_custo) $q->where('centro_custo', $filtro->centro_custo);
        if ($filtro->categoria) $q->where('categoria', $filtro->categoria);
        if ($filtro->data_ini) $q->whereDate('data_vencimento','>=',$filtro->data_ini);
        if ($filtro->data_fim) $q->whereDate('data_vencimento','<=',$filtro->data_fim);
        if ($filtro->vencidas) $q->whereDate('data_vencimento','<', now()->toDateString())->where('status','!=','PAGA');

        $q->orderBy('data_vencimento', 'desc')->orderBy('id');

        return $q->paginate($perPage, ['*'], 'page', $page);
    }


    public function encontrar(int $id): Builder|array|Collection|Model
    {
        return ContaPagar::with(['fornecedor','pagamentos.usuario'])->find($id);
    }

    public function criar(array $dados): ContaPagar
    {
        return ContaPagar::create($dados);
    }

    public function atualizar(ContaPagar $conta, array $dados): ContaPagar
    {
        $conta->fill($dados)->save();
        return $conta;
    }

    public function deletar(ContaPagar $conta): void
    {
        $conta->delete();
    }
}
