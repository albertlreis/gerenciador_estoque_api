<?php

namespace App\Repositories;

use App\DTOs\FiltroLancamentoFinanceiroDTO;
use App\Models\LancamentoFinanceiro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class LancamentoFinanceiroRepository
{
    /** @return Builder<LancamentoFinanceiro> */
    public function queryBase(FiltroLancamentoFinanceiroDTO $f): Builder
    {
        $q = LancamentoFinanceiro::query()
            ->with(['categoria', 'conta', 'criador', 'centroCusto']);

        /**
         * Período
         * - Ledger deve filtrar por data_movimento (principal)
         * - Se você quiser permitir filtrar por data_pagamento também, dá pra adicionar um flag no DTO.
         */
        if ($f->dataInicio) {
            $q->where('data_movimento', '>=', Carbon::parse($f->dataInicio)->startOfDay());
        }
        if ($f->dataFim) {
            $q->where('data_movimento', '<=', Carbon::parse($f->dataFim)->endOfDay());
        }

        // Filtros simples (normalmente DTO já vem sanitizado)
        if ($f->status) {
            $q->where('status', strtolower($f->status));
        }

        if ($f->tipo) {
            $q->where('tipo', strtolower($f->tipo));
        }

        if ($f->categoriaId) {
            $q->where('categoria_id', $f->categoriaId);
        }

        if ($f->contaId) {
            $q->where('conta_id', $f->contaId);
        }

        /**
         * Atrasado:
         * - NÃO faz sentido em lancamentos_financeiros (ledger).
         * - Atraso pertence a contas_pagar/contas_receber (vencimento).
         *
         * Então aqui:
         * - se vier atrasado=true/false, vamos IGNORAR silenciosamente
         *   (ou você pode lançar ValidationException no controller/DTO se preferir).
         */

        // Busca textual (escape do LIKE para evitar %/_ como curingas inesperados)
        if ($f->q) {
            $term = $this->escapeLike($f->q);
            $q->where(function ($w) use ($term) {
                $w->where('descricao', 'like', "%{$term}%")
                    ->orWhere('observacoes', 'like', "%{$term}%");
            });
        }

        // Ordenação (whitelist) — default data_movimento
        $allowedOrderBy = ['id','data_movimento','data_pagamento','competencia','valor','created_at'];
        $orderBy = in_array($f->orderBy, $allowedOrderBy, true) ? $f->orderBy : 'data_movimento';
        $orderDir = strtolower($f->orderDir) === 'asc' ? 'asc' : 'desc';

        return $q->orderBy($orderBy, $orderDir);
    }

    public function findOrFail(int $id): Builder|array|Collection|Model
    {
        return LancamentoFinanceiro::with(['categoria', 'conta', 'criador', 'centroCusto'])->findOrFail($id);
    }

    public function create(array $data): LancamentoFinanceiro
    {
        return LancamentoFinanceiro::create($data);
    }

    public function update(LancamentoFinanceiro $model, array $data): LancamentoFinanceiro
    {
        $model->fill($data)->save();
        return $model->fresh(['categoria','conta','criador','centroCusto']);
    }

    public function delete(LancamentoFinanceiro $model): void
    {
        $model->delete();
    }

    private function escapeLike(string $value, string $escapeChar = '\\'): string
    {
        return str_replace(
            [$escapeChar, '%', '_'],
            [$escapeChar.$escapeChar, $escapeChar.'%', $escapeChar.'_'],
            $value
        );
    }
}
