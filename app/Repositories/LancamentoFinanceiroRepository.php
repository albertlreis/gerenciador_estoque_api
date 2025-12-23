<?php

namespace App\Repositories;

use App\DTOs\FiltroLancamentoFinanceiroDTO;
use App\Models\LancamentoFinanceiro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class LancamentoFinanceiroRepository
{
    /** @return Builder<LancamentoFinanceiro> */
    public function queryBase(FiltroLancamentoFinanceiroDTO $f): Builder
    {
        $q = LancamentoFinanceiro::query()
            ->with(['categoria', 'conta', 'criador']);

        // Período (por data_vencimento)
        if ($f->dataInicio) {
            $q->where('data_vencimento', '>=', Carbon::parse($f->dataInicio)->startOfDay());
        }
        if ($f->dataFim) {
            $q->where('data_vencimento', '<=', Carbon::parse($f->dataFim)->endOfDay());
        }

        // Filtros simples
        if ($f->status) {
            $q->where('status', $f->status);
        }
        if ($f->tipo) {
            $q->where('tipo', $f->tipo);
        }
        if ($f->categoriaId) {
            $q->where('categoria_id', $f->categoriaId);
        }
        if ($f->contaId) {
            $q->where('conta_id', $f->contaId);
        }

        // Atrasado (derivado): pendente + vencido
        if ($f->atrasado !== null) {
            if ($f->atrasado === true) {
                $q->where('status', 'pendente')
                    ->where('data_vencimento', '<', now());
            } else {
                // "não atrasado" => ou não pendente, ou vencimento >= hoje
                $q->where(function ($w) {
                    $w->where('status', '!=', 'pendente')
                        ->orWhere('data_vencimento', '>=', now());
                });
            }
        }

        // Busca textual
        if ($f->q) {
            $term = $f->q;
            $q->where(function ($w) use ($term) {
                $w->where('descricao', 'like', "%{$term}%")
                    ->orWhere('observacoes', 'like', "%{$term}%");
            });
        }

        // Ordenação
        $orderBy = in_array($f->orderBy, ['data_vencimento','data_pagamento','valor','created_at','id'], true)
            ? $f->orderBy
            : 'data_vencimento';

        $orderDir = strtolower($f->orderDir) === 'asc' ? 'asc' : 'desc';

        return $q->orderBy($orderBy, $orderDir);
    }

    public function findOrFail(int $id): LancamentoFinanceiro
    {
        return LancamentoFinanceiro::with(['categoria', 'conta', 'criador'])->findOrFail($id);
    }

    public function create(array $data): LancamentoFinanceiro
    {
        return LancamentoFinanceiro::create($data);
    }

    public function update(LancamentoFinanceiro $model, array $data): LancamentoFinanceiro
    {
        $model->fill($data);
        $model->save();
        return $model->fresh(['categoria','conta','criador']);
    }

    public function delete(LancamentoFinanceiro $model): void
    {
        $model->delete();
    }
}
