<?php

namespace App\Services;

use App\Enums\ContaStatus;
use App\Enums\LancamentoTipo;
use App\Http\Resources\ContaPagarResource;
use App\Http\Resources\ContaPagarPagamentoResource;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Services\ContaAzulExportDispatchService;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\FinanceiroParcelamento;
use App\Repositories\Contracts\ContaPagarRepository;
use BackedEnum;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ContaPagarCommandService
{
    public function __construct(
        private readonly ContaPagarRepository $repo,
        private readonly ContaStatusService $statusSvc,
        private readonly FinanceiroLedgerService $ledger,
        private readonly FinanceiroAuditoriaService $audit,
        private readonly RecorrenciaFinanceiraService $recorrencias,
        private readonly ContaAzulExportDispatchService $contaAzulExports,
    ) {}

    public function criar(array $dados): ContaPagarResource
    {
        if (!empty($dados['recorrencia']) && !empty($dados['parcelamento'])) {
            throw ValidationException::withMessages([
                'recorrencia' => 'RecorrÃªncia e parcelamento nÃ£o podem ser usados no mesmo lanÃ§amento.',
            ]);
        }

        if (!empty($dados['recorrencia']) && is_array($dados['recorrencia'])) {
            return $this->criarRecorrente($dados);
        }

        if (!empty($dados['parcelamento']) && is_array($dados['parcelamento'])) {
            return $this->criarParcelado($dados);
        }

        $pagamentoInicial = $dados['pagamento_inicial'] ?? null;
        unset($dados['pagamento_inicial'], $dados['parcelamento']);

        $dados['status'] = $dados['status'] ?? ContaStatus::ABERTA->value;

        $conta = $this->repo->criar($dados);

        $this->statusSvc->syncPagar($conta->fresh());

        if (is_array($pagamentoInicial)) {
            $this->registrarPagamentoInicial($conta->fresh(), $pagamentoInicial);
        }

        $fresh = $conta->fresh(['fornecedor', 'categoria', 'centroCusto', 'parcelamento', 'recorrencia', 'pagamentos.usuario']);
        $this->audit->log('created', $conta, null, $fresh->toArray());

        if (!is_array($pagamentoInicial)) {
            $this->exportarContaPagarContaAzulBestEffort((int) $fresh->id, 'conta_pagar_criada');
        }

        return new ContaPagarResource($fresh);
    }

    public function atualizar(ContaPagar $conta, array $dados): ContaPagarResource
    {
        $antes = $conta->fresh(['fornecedor', 'pagamentos.usuario'])->toArray();

        $conta = $this->repo->atualizar($conta, $dados);

        $this->statusSvc->syncPagar($conta->fresh());

        $fresh = $conta->fresh(['fornecedor', 'recorrencia', 'pagamentos.usuario']);
        $this->audit->log('updated', $conta, $antes, $fresh->toArray());

        $this->exportarContaPagarContaAzulBestEffort((int) $fresh->id, 'conta_pagar_atualizada');

        return new ContaPagarResource($fresh);
    }

    public function deletar(ContaPagar $conta, bool $confirmarEstornos = false): void
    {
        $conta->loadMissing(['pagamentos.usuario', 'pagamentos.contaFinanceira']);

        if ($conta->pagamentos->isNotEmpty() && !$confirmarEstornos) {
            $this->exigirConfirmacaoEstornos($conta->pagamentos);
        }

        abort_if(
            $conta->pagamentos->isEmpty() && $this->statusValue($conta) === ContaStatus::PAGA->value,
            422,
            'Nao e possivel excluir uma conta paga sem pagamentos vinculados para estorno.'
        );

        DB::transaction(function () use ($conta, $confirmarEstornos): void {
            $conta = ContaPagar::query()
                ->with(['fornecedor', 'recorrencia', 'pagamentos.usuario', 'pagamentos.contaFinanceira'])
                ->lockForUpdate()
                ->findOrFail($conta->id);

            if ($conta->pagamentos->isNotEmpty() && !$confirmarEstornos) {
                $this->exigirConfirmacaoEstornos($conta->pagamentos);
            }

            abort_if(
                $conta->pagamentos->isEmpty() && $this->statusValue($conta) === ContaStatus::PAGA->value,
                422,
                'Nao e possivel excluir uma conta paga sem pagamentos vinculados para estorno.'
            );

            $antes = $conta->fresh(['fornecedor', 'recorrencia', 'pagamentos.usuario', 'pagamentos.contaFinanceira'])->toArray();

            foreach ($conta->pagamentos as $pagamento) {
                $this->estornarPagamentoParaExclusao($conta, $pagamento);
                $this->estornarBaixaContaAzulBestEffort((int) $pagamento->id, 'exclusao_conta_pagar');
            }

            if ($conta->pagamentos->isNotEmpty()) {
                $this->statusSvc->syncPagar($conta->fresh());
            }

            $this->excluirContaPagarContaAzulBestEffort((int) $conta->id, 'exclusao_conta_pagar');

            $this->repo->deletar($conta);

            $this->audit->log('deleted', $conta, $antes, null);
        });
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
            $this->exportarBaixaContaPagarContaAzulBestEffort((int) $pagamento->id, 'pagamento_conta_pagar_registrado');

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

    private function estornarPagamentoParaExclusao(ContaPagar $conta, ContaPagarPagamento $pagamento): void
    {
        $antesPagamento = $pagamento->fresh(['usuario', 'contaFinanceira'])->toArray();

        $this->ledger->cancelarLancamentoPorPagamento($pagamento);

        if ($pagamento->comprovante_path) {
            Storage::disk('public')->delete($pagamento->comprovante_path);
        }

        $pagamento->delete();

        $this->audit->log('reversed_by_delete', $pagamento, $antesPagamento, null);
        $this->audit->log('ledger_canceled', $pagamento, null, [
            'pagamento_id' => (int) $pagamento->id,
            'pagamento_type' => get_class($pagamento),
            'motivo' => 'delete_conta_pagar',
            'conta_pagar_id' => (int) $conta->id,
        ]);
    }

    private function exigirConfirmacaoEstornos($pagamentos): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Confirme a exclusao e os estornos dos pagamentos vinculados.',
            'reason' => 'confirmacao_estornos_obrigatoria',
            'pagamentos' => $this->pagamentosResumo($pagamentos),
        ], 422));
    }

    private function pagamentosResumo($pagamentos): array
    {
        return collect($pagamentos)->map(fn (ContaPagarPagamento $pagamento) => [
            'id' => (int) $pagamento->id,
            'data_pagamento' => optional($pagamento->data_pagamento)->format('Y-m-d'),
            'valor' => (float) $pagamento->valor,
            'forma_pagamento' => $pagamento->forma_pagamento,
            'conta_financeira' => $pagamento->relationLoaded('contaFinanceira') ? [
                'id' => $pagamento->contaFinanceira?->id,
                'nome' => $pagamento->contaFinanceira?->nome,
            ] : null,
            'usuario' => $pagamento->relationLoaded('usuario') ? [
                'id' => $pagamento->usuario?->id,
                'nome' => $pagamento->usuario?->nome ?? $pagamento->usuario?->name,
            ] : null,
        ])->values()->all();
    }

    private function exportarContaPagarContaAzulBestEffort(int $contaPagarId, string $evento): void
    {
        try {
            DB::afterCommit(function () use ($contaPagarId, $evento): void {
                try {
                    $this->contaAzulExports->contaPagar($contaPagarId, null, ['evento' => $evento]);
                } catch (Throwable $e) {
                    $this->logFalhaExportacaoContaAzul('conta_pagar', [
                        'conta_pagar_id' => $contaPagarId,
                        'evento' => $evento,
                    ], $e);
                }
            });
        } catch (Throwable $e) {
            $this->logFalhaExportacaoContaAzul('conta_pagar', [
                'conta_pagar_id' => $contaPagarId,
                'evento' => $evento,
            ], $e);
        }
    }

    private function exportarBaixaContaPagarContaAzulBestEffort(int $pagamentoId, string $evento): void
    {
        try {
            DB::afterCommit(function () use ($pagamentoId, $evento): void {
                try {
                    $this->contaAzulExports->baixaContaPagar($pagamentoId, null, ['evento' => $evento]);
                } catch (Throwable $e) {
                    $this->logFalhaExportacaoContaAzul('baixa_conta_pagar', [
                        'pagamento_id' => $pagamentoId,
                        'evento' => $evento,
                    ], $e);
                }
            });
        } catch (Throwable $e) {
            $this->logFalhaExportacaoContaAzul('baixa_conta_pagar', [
                'pagamento_id' => $pagamentoId,
                'evento' => $evento,
            ], $e);
        }
    }

    private function estornarBaixaContaAzulBestEffort(int $pagamentoId, string $evento): void
    {
        try {
            DB::afterCommit(function () use ($pagamentoId, $evento): void {
                try {
                    $this->contaAzulExports->estornarBaixa(ContaAzulEntityType::BAIXA_CONTA_PAGAR, $pagamentoId, null, [
                        'evento' => $evento,
                    ]);
                } catch (Throwable $e) {
                    $this->logFalhaExportacaoContaAzul('estorno_baixa_conta_pagar', [
                        'pagamento_id' => $pagamentoId,
                        'evento' => $evento,
                    ], $e);
                }
            });
        } catch (Throwable $e) {
            $this->logFalhaExportacaoContaAzul('estorno_baixa_conta_pagar', [
                'pagamento_id' => $pagamentoId,
                'evento' => $evento,
            ], $e);
        }
    }

    private function excluirContaPagarContaAzulBestEffort(int $contaPagarId, string $evento): void
    {
        try {
            DB::afterCommit(function () use ($contaPagarId, $evento): void {
                try {
                    $this->contaAzulExports->excluirTituloFinanceiro(ContaAzulEntityType::CONTA_PAGAR, $contaPagarId, null, [
                        'evento' => $evento,
                    ]);
                } catch (Throwable $e) {
                    $this->logFalhaExportacaoContaAzul('delete_conta_pagar', [
                        'conta_pagar_id' => $contaPagarId,
                        'evento' => $evento,
                    ], $e);
                }
            });
        } catch (Throwable $e) {
            $this->logFalhaExportacaoContaAzul('delete_conta_pagar', [
                'conta_pagar_id' => $contaPagarId,
                'evento' => $evento,
            ], $e);
        }
    }

    /**
     * @param array<string, mixed> $contexto
     */
    private function logFalhaExportacaoContaAzul(string $tipo, array $contexto, Throwable $e): void
    {
        Log::warning("Falha ao disparar exportacao Conta Azul para {$tipo}.", $contexto + [
            'exception' => $e::class,
            'erro' => $e->getMessage(),
        ]);
    }

    private function criarParcelado(array $dados): ContaPagarResource
    {
        return DB::transaction(function () use ($dados) {
            $parcelamentoDados = $dados['parcelamento'] ?? [];
            $pagamentoInicial = $dados['pagamento_inicial'] ?? null;
            unset($dados['parcelamento'], $dados['pagamento_inicial']);

            $valorTotal = $this->valorLiquido($dados);
            $valorEntrada = $this->toMoneyFloat($parcelamentoDados['valor_entrada'] ?? 0);
            $quantidade = max(1, (int)($parcelamentoDados['quantidade_parcelas'] ?? 1));
            $intervalo = max(1, (int)($parcelamentoDados['intervalo_meses'] ?? 1));

            if ($valorEntrada >= $valorTotal) {
                throw ValidationException::withMessages([
                    'parcelamento.valor_entrada' => 'A entrada deve ser menor que o valor total.',
                ]);
            }

            $parcelamento = FinanceiroParcelamento::create([
                'tipo' => 'pagar',
                'descricao' => (string)($dados['descricao'] ?? ''),
                'numero_documento' => $dados['numero_documento'] ?? null,
                'valor_total' => $valorTotal,
                'valor_entrada' => $valorEntrada,
                'quantidade_parcelas' => $quantidade,
                'intervalo_meses' => $intervalo,
                'data_emissao' => $dados['data_emissao'] ?? null,
                'primeiro_vencimento' => $parcelamentoDados['primeiro_vencimento'] ?? $dados['data_vencimento'],
                'created_by' => auth()->id(),
            ]);

            $contas = [];
            foreach ($this->parcelasPayload($dados, $parcelamento, $parcelamentoDados, $valorTotal, $valorEntrada, $quantidade, $intervalo) as $payload) {
                $conta = $this->repo->criar($payload);
                $this->statusSvc->syncPagar($conta->fresh());
                $contas[] = $conta->fresh();
            }

            $target = collect($contas)->first(fn (ContaPagar $conta) => (bool)$conta->is_entrada) ?? ($contas[0] ?? null);
            if ($target && is_array($pagamentoInicial)) {
                $this->registrarPagamentoInicial($target, $pagamentoInicial);
            }

            $fresh = ($target ?: $contas[0])->fresh(['fornecedor', 'categoria', 'centroCusto', 'parcelamento', 'recorrencia', 'pagamentos.usuario']);
            $this->audit->log('created_installments', $parcelamento, null, [
                'parcelamento' => $parcelamento->fresh()->toArray(),
                'contas' => collect($contas)->pluck('id')->values()->all(),
            ]);

            foreach ($contas as $conta) {
                if (is_array($pagamentoInicial) && $target && (int) $conta->id === (int) $target->id) {
                    continue;
                }
                $this->exportarContaPagarContaAzulBestEffort((int) $conta->id, 'conta_pagar_parcelada_criada');
            }

            return new ContaPagarResource($fresh);
        });
    }

    private function criarRecorrente(array $dados): ContaPagarResource
    {
        return DB::transaction(function () use ($dados) {
            $recorrenciaDados = $dados['recorrencia'] ?? [];
            $pagamentoInicial = $dados['pagamento_inicial'] ?? null;
            unset($dados['recorrencia'], $dados['parcelamento'], $dados['pagamento_inicial']);

            $dados['status'] = ContaStatus::ABERTA->value;

            $datas = $this->recorrencias->datas($recorrenciaDados, (string) $dados['data_vencimento']);
            $serie = $this->recorrencias->criarSerie($dados, $recorrenciaDados, 'PAGAR', auth()->id());

            $contas = [];
            foreach ($datas as $data) {
                $payload = $dados;
                $payload['data_vencimento'] = $data->toDateString();
                $payload['despesa_recorrente_id'] = $serie->id;
                $payload['recorrencia_competencia'] = $data->toDateString();

                $conta = $this->repo->criar($payload);
                $this->statusSvc->syncPagar($conta->fresh());
                $freshConta = $conta->fresh();
                $this->recorrencias->registrarExecucao($serie, $freshConta, $data);
                $contas[] = $freshConta;
            }

            $primeiraConta = $contas[0] ?? null;
            if ($primeiraConta && is_array($pagamentoInicial)) {
                $this->registrarPagamentoInicial($primeiraConta, $pagamentoInicial);
            }

            $fresh = $primeiraConta->fresh(['fornecedor', 'categoria', 'centroCusto', 'recorrencia', 'pagamentos.usuario']);
            $this->audit->log('created_recurring', $serie, null, [
                'recorrencia' => $serie->fresh()->toArray(),
                'contas' => collect($contas)->pluck('id')->values()->all(),
            ]);

            foreach ($contas as $idx => $conta) {
                if (is_array($pagamentoInicial) && $idx === 0) {
                    continue;
                }
                $this->exportarContaPagarContaAzulBestEffort((int) $conta->id, 'conta_pagar_recorrente_criada');
            }

            return new ContaPagarResource($fresh);
        });
    }

    private function parcelasPayload(
        array $base,
        FinanceiroParcelamento $parcelamento,
        array $parcelamentoDados,
        float $valorTotal,
        float $valorEntrada,
        int $quantidade,
        int $intervalo
    ): array {
        if (!empty($parcelamentoDados['parcelas']) && is_array($parcelamentoDados['parcelas'])) {
            return $this->parcelasCustomizadasPayload($base, $parcelamento, $parcelamentoDados, $valorTotal, $valorEntrada, $quantidade);
        }

        $payloads = [];
        $descricao = trim((string)($base['descricao'] ?? 'Conta a pagar'));
        $entradaDate = Carbon::parse($parcelamentoDados['data_entrada'] ?? $base['data_emissao'] ?? now())->toDateString();
        $primeiroVencimento = Carbon::parse($parcelamentoDados['primeiro_vencimento'] ?? $base['data_vencimento'] ?? now());
        $comum = $base;
        $comum['status'] = ContaStatus::ABERTA->value;
        $comum['desconto'] = 0;
        $comum['juros'] = 0;
        $comum['multa'] = 0;
        $comum['parcelamento_id'] = $parcelamento->id;
        $comum['parcelas_total'] = $quantidade;

        if ($valorEntrada > 0) {
            $payloads[] = [
                ...$comum,
                'descricao' => "{$descricao} - Entrada",
                'data_vencimento' => $entradaDate,
                'valor_bruto' => $valorEntrada,
                'parcela_numero' => 0,
                'is_entrada' => true,
            ];
        }

        $valores = $this->distribuirValor($valorTotal - $valorEntrada, $quantidade);
        foreach ($valores as $idx => $valor) {
            $numero = $idx + 1;
            $payloads[] = [
                ...$comum,
                'descricao' => "{$descricao} - Parcela {$numero}/{$quantidade}",
                'data_vencimento' => $primeiroVencimento->copy()->addMonthsNoOverflow($idx * $intervalo)->toDateString(),
                'valor_bruto' => $valor,
                'parcela_numero' => $numero,
                'is_entrada' => false,
            ];
        }

        return $payloads;
    }

    private function parcelasCustomizadasPayload(
        array $base,
        FinanceiroParcelamento $parcelamento,
        array $parcelamentoDados,
        float $valorTotal,
        float $valorEntrada,
        int $quantidade
    ): array {
        $parcelas = collect($parcelamentoDados['parcelas'])
            ->map(function (array $parcela) {
                $isEntrada = (bool)($parcela['is_entrada'] ?? false) || (int)($parcela['parcela_numero'] ?? 0) === 0;

                return [
                    'parcela_numero' => $isEntrada ? 0 : (int)$parcela['parcela_numero'],
                    'vencimento' => Carbon::parse($parcela['vencimento'])->toDateString(),
                    'valor' => $this->toMoneyFloat($parcela['valor'] ?? 0),
                    'is_entrada' => $isEntrada,
                ];
            })
            ->sortBy(fn (array $parcela) => ($parcela['is_entrada'] ? '0' : '1') . str_pad((string)$parcela['parcela_numero'], 3, '0', STR_PAD_LEFT))
            ->values();

        $totalInformado = $parcelas->sum('valor');
        if ($this->moneyCents($totalInformado) !== $this->moneyCents($valorTotal)) {
            throw ValidationException::withMessages([
                'parcelamento.parcelas' => 'A soma das parcelas deve ser igual ao valor líquido da conta.',
            ]);
        }

        $entradas = $parcelas->where('is_entrada', true);
        $valorEntradaInformado = $entradas->sum('valor');
        if ($this->moneyCents($valorEntradaInformado) !== $this->moneyCents($valorEntrada)) {
            throw ValidationException::withMessages([
                'parcelamento.parcelas' => 'O valor da entrada deve conferir com as parcelas informadas.',
            ]);
        }

        if ($valorEntrada > 0 && $entradas->count() !== 1) {
            throw ValidationException::withMessages([
                'parcelamento.parcelas' => 'Informe exatamente uma parcela de entrada.',
            ]);
        }

        if ($parcelas->where('is_entrada', false)->count() !== $quantidade) {
            throw ValidationException::withMessages([
                'parcelamento.quantidade_parcelas' => 'A quantidade de parcelas não confere com a agenda informada.',
            ]);
        }

        $descricao = trim((string)($base['descricao'] ?? 'Conta a pagar'));
        $comum = $base;
        $comum['status'] = ContaStatus::ABERTA->value;
        $comum['desconto'] = 0;
        $comum['juros'] = 0;
        $comum['multa'] = 0;
        $comum['parcelamento_id'] = $parcelamento->id;
        $comum['parcelas_total'] = $quantidade;

        return $parcelas->map(function (array $parcela) use ($comum, $descricao, $quantidade) {
            $numero = (int)$parcela['parcela_numero'];
            $isEntrada = (bool)$parcela['is_entrada'];

            return [
                ...$comum,
                'descricao' => $isEntrada ? "{$descricao} - Entrada" : "{$descricao} - Parcela {$numero}/{$quantidade}",
                'data_vencimento' => $parcela['vencimento'],
                'valor_bruto' => $parcela['valor'],
                'parcela_numero' => $numero,
                'is_entrada' => $isEntrada,
            ];
        })->all();
    }

    private function registrarPagamentoInicial(ContaPagar $conta, array $dados): void
    {
        $valor = $this->toMoneyFloat($dados['valor'] ?? 0);
        if ($valor <= 0 || $valor > (float)$conta->saldo_aberto) {
            throw ValidationException::withMessages([
                'pagamento_inicial.valor' => 'O valor do pagamento inicial deve ser maior que zero e menor ou igual ao saldo da parcela.',
            ]);
        }

        $this->registrarPagamento($conta, $dados);
    }

    private function valorLiquido(array $dados): float
    {
        return max(0, $this->toMoneyFloat($dados['valor_bruto'] ?? 0)
            - $this->toMoneyFloat($dados['desconto'] ?? 0)
            + $this->toMoneyFloat($dados['juros'] ?? 0)
            + $this->toMoneyFloat($dados['multa'] ?? 0));
    }

    private function distribuirValor(float $total, int $quantidade): array
    {
        $totalCents = (int) round($total * 100);
        $base = intdiv($totalCents, $quantidade);
        $resto = $totalCents % $quantidade;

        return array_map(
            fn (int $i) => ($base + ($i < $resto ? 1 : 0)) / 100,
            range(0, $quantidade - 1)
        );
    }

    private function toMoneyFloat(mixed $value): float
    {
        if ($value === null || $value === '') return 0.0;
        if (is_string($value)) {
            $value = str_contains($value, ',')
                ? str_replace(['.', ','], ['', '.'], trim($value))
                : trim($value);
        }
        return round((float)$value, 2);
    }

    private function moneyCents(float $value): int
    {
        return (int) round($value * 100);
    }
}
