<?php

namespace App\Services;

use App\Enums\ContaStatus;
use App\Enums\LancamentoTipo;
use App\Http\Resources\ContaPagarResource;
use App\Http\Resources\ContaPagarPagamentoResource;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Repositories\Contracts\ContaPagarRepository;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ContaPagarCommandService
{
    public function __construct(
        private readonly ContaPagarRepository $repo,
        private readonly ContaStatusService $statusSvc,
        private readonly FinanceiroLedgerService $ledger,
        private readonly FinanceiroAuditoriaService $audit,
    ) {}

    public function criar(array $dados): ContaPagarResource
    {
        $dados['status'] = $dados['status'] ?? ContaStatus::ABERTA->value;

        $conta = $this->repo->criar($dados);

        $this->statusSvc->syncPagar($conta->fresh());

        $fresh = $conta->fresh(['fornecedor', 'pagamentos.usuario']);
        $this->audit->log('created', $conta, null, $fresh->toArray());

        return new ContaPagarResource($fresh);
    }

    public function atualizar(ContaPagar $conta, array $dados): ContaPagarResource
    {
        $antes = $conta->fresh(['fornecedor', 'pagamentos.usuario'])->toArray();

        $conta = $this->repo->atualizar($conta, $dados);

        $this->statusSvc->syncPagar($conta->fresh());

        $fresh = $conta->fresh(['fornecedor', 'pagamentos.usuario']);
        $this->audit->log('updated', $conta, $antes, $fresh->toArray());

        return new ContaPagarResource($fresh);
    }

    public function deletar(ContaPagar $conta): void
    {
        abort_if($this->statusValue($conta) === ContaStatus::PAGA->value, 422, 'Não é possível excluir uma conta já paga.');

        $antes = $conta->fresh(['fornecedor', 'pagamentos.usuario'])->toArray();

        $this->repo->deletar($conta);

        $this->audit->log('deleted', $conta, $antes, null);
    }

    public function registrarPagamento(ContaPagar $conta, array $dados): ContaPagarPagamentoResource
    {
        abort_if($this->statusValue($conta) === ContaStatus::CANCELADA->value, 422, 'Conta cancelada não pode receber pagamento.');
        abort_if(empty($dados['forma_pagamento']), 422, 'Forma de pagamento é obrigatória no pagamento.');
        abort_if(empty($dados['conta_financeira_id']), 422, 'Conta financeira é obrigatória no pagamento.');

        return DB::transaction(function () use ($conta, $dados) {

            $antesConta = $conta->fresh(['fornecedor', 'pagamentos.usuario'])->toArray();

            $pagamento = new ContaPagarPagamento([
                'conta_pagar_id'      => $conta->id,
                'data_pagamento'      => $dados['data_pagamento'],
                'valor'               => $dados['valor'],
                'forma_pagamento'     => $dados['forma_pagamento'],
                'observacoes'         => $dados['observacoes'] ?? null,
                'usuario_id'          => auth()->id(),
                'conta_financeira_id' => $dados['conta_financeira_id'],
            ]);

            if (!empty($dados['comprovante'])) {
                $pagamento->comprovante_path = $dados['comprovante']->store('financeiro/comprovantes', 'public');
            }

            $pagamento->save();

            // Ledger (Fase 2 - Caminho A): DESPESA confirmada vinculada ao pagamento (idempotente)
            $lancamento = $this->ledger->criarLancamentoPorPagamento(
                tipo: LancamentoTipo::DESPESA->value,
                descricao: "Pagamento Conta a Pagar #{$conta->id} - {$conta->descricao}",
                valor: (float) $pagamento->valor,
                contaFinanceiraId: (int) $pagamento->conta_financeira_id,
                categoriaId: $conta->categoria_id ?? null,
                centroCustoId: $conta->centro_custo_id ?? null,
                dataMovimento: $pagamento->data_pagamento,
                referencia: $conta,
                pagamento: $pagamento,
            );

            $this->statusSvc->syncPagar($conta->fresh());

            $depoisConta = $conta->fresh(['fornecedor', 'pagamentos.usuario'])->toArray();

            $this->audit->log('paid', $conta, $antesConta, $depoisConta);
            $this->audit->log('ledger_created', $lancamento, null, $lancamento->fresh()->toArray());

            return new ContaPagarPagamentoResource($pagamento->fresh(['usuario', 'contaFinanceira']));
        });
    }

    public function estornarPagamento(ContaPagar $conta, int $pagamentoId): ContaPagarResource
    {
        return DB::transaction(function () use ($conta, $pagamentoId) {

            $antesConta  = $conta->fresh(['fornecedor', 'pagamentos.usuario'])->toArray();
            $statusAntes = $this->statusValue($conta);

            $pagamento = $conta->pagamentos()->findOrFail($pagamentoId);

            // Ledger (Fase 2 - Caminho A): cancela lançamento vinculado ao pagamento
            $this->ledger->cancelarLancamentoPorPagamento($pagamento);

            if ($pagamento->comprovante_path) {
                Storage::disk('public')->delete($pagamento->comprovante_path);
            }

            $pagamento->delete();

            $conta->refresh();
            $this->statusSvc->syncPagar($conta->fresh());

            // Regra extra: CANCELADA não pode ser sobrescrita por sync
            if ($statusAntes === ContaStatus::CANCELADA->value && $this->statusValue($conta) !== ContaStatus::CANCELADA->value) {
                $conta->status = ContaStatus::CANCELADA->value;
                $conta->save();
            }

            $fresh = $conta->fresh(['fornecedor', 'pagamentos.usuario']);

            $this->audit->log('reversed', $conta, $antesConta, $fresh->toArray());
            $this->audit->log('ledger_canceled', $pagamento, null, [
                'pagamento_id' => $pagamentoId,
                'pagamento_type' => get_class($pagamento),
            ]);

            return new ContaPagarResource($fresh);
        });
    }

    private function statusValue(ContaPagar $conta): string
    {
        $st = $conta->status;
        if ($st instanceof BackedEnum) return $st->value;
        return (string) $st;
    }
}
