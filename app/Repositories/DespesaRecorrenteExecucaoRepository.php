<?php

namespace App\Repositories;

use App\Models\DespesaRecorrenteExecucao;
use Illuminate\Database\Eloquent\Builder;

class DespesaRecorrenteExecucaoRepository
{
    /** @return Builder<DespesaRecorrenteExecucao> */
    public function queryBase(int $despesaId): Builder
    {
        return DespesaRecorrenteExecucao::query()
            ->where('despesa_recorrente_id', $despesaId)
            ->orderByDesc('competencia');
    }

    public function existsByCompetencia(int $despesaId, string $competenciaYmd): bool
    {
        return DespesaRecorrenteExecucao::query()
            ->where('despesa_recorrente_id', $despesaId)
            ->whereDate('competencia', $competenciaYmd)
            ->exists();
    }

    public function create(array $data): DespesaRecorrenteExecucao
    {
        return DespesaRecorrenteExecucao::create($data);
    }
}
