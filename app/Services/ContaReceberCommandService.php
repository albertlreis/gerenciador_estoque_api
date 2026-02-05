<?php

namespace App\Services;

use App\Enums\ContaStatus;
use App\Enums\LancamentoTipo;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ContaReceberCommandService
{
    public function __construct(
        private readonly ContaStatusService $statusSvc,
        private readonly FinanceiroLedgerService $ledger,
        private readonly FinanceiroAuditoriaService $audit,
    ) {}

    public function criar(array $dados): ContaReceber
    {
        return DB::transaction(function () use ($dados) {

            $dados['status'] = $dados['status'] ?? ContaStatus::ABERTA->value;

            $conta = ContaReceber::create($dados);

            $this->syncValores($conta);
            $this->statusSvc->syncReceber($conta->fresh());

            $fresh = $conta->fresh();
            $this->audit->log('created', $conta, null, $fresh->toArray());

            return $fresh;
        });
    }

    public function atualizar(ContaReceber $conta, array $dados): ContaReceber
    {
        return DB::transaction(function () use ($conta, $dados) {

            $antes = $conta->fresh()->toArray();

            $conta->fill($dados)->save();

            $this->syncValores($conta);
            $this->statusSvc->syncReceber($conta->fresh());

            $fresh = $conta->fresh();
            $this->audit->log('updated', $conta, $antes, $fresh->toArray());

            return $fresh;
        });
    }

    public function deletar(ContaReceber $conta): void
    {
        abort_if($this->statusValue($conta) === ContaStatus::PAGA->value, 422, 'Não é possível excluir uma conta já paga.');

        $antes = $conta->fresh()->toArray();

        $conta->delete();

        $this->audit->log('deleted', $conta, $antes, null);
    }

    public function registrarPagamento(ContaReceber $conta, array $dados): ContaReceberPagamento
    {
        abort_if($this->statusValue($conta) === ContaStatus::CANCELADA->value, 422, 'Conta cancelada não pode receber pagamento.');
        abort_if(empty($dados['forma_pagamento']), 422, 'Forma de pagamento é obrigatória no pagamento.');
        abort_if(empty($dados['conta_financeira_id']), 422, 'Conta financeira é obrigatória no pagamento.');

        return DB::transaction(function () use ($conta, $dados) {

            $antesConta = $conta->fresh()->toArray();

            $pagamento = new ContaReceberPagamento([
                'conta_receber_id'     => $conta->id,
                'data_pagamento'       => $dados['data_pagamento'],
                'valor'                => $dados['valor'],
                'forma_pagamento'      => $dados['forma_pagamento'],
                'observacoes'          => $dados['observacoes'] ?? null,
                'usuario_id'           => auth()->id(),
                'conta_financeira_id'  => $dados['conta_financeira_id'],
            ]);

            if (!empty($dados['comprovante'])) {
                $pagamento->comprovante_path = $dados['comprovante']->store('financeiro/comprovantes', 'public');
            }

            $pagamento->save();

            $this->syncValores($conta->fresh());
            // Ledger (Fase 2 - Caminho A): RECEITA confirmada vinculada ao pagamento (idempotente)
            $lancamento = $this->ledger->criarLancamentoPorPagamento(
                tipo: LancamentoTipo::RECEITA->value,
                descricao: "Recebimento Conta a Receber #{$conta->id} - {$conta->descricao}",
                valor: (float) $pagamento->valor,
                contaFinanceiraId: (int) $pagamento->conta_financeira_id,
                categoriaId: $conta->categoria_id ?? null,
                centroCustoId: $conta->centro_custo_id ?? null,
                dataMovimento: $pagamento->data_pagamento,
                referencia: $conta,
                pagamento: $pagamento,
            );

            $this->statusSvc->syncReceber($conta->fresh());

            $depoisConta = $conta->fresh()->toArray();

            $this->audit->log('received', $conta, $antesConta, $depoisConta);
            $this->audit->log('ledger_created', $lancamento, null, $lancamento->fresh()->toArray());

            return $pagamento->fresh(['usuario', 'contaFinanceira']);
        });
    }

    public function estornarPagamento(ContaReceber $conta, int $pagamentoId): ContaReceber
    {
        return DB::transaction(function () use ($conta, $pagamentoId) {

            $antesConta = $conta->fresh()->toArray();

            $pagamento = $conta->pagamentos()->findOrFail($pagamentoId);

            // Ledger (Fase 2 - Caminho A): cancela lançamento vinculado ao pagamento
            $this->ledger->cancelarLancamentoPorPagamento($pagamento);

            if ($pagamento->comprovante_path) {
                Storage::disk('public')->delete($pagamento->comprovante_path);
            }

            $pagamento->delete();

            $this->syncValores($conta->fresh());
            $this->statusSvc->syncReceber($conta->fresh());

            $fresh = $conta->fresh(['pedido.cliente', 'pagamentos.usuario']);

            $this->audit->log('reversed', $conta, $antesConta, $fresh->toArray());
            $this->audit->log('ledger_canceled', $pagamento, null, [
                'pagamento_id' => $pagamentoId,
                'pagamento_type' => get_class($pagamento),
            ]);

            return $fresh;
        });
    }

    private function statusValue(ContaReceber $conta): string
    {
        $st = $conta->status;
        if ($st instanceof BackedEnum) return $st->value;
        return (string) $st;
    }

    private function syncValores(ContaReceber $conta): void
    {
        $valorLiquido = (float)($conta->valor_bruto - $conta->desconto + $conta->juros + $conta->multa);
        $valorRecebido = (float) $conta->pagamentos()->sum('valor');
        $saldoAberto = max(0.0, $valorLiquido - $valorRecebido);

        $conta->valor_liquido = $valorLiquido;
        $conta->valor_recebido = $valorRecebido;
        $conta->saldo_aberto = $saldoAberto;
        $conta->saveQuietly();
    }
}
