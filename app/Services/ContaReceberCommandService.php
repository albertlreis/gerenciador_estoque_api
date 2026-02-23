<?php

namespace App\Services;

use App\Enums\ContaStatus;
use App\Enums\LancamentoTipo;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Support\Audit\AuditLogger;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ContaReceberCommandService
{
    public function __construct(
        private readonly ContaStatusService $statusSvc,
        private readonly FinanceiroLedgerService $ledger,
        private readonly FinanceiroAuditoriaService $audit,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function criar(array $dados): ContaReceber
    {
        return DB::transaction(function () use ($dados) {
            $dados = $this->normalizarDados($dados);
            $dados['status'] = $dados['status'] ?? ContaStatus::ABERTA->value;

            $conta = ContaReceber::create($dados);

            $this->syncValores($conta);
            $this->statusSvc->syncReceber($conta->fresh());

            $fresh = $conta->fresh();
            $this->audit->log('created', $conta, null, $fresh->toArray());
            $this->auditLogger->logCreate($conta, 'financeiro', "Conta a receber criada: #{$conta->id}");

            return $fresh;
        });
    }

    public function atualizar(ContaReceber $conta, array $dados): ContaReceber
    {
        return DB::transaction(function () use ($conta, $dados) {
            $antes = $conta->fresh()->toArray();
            $beforeAttrs = $conta->getAttributes();

            $conta->fill($this->normalizarDados($dados, $conta))->save();

            $this->syncValores($conta);
            $this->statusSvc->syncReceber($conta->fresh());

            $fresh = $conta->fresh();
            $this->audit->log('updated', $conta, $antes, $fresh->toArray());
            $this->auditLogger->logUpdate(
                $conta,
                'financeiro',
                "Conta a receber atualizada: #{$conta->id}",
                [
                    '__before' => $beforeAttrs,
                    '__dirty' => $this->diffDirty($beforeAttrs, $conta->getAttributes()),
                ]
            );

            return $fresh;
        });
    }

    public function deletar(ContaReceber $conta): void
    {
        abort_if($this->statusValue($conta) === ContaStatus::PAGA->value, 422, 'Nao e possivel excluir uma conta ja paga.');

        $antes = $conta->fresh()->toArray();
        $this->auditLogger->logDelete($conta, 'financeiro', "Conta a receber removida: #{$conta->id}");
        $conta->delete();

        $this->audit->log('deleted', $conta, $antes, null);
    }

    public function registrarPagamento(ContaReceber $conta, array $dados): ContaReceberPagamento
    {
        abort_if($this->statusValue($conta) === ContaStatus::CANCELADA->value, 422, 'Conta cancelada nao pode receber pagamento.');
        abort_if(empty($dados['forma_pagamento']), 422, 'Forma de pagamento e obrigatoria no pagamento.');
        abort_if(empty($dados['conta_financeira_id']), 422, 'Conta financeira e obrigatoria no pagamento.');

        return DB::transaction(function () use ($conta, $dados) {
            $antesConta = $conta->fresh()->toArray();
            $statusAntes = $this->statusValue($conta);

            $pagamento = new ContaReceberPagamento([
                'conta_receber_id' => $conta->id,
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

            $this->syncValores($conta->fresh());

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

            $statusNovo = $this->statusValue($conta->fresh());
            $this->auditLogger->logCustom(
                'ContaReceber',
                $conta->id,
                'financeiro',
                'STATUS_CHANGE',
                "Baixa em conta a receber #{$conta->id}",
                [
                    'status' => [
                        'old' => $statusAntes,
                        'new' => $statusNovo,
                    ],
                    'valor_recebido' => [
                        'old' => null,
                        'new' => (float) $pagamento->valor,
                    ],
                ],
                [
                    'pagamento_id' => $pagamento->id,
                    'forma_pagamento' => $pagamento->forma_pagamento?->value ?? $pagamento->forma_pagamento,
                ]
            );

            return $pagamento->fresh(['usuario', 'contaFinanceira']);
        });
    }

    public function estornarPagamento(ContaReceber $conta, int $pagamentoId): ContaReceber
    {
        return DB::transaction(function () use ($conta, $pagamentoId) {
            $antesConta = $conta->fresh()->toArray();
            $statusAntes = $this->statusValue($conta);

            $pagamento = $conta->pagamentos()->findOrFail($pagamentoId);
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

            $this->auditLogger->logCustom(
                'ContaReceber',
                $conta->id,
                'financeiro',
                'REVERSAL',
                "Estorno em conta a receber #{$conta->id}",
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

            return $fresh;
        });
    }

    private function statusValue(ContaReceber $conta): string
    {
        $st = $conta->status;
        if ($st instanceof BackedEnum) {
            return $st->value;
        }

        return (string) $st;
    }

    private function syncValores(ContaReceber $conta): void
    {
        $valorBruto = $this->toDecimal($conta->valor_bruto);
        $desconto = $this->toDecimal($conta->desconto);
        $juros = $this->toDecimal($conta->juros);
        $multa = $this->toDecimal($conta->multa);

        $valorLiquido = $this->bcAdd($this->bcSub($valorBruto, $desconto), $this->bcAdd($juros, $multa));

        $hasPagamentos = $conta->pagamentos()->exists();
        $valorRecebido = $hasPagamentos
            ? $this->toDecimal($conta->pagamentos()->sum('valor'))
            : $this->toDecimal($conta->valor_recebido);

        $saldoAberto = $this->bcSub($valorLiquido, $valorRecebido);
        if ($this->bcComp($saldoAberto, '0.00') < 0) {
            $saldoAberto = '0.00';
        }

        $conta->valor_liquido = $valorLiquido;
        $conta->valor_recebido = $valorRecebido;
        $conta->saldo_aberto = $saldoAberto;
        $conta->saveQuietly();
    }

    private function normalizarDados(array $dados, ?ContaReceber $conta = null): array
    {
        $out = $dados;

        if (array_key_exists('descricao', $out) && $out['descricao'] !== null) {
            $out['descricao'] = trim((string) $out['descricao']);
        }
        if (array_key_exists('numero_documento', $out)) {
            $out['numero_documento'] = $out['numero_documento'] !== null
                ? trim((string) $out['numero_documento'])
                : null;
        }
        if (array_key_exists('observacoes', $out)) {
            $out['observacoes'] = $out['observacoes'] !== null
                ? trim((string) $out['observacoes'])
                : null;
        }
        if (array_key_exists('forma_recebimento', $out)) {
            $out['forma_recebimento'] = $out['forma_recebimento'] !== null
                ? trim((string) $out['forma_recebimento'])
                : null;
        }

        foreach (['valor_bruto', 'desconto', 'juros', 'multa', 'valor_recebido'] as $field) {
            if (array_key_exists($field, $out)) {
                $out[$field] = $this->toDecimal($out[$field]);
            } elseif ($conta && in_array($field, ['desconto', 'juros', 'multa', 'valor_recebido'], true)) {
                $out[$field] = $this->toDecimal($conta->$field);
            }
        }

        $valorBruto = $this->toDecimal($out['valor_bruto'] ?? ($conta?->valor_bruto ?? '0'));
        $desconto = $this->toDecimal($out['desconto'] ?? ($conta?->desconto ?? '0'));
        $juros = $this->toDecimal($out['juros'] ?? ($conta?->juros ?? '0'));
        $multa = $this->toDecimal($out['multa'] ?? ($conta?->multa ?? '0'));
        $valorRecebido = $this->toDecimal($out['valor_recebido'] ?? ($conta?->valor_recebido ?? '0'));

        $valorLiquido = $this->bcAdd($this->bcSub($valorBruto, $desconto), $this->bcAdd($juros, $multa));
        $saldoAberto = $this->bcSub($valorLiquido, $valorRecebido);
        if ($this->bcComp($saldoAberto, '0.00') < 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'valor_recebido' => 'Valor recebido nao pode ser maior que o valor liquido.',
            ]);
        }

        $out['valor_liquido'] = $valorLiquido;
        $out['valor_recebido'] = $valorRecebido;
        $out['saldo_aberto'] = $saldoAberto;

        return $out;
    }

    private function toDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }
        $v = is_string($value) ? str_replace(',', '.', trim($value)) : (string) $value;
        if (function_exists('bcadd')) {
            return bcadd($v, '0', 2);
        }
        return number_format((float) $v, 2, '.', '');
    }

    private function bcAdd(string $a, string $b): string
    {
        if (function_exists('bcadd')) {
            return bcadd($a, $b, 2);
        }
        return number_format(((float) $a + (float) $b), 2, '.', '');
    }

    private function bcSub(string $a, string $b): string
    {
        if (function_exists('bcsub')) {
            return bcsub($a, $b, 2);
        }
        return number_format(((float) $a - (float) $b), 2, '.', '');
    }

    private function bcComp(string $a, string $b): int
    {
        if (function_exists('bccomp')) {
            return bccomp($a, $b, 2);
        }
        return (float) $a <=> (float) $b;
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
