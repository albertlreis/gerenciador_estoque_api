<?php

namespace App\Services;

use App\Enums\ContaStatus;
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
    ) {}

    public function criar(array $dados): ContaPagarResource
    {
        // status inicial: ABERTA (status real virá do sync)
        $dados['status'] = $dados['status'] ?? ContaStatus::ABERTA->value;

        $conta = $this->repo->criar($dados);

        // status sempre refletindo o real (e não permitir sobrescrever CANCELADA, se vier)
        $this->statusSvc->syncPagar($conta->fresh());

        return new ContaPagarResource($conta->fresh(['fornecedor', 'pagamentos.usuario']));
    }

    public function atualizar(ContaPagar $conta, array $dados): ContaPagarResource
    {
        $conta = $this->repo->atualizar($conta, $dados);

        // Garante status real depois de qualquer update
        $this->statusSvc->syncPagar($conta->fresh());

        return new ContaPagarResource($conta->fresh(['fornecedor', 'pagamentos.usuario']));
    }

    public function deletar(ContaPagar $conta): void
    {
        abort_if($this->statusValue($conta) === ContaStatus::PAGA->value, 422, 'Não é possível excluir uma conta já paga.');
        $this->repo->deletar($conta);
    }

    /**
     * Registra pagamento. (Fase 1)
     * - Conta CANCELADA não pode receber pagamento.
     * - Padroniza campos de pagamento.
     */
    public function registrarPagamento(ContaPagar $conta, array $dados): ContaPagarPagamentoResource
    {
        abort_if($this->statusValue($conta) === ContaStatus::CANCELADA->value, 422, 'Conta cancelada não pode receber pagamento.');

        // Se você quer Fase 1 "bem rígida": pagamento sempre exige forma_pagamento
        abort_if(empty($dados['forma_pagamento']), 422, 'Forma de pagamento é obrigatória no pagamento.');

        return DB::transaction(function () use ($conta, $dados) {

            $pagamento = new ContaPagarPagamento([
                'conta_pagar_id'       => $conta->id,
                'data_pagamento'       => $dados['data_pagamento'],
                'valor'                => $dados['valor'],
                'forma_pagamento'      => $dados['forma_pagamento'] ?? null,
                'observacoes'          => $dados['observacoes'] ?? null,
                'usuario_id'           => auth()->id(),
                'conta_financeira_id'  => $dados['conta_financeira_id'] ?? null,
            ]);

            if (!empty($dados['comprovante'])) {
                $pagamento->comprovante_path = $dados['comprovante']->store('financeiro/comprovantes', 'public');
            }

            $pagamento->save();

            // Recalcula status real
            $this->statusSvc->syncPagar($conta->fresh());

            return new ContaPagarPagamentoResource($pagamento->fresh(['usuario', 'contaFinanceira']));
        });
    }

    /**
     * Estorna removendo um pagamento específico.
     * - Remove comprovante no storage.
     * - Recalcula status real.
     * - Se conta está CANCELADA, mantém CANCELADA.
     */
    public function estornarPagamento(ContaPagar $conta, int $pagamentoId): ContaPagarResource
    {
        return DB::transaction(function () use ($conta, $pagamentoId) {

            $statusAntes = $this->statusValue($conta);

            $pagamento = $conta->pagamentos()->findOrFail($pagamentoId);

            if ($pagamento->comprovante_path) {
                Storage::disk('public')->delete($pagamento->comprovante_path);
            }

            $pagamento->delete();

            $conta->refresh();

            // Recalcula status real
            $this->statusSvc->syncPagar($conta->fresh());

            // Regra: CANCELADA não deve ser sobrescrita por sync (garantia extra)
            if ($statusAntes === ContaStatus::CANCELADA->value && $this->statusValue($conta) !== ContaStatus::CANCELADA->value) {
                $conta->status = ContaStatus::CANCELADA->value;
                $conta->save();
            }

            return new ContaPagarResource($conta->fresh(['fornecedor', 'pagamentos.usuario']));
        });
    }

    private function statusValue(ContaPagar $conta): string
    {
        $st = $conta->status;

        if ($st instanceof BackedEnum) return $st->value;

        return (string) $st;
    }
}
