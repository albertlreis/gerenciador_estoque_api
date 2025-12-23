<?php

namespace App\Services;

use App\Enums\ContaStatus;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ContaReceberCommandService
{
    public function __construct(
        private readonly ContaStatusService $statusSvc,
    ) {}

    public function criar(array $dados): ContaReceber
    {
        return DB::transaction(function () use ($dados) {
            $dados['status'] = $dados['status'] ?? ContaStatus::ABERTA->value;
            $conta = ContaReceber::create($dados);
            $this->statusSvc->syncReceber($conta->fresh());
            return $conta->fresh();
        });
    }

    public function atualizar(ContaReceber $conta, array $dados): ContaReceber
    {
        return DB::transaction(function () use ($conta, $dados) {
            $conta->fill($dados)->save();
            $this->statusSvc->syncReceber($conta->fresh());
            return $conta->fresh();
        });
    }

    public function deletar(ContaReceber $conta): void
    {
        abort_if($conta->status === ContaStatus::PAGA, 422, 'Não é possível excluir uma conta já paga.');
        $conta->delete();
    }

    public function registrarPagamento(ContaReceber $conta, array $dados): ContaReceberPagamento
    {
        abort_if($conta->status === ContaStatus::CANCELADA, 422, 'Conta cancelada não pode receber pagamento.');

        return DB::transaction(function () use ($conta, $dados) {

            $pagamento = new ContaReceberPagamento([
                'conta_receber_id' => $conta->id,
                'data_pagamento' => $dados['data_pagamento'],
                'valor' => $dados['valor'],
                'forma_pagamento' => $dados['forma_pagamento'],
                'observacoes' => $dados['observacoes'] ?? null,
                'usuario_id' => auth()->id(),
                'conta_financeira_id' => $dados['conta_financeira_id'] ?? null,
            ]);

            if (!empty($dados['comprovante'])) {
                $pagamento->comprovante_path = $dados['comprovante']->store('financeiro/comprovantes', 'public');
            }

            $pagamento->save();

            $this->statusSvc->syncReceber($conta->fresh());

            return $pagamento->fresh(['usuario','contaFinanceira']);
        });
    }

    public function estornarPagamento(ContaReceber $conta, int $pagamentoId): ContaReceber
    {
        return DB::transaction(function () use ($conta, $pagamentoId) {

            $pagamento = $conta->pagamentos()->findOrFail($pagamentoId);

            if ($pagamento->comprovante_path) {
                Storage::disk('public')->delete($pagamento->comprovante_path);
            }

            $pagamento->delete();

            $this->statusSvc->syncReceber($conta->fresh());

            return $conta->fresh(['pedido.cliente','pagamentos.usuario']);
        });
    }
}
