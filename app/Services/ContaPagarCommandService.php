<?php

namespace App\Services;

use App\Enums\ContaPagarStatus;
use App\Http\Resources\ContaPagarResource;
use App\Http\Resources\ContaPagarPagamentoResource;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Repositories\Contracts\ContaPagarRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ContaPagarCommandService
{
    public function __construct(private readonly ContaPagarRepository $repo) {}

    public function criar(array $dados): ContaPagarResource
    {
        $dados['status'] = $dados['status'] ?? ContaPagarStatus::ABERTA->value;
        $conta = $this->repo->criar($dados);
        return new ContaPagarResource($conta->fresh(['fornecedor']));
    }

    public function atualizar(ContaPagar $conta, array $dados): ContaPagarResource
    {
        // Não permitir alterar status para PAGA/CANCELADA diretamente se há inconsistência
        $conta = $this->repo->atualizar($conta, $dados);
        $this->sincronizarStatus($conta);
        return new ContaPagarResource($conta->fresh(['fornecedor','pagamentos']));
    }

    public function deletar(ContaPagar $conta): void
    {
        abort_if($conta->status === ContaPagarStatus::PAGA, 422, 'Não é possível excluir uma conta já paga.');
        $this->repo->deletar($conta);
    }

    public function registrarPagamento(ContaPagar $conta, array $dados): ContaPagarPagamentoResource
    {
        return DB::transaction(function () use ($conta, $dados) {
            $pagamento = new ContaPagarPagamento([
                'conta_pagar_id' => $conta->id,
                'data_pagamento' => $dados['data_pagamento'],
                'valor' => $dados['valor'],
                'forma_pagamento' => $dados['forma_pagamento'] ?? $conta->forma_pagamento,
                'observacoes' => $dados['observacoes'] ?? null,
                'usuario_id' => auth()->id(),
            ]);

            if (!empty($dados['comprovante'])) {
                $path = $dados['comprovante']->store('financeiro/comprovantes', 'public');
                $pagamento->comprovante_path = $path;
            }

            $pagamento->save();

            $this->sincronizarStatus($conta->fresh());

            return new ContaPagarPagamentoResource($pagamento);
        });
    }

    public function estornarPagamento(ContaPagar $conta, int $pagamentoId): ContaPagarResource
    {
        return DB::transaction(function () use ($conta, $pagamentoId) {
            $pagamento = $conta->pagamentos()->findOrFail($pagamentoId);
            if ($pagamento->comprovante_path) {
                Storage::disk('public')->delete($pagamento->comprovante_path);
            }
            $pagamento->delete();


            $this->sincronizarStatus($conta->fresh());


            return new ContaPagarResource($conta->fresh(['fornecedor','pagamentos']));
        });
    }

    private function sincronizarStatus(ContaPagar $conta): void
    {
        $saldo = (float) $conta->saldo_aberto;
        $novo = $saldo <= 0.00001 ? ContaPagarStatus::PAGA : ($conta->pagamentos()->exists() ? ContaPagarStatus::PARCIAL : ContaPagarStatus::ABERTA);
        if ($conta->status !== $novo) {
            $conta->status = $novo;
            $conta->save();
        }
    }
}
