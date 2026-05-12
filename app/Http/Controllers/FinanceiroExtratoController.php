<?php

namespace App\Http\Controllers;

use App\Enums\LancamentoStatus;
use App\Enums\LancamentoTipo;
use App\Models\ContaFinanceira;
use App\Models\LancamentoFinanceiro;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class FinanceiroExtratoController extends Controller
{
    public function exportPdf(Request $request): Response
    {
        $validated = $request->validate([
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'conta_id' => ['required', 'integer', 'exists:contas_financeiras,id'],
        ]);

        $inicio = Carbon::parse($validated['data_inicio'])->startOfDay();
        $fim = Carbon::parse($validated['data_fim'])->endOfDay();
        $conta = ContaFinanceira::query()->findOrFail((int)$validated['conta_id']);

        $saldoInicial = (float)$conta->saldo_inicial + (float)LancamentoFinanceiro::query()
            ->where('conta_id', $conta->id)
            ->where('status', LancamentoStatus::CONFIRMADO->value)
            ->where('data_movimento', '<', $inicio)
            ->get()
            ->sum(fn (LancamentoFinanceiro $l) => $this->signedValue($l));

        $lancamentos = LancamentoFinanceiro::query()
            ->with(['categoria'])
            ->where('conta_id', $conta->id)
            ->whereBetween('data_movimento', [$inicio, $fim])
            ->orderBy('data_movimento')
            ->orderBy('id')
            ->get();

        $saldo = $saldoInicial;
        $linhas = $lancamentos->map(function (LancamentoFinanceiro $l) use (&$saldo) {
            $valor = $this->signedValue($l);
            if (($l->status?->value ?? $l->status) === LancamentoStatus::CONFIRMADO->value) {
                $saldo += $valor;
            }

            return [
                'data' => optional($l->data_movimento)->format('d/m/Y'),
                'descricao' => (string)$l->descricao,
                'cliente_fornecedor' => '-',
                'situacao' => $this->statusLabel($l),
                'categoria' => $l->categoria?->nome ?: $this->categoriaFallback($l),
                'valor' => $valor,
                'saldo' => $saldo,
                'cancelado' => ($l->status?->value ?? $l->status) === LancamentoStatus::CANCELADO->value,
            ];
        });

        $receitasRealizadas = $linhas->where('valor', '>', 0)->where('cancelado', false)->sum('valor');
        $despesasRealizadas = abs($linhas->where('valor', '<', 0)->where('cancelado', false)->sum('valor'));
        $totalPeriodo = $receitasRealizadas - $despesasRealizadas;
        $cancelados = abs($linhas->where('cancelado', true)->sum('valor'));

        $pdf = Pdf::loadView('pdf.financeiro-extrato', [
            'empresa' => [
                'nome' => config('app.empresa_nome', 'G. P COMERCIO VAREJISTA DE MOVEIS LTDA'),
                'endereco' => config('app.empresa_endereco', 'TV RUI BARBOSA, 1820'),
                'documento' => config('app.empresa_documento', '54.129.336/0001-88'),
                'telefone' => config('app.empresa_telefone', '91984278816'),
            ],
            'conta' => $conta,
            'periodo' => [
                'inicio' => $inicio->format('d/m/Y'),
                'fim' => Carbon::parse($validated['data_fim'])->format('d/m/Y'),
            ],
            'linhas' => $linhas,
            'resumo' => [
                'receitas_abertas' => 0,
                'receitas_realizadas' => $receitasRealizadas,
                'despesas_abertas' => 0,
                'despesas_realizadas' => $despesasRealizadas,
                'total_periodo' => $totalPeriodo,
                'desconsiderados' => 0,
                'perdidos' => $cancelados,
                'saldo_realizado' => $saldo,
                'saldo_inicial' => $saldoInicial,
            ],
            'usuario' => auth()->user()?->nome ?? auth()->user()?->name ?? '-',
        ])->setPaper('a4', 'landscape');

        return $pdf->download('relatorio_extrato_financeiro.pdf');
    }

    private function signedValue(LancamentoFinanceiro $l): float
    {
        $tipo = $l->tipo?->value ?? (string)$l->tipo;
        $valor = (float)$l->valor;

        if ($tipo === LancamentoTipo::DESPESA->value) {
            return -$valor;
        }

        if ($tipo === LancamentoTipo::TRANSFERENCIA->value) {
            return str_contains(strtolower((string)$l->descricao), 'recebida') ? $valor : -$valor;
        }

        return $valor;
    }

    private function statusLabel(LancamentoFinanceiro $l): string
    {
        if (($l->status?->value ?? $l->status) === LancamentoStatus::CANCELADO->value) {
            return 'Cancelado';
        }

        $tipo = $l->tipo?->value ?? (string)$l->tipo;
        return match ($tipo) {
            LancamentoTipo::RECEITA->value => 'Recebido',
            LancamentoTipo::DESPESA->value => 'Pago',
            LancamentoTipo::TRANSFERENCIA->value => 'Transferido',
            default => 'Confirmado',
        };
    }

    private function categoriaFallback(LancamentoFinanceiro $l): string
    {
        $tipo = $l->tipo?->value ?? (string)$l->tipo;
        if ($tipo === LancamentoTipo::TRANSFERENCIA->value) {
            return str_contains(strtolower((string)$l->descricao), 'recebida')
                ? 'Transferência de Entrada'
                : 'Transferência de Saída';
        }

        return '-';
    }
}
