<?php

namespace App\Services;

use App\Enums\LancamentoStatus;
use App\Enums\LancamentoTipo;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\ContaFinanceira;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\LancamentoFinanceiro;
use Illuminate\Support\Carbon;

class FinanceiroExtratoService
{
    public function montar(array $validated): array
    {
        $inicio = Carbon::parse($validated['data_inicio'])->startOfDay();
        $fim = Carbon::parse($validated['data_fim'])->endOfDay();
        $conta = ContaFinanceira::query()->findOrFail((int) $validated['conta_id']);

        $saldoInicial = (float) $conta->saldo_inicial + (float) LancamentoFinanceiro::query()
            ->where('conta_id', $conta->id)
            ->where('status', LancamentoStatus::CONFIRMADO->value)
            ->where('data_movimento', '<', $inicio)
            ->get()
            ->sum(fn (LancamentoFinanceiro $l) => $this->signedValue($l));

        $lancamentos = LancamentoFinanceiro::query()
            ->with(['categoria', 'referencia', 'pagamento'])
            ->where('conta_id', $conta->id)
            ->whereBetween('data_movimento', [$inicio, $fim])
            ->orderBy('data_movimento')
            ->orderBy('id')
            ->get();

        $lancamentos->loadMorph('referencia', [
            ContaPagar::class => ['fornecedor'],
            ContaReceber::class => ['pedido.cliente'],
        ]);
        $lancamentos->loadMorph('pagamento', [
            ContaPagarPagamento::class => ['conta.fornecedor'],
            ContaReceberPagamento::class => ['conta.pedido.cliente'],
        ]);

        $saldo = $saldoInicial;
        $linhas = $lancamentos->map(function (LancamentoFinanceiro $l) use (&$saldo) {
            $valor = $this->signedValue($l);
            if (($l->status?->value ?? $l->status) === LancamentoStatus::CONFIRMADO->value) {
                $saldo += $valor;
            }

            return [
                'data' => optional($l->data_movimento)->format('d/m/Y'),
                'descricao' => (string) $l->descricao,
                'cliente_fornecedor' => $this->clienteFornecedor($l),
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

        return [
            'empresa' => [
                'nome' => config('app.empresa_nome', 'G. P COMERCIO VAREJISTA DE MOVEIS LTDA'),
                'endereco' => config('app.empresa_endereco', 'TV RUI BARBOSA, 1820'),
                'documento' => config('app.empresa_documento', '54.129.336/0001-88'),
                'telefone' => config('app.empresa_telefone', '91984278816'),
            ],
            'conta_dados' => $this->contaDados($conta),
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
        ];
    }

    public function resumo(array $validated): array
    {
        $ids = $validated['conta_ids'] ?? [$validated['conta_id']];
        $ids = collect(is_array($ids) ? $ids : [$ids])
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return $ids->map(function (int $id) use ($validated) {
            $dados = $this->montar([
                'conta_id' => $id,
                'data_inicio' => $validated['data_inicio'],
                'data_fim' => $validated['data_fim'],
            ]);

            return [
                'conta_id' => $id,
                'saldo_inicial' => $dados['resumo']['saldo_inicial'],
                'receitas_realizadas' => $dados['resumo']['receitas_realizadas'],
                'despesas_realizadas' => $dados['resumo']['despesas_realizadas'],
                'total_periodo' => $dados['resumo']['total_periodo'],
                'saldo_final' => $dados['resumo']['saldo_realizado'],
            ];
        })->all();
    }

    private function signedValue(LancamentoFinanceiro $l): float
    {
        $tipo = $l->tipo?->value ?? (string) $l->tipo;
        $valor = (float) $l->valor;

        if ($tipo === LancamentoTipo::DESPESA->value) {
            return -$valor;
        }

        if ($tipo === LancamentoTipo::TRANSFERENCIA->value) {
            return str_contains(strtolower((string) $l->descricao), 'recebida') ? $valor : -$valor;
        }

        return $valor;
    }

    private function statusLabel(LancamentoFinanceiro $l): string
    {
        if (($l->status?->value ?? $l->status) === LancamentoStatus::CANCELADO->value) {
            return 'Cancelado';
        }

        $tipo = $l->tipo?->value ?? (string) $l->tipo;
        return match ($tipo) {
            LancamentoTipo::RECEITA->value => 'Recebido',
            LancamentoTipo::DESPESA->value => 'Pago',
            LancamentoTipo::TRANSFERENCIA->value => 'Transferido',
            default => 'Confirmado',
        };
    }

    private function categoriaFallback(LancamentoFinanceiro $l): string
    {
        $tipo = $l->tipo?->value ?? (string) $l->tipo;
        if ($tipo === LancamentoTipo::TRANSFERENCIA->value) {
            return str_contains(strtolower((string) $l->descricao), 'recebida')
                ? 'Transferencia de Entrada'
                : 'Transferencia de Saida';
        }

        return '-';
    }

    private function clienteFornecedor(LancamentoFinanceiro $l): string
    {
        $referencia = $l->referencia;
        if ($referencia instanceof ContaPagar) {
            return $this->nonEmpty($referencia->fornecedor?->nome);
        }
        if ($referencia instanceof ContaReceber) {
            return $this->nonEmpty($referencia->pedido?->cliente?->nome);
        }

        $pagamento = $l->pagamento;
        if ($pagamento instanceof ContaPagarPagamento) {
            return $this->nonEmpty($pagamento->conta?->fornecedor?->nome);
        }
        if ($pagamento instanceof ContaReceberPagamento) {
            return $this->nonEmpty($pagamento->conta?->pedido?->cliente?->nome);
        }

        return '-';
    }

    private function contaDados(ContaFinanceira $conta): array
    {
        return [
            'nome' => $conta->nome,
            'titular_nome' => $conta->titular_nome ?: config('app.empresa_nome', 'G. P COMERCIO VAREJISTA DE MOVEIS LTDA'),
            'titular_documento' => $conta->titular_documento ?: config('app.empresa_documento', '54.129.336/0001-88'),
            'identificacao_bancaria' => $conta->identificacaoBancaria(),
            'moeda' => $conta->moeda ?: 'BRL',
        ];
    }

    private function nonEmpty(?string $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : '-';
    }
}
