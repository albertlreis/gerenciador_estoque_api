<?php

namespace App\Services;

use App\Enums\ContaStatus;
use App\Enums\LancamentoTipo;
use App\Http\Resources\ContaPagarPagamentoResource;
use App\Http\Resources\ContaPagarResource;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Repositories\Contracts\ContaPagarRepository;
use App\Support\Audit\AuditLogger;
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
        private readonly AuditLogger $auditLogger,
    ) {}

    public function criar(array $dados): ContaPagarResource
    {
        $dados['status'] = $dados['status'] ?? ContaStatus::ABERTA->value;

        $conta = $this->repo->criar($dados);
        $this->statusSvc->syncPagar($conta->fresh());

        $fresh = $conta->fresh(['fornecedor', 'pagamentos.usuario']);
        $this->audit->log('created', $conta, null, $fresh->toArray());
        $this->auditLogger->logCreate($conta, 'financeiro', "Conta a pagar criada: #{$conta->id}");

        return new ContaPagarResource($fresh);
    }

    public function atualizar(ContaPagar $conta, array $dados): ContaPagarResource
    {
        $antes = $conta->fresh(['fornecedor', 'pagamentos.usuario'])->toArray();
        $beforeAttrs = $conta->getAttributes();

        $conta = $this->repo->atualizar($conta, $dados);
        $this->statusSvc->syncPagar($conta->fresh());

        $fresh = $conta->fresh(['fornecedor', 'pagamentos.usuario']);
        $this->audit->log('updated', $conta, $antes, $fresh->toArray());
        $this->auditLogger->logUpdate(
            $conta,
            'financeiro',
            "Conta a pagar atualizada: #{$conta->id}",
            [
                '__before' => $beforeAttrs,
                '__dirty' => $this->diffDirty($beforeAttrs, $conta->getAttributes()),
            ]
        );

        return new ContaPagarResource($fresh);
    }

    public function deletar(ContaPagar $conta): void
    {
        abort_if($this->statusValue($conta) === ContaStatus::PAGA->value, 422, 'Nao e possivel excluir uma conta ja paga.');

        $antes = $conta->fresh(['fornecedor', 'pagamentos.usuario'])->toArray();

        $this->auditLogger->logDelete(
            $conta,
            'financeiro',
            "Conta a pagar removida: #{$conta->id}"
        );

        $this->repo->deletar($conta);
        $this->audit->log('deleted', $conta, $antes, null);
    }

    public function registrarPagamento(ContaPagar $conta, array $dados): ContaPagarPagamentoResource
    {
        abort_if($this->statusValue($conta) === ContaStatus::CANCELADA->value, 422, 'Conta cancelada nao pode receber pagamento.');
        abort_if(empty($dados['forma_pagamento']), 422, 'Forma de pagamento e obrigatoria no pagamento.');
        abort_if(empty($dados['conta_financeira_id']), 422, 'Conta financeira e obrigatoria no pagamento.');

        return DB::transaction(function () use ($conta, $dados) {
            $antesConta = $conta->fresh(['fornecedor', 'pagamentos.usuario'])->toArray();
            $statusAntes = $this->statusValue($conta);

            $pagamento = new ContaPagarPagamento([
                'conta_pagar_id' => $conta->id,
                'data_pagamento' => $dados['data_pagamento'],
                'valor' => $dados['valor'],
                'forma_pagamento' => $dados['forma_pagamento'],
                'observacoes' => $dados['observacoes'] ?? null,
                'usuario_id' => auth()->id(),
                'conta_financeira_id' => $dados['conta_financeira_id'],
            ]);

            if (!empty($dados['comprovante'])) {
                $pagamento->comprovante_path = $dados['comprovante']->store('financeiro/comprovantes', 'public');
            }

            $pagamento->save();

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

            $statusNovo = $this->statusValue($conta->fresh());
            $this->auditLogger->logCustom(
                'ContaPagar',
                $conta->id,
                'financeiro',
                'STATUS_CHANGE',
                "Baixa em conta a pagar #{$conta->id}",
                [
                    'status' => [
                        'old' => $statusAntes,
                        'new' => $statusNovo,
                    ],
                    'valor_pago' => [
                        'old' => null,
                        'new' => (float) $pagamento->valor,
                    ],
                ],
                [
                    'pagamento_id' => $pagamento->id,
                    'forma_pagamento' => $pagamento->forma_pagamento?->value ?? $pagamento->forma_pagamento,
                ]
            );

            return new ContaPagarPagamentoResource($pagamento->fresh(['usuario', 'contaFinanceira']));
        });
    }

    public function estornarPagamento(ContaPagar $conta, int $pagamentoId): ContaPagarResource
    {
        return DB::transaction(function () use ($conta, $pagamentoId) {
            $antesConta = $conta->fresh(['fornecedor', 'pagamentos.usuario'])->toArray();
            $statusAntes = $this->statusValue($conta);

            $pagamento = $conta->pagamentos()->findOrFail($pagamentoId);
            $this->ledger->cancelarLancamentoPorPagamento($pagamento);

            if ($pagamento->comprovante_path) {
                Storage::disk('public')->delete($pagamento->comprovante_path);
            }

            $pagamento->delete();

            $conta->refresh();
            $this->statusSvc->syncPagar($conta->fresh());

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

            $this->auditLogger->logCustom(
                'ContaPagar',
                $conta->id,
                'financeiro',
                'REVERSAL',
                "Estorno em conta a pagar #{$conta->id}",
                [
                    'status' => [
                        'old' => $statusAntes,
                        'new' => $this->statusValue($fresh),
                    ],
                    'pagamento_estornado' => [
                        'old' => (float) $pagamento->valor,
                        'new' => null,
                    ],
                ],
                [
                    'pagamento_id' => $pagamentoId,
                    'motivo' => 'estorno_pagamento',
                ]
            );

            return new ContaPagarResource($fresh);
        });
    }

    private function statusValue(ContaPagar $conta): string
    {
        $st = $conta->status;
        if ($st instanceof BackedEnum) {
            return $st->value;
        }

        return (string) $st;
    }

    private function diffDirty(array $before, array $after): array
    {
        $dirty = [];
        foreach ($after as $field => $value) {
            $anterior = $before[$field] ?? null;
            if ((string) $anterior !== (string) $value) {
                $dirty[$field] = $value;
            }
        }

        return $dirty;
    }
}
