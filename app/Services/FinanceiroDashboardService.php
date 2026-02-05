<?php
namespace App\Services;

use App\Models\LancamentoFinanceiro;
use Illuminate\Support\Carbon;

class FinanceiroDashboardService
{
    /**
     * Retorna um resumo simples para o dashboard:
     * - receitas_total (somente lançamentos pagos dentro do período)
     * - despesas_total (somente lançamentos pagos dentro do período)
     * - saldo (receitas - despesas)
     * - serie (placeholder para futura evolução)
     *
     * @param array{data_inicio?:string|null,data_fim?:string|null} $f
     * @return array{receitas_total:string,despesas_total:string,saldo:string,serie:array<int,mixed>}
     */
    public function resumo(array $f): array
    {
        $inicio = !empty($f['data_inicio']) ? Carbon::createFromFormat('Y-m-d', $f['data_inicio'])->startOfDay() : null;
        $fim    = !empty($f['data_fim']) ? Carbon::createFromFormat('Y-m-d', $f['data_fim'])->endOfDay() : null;

        $base = LancamentoFinanceiro::query()
            ->where('status', 'confirmado')
            ->when($inicio, fn($q) => $q->where('data_movimento', '>=', $inicio))
            ->when($fim, fn($q) => $q->where('data_movimento', '<=', $fim));

        $receitas = (clone $base)->where('tipo', 'receita')->sum('valor');
        $despesas = (clone $base)->where('tipo', 'despesa')->sum('valor');

        $saldo = (float)$receitas - (float)$despesas;

        return [
            'receitas_total' => number_format((float)$receitas, 2, '.', ''),
            'despesas_total' => number_format((float)$despesas, 2, '.', ''),
            'saldo'          => number_format((float)$saldo, 2, '.', ''),
            'serie'          => [], // placeholder (ex.: série diária/mensal)
        ];
    }
}
