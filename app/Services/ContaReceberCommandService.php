<?php

namespace App\Services;

use App\Enums\ContaStatus;
use App\Enums\LancamentoTipo;
use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Services\ContaAzulExportDispatchService;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\FinanceiroParcelamento;
use BackedEnum;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\Comunicacao\ComunicacaoApiClient;
use Illuminate\Validation\ValidationException;
use Throwable;

class ContaReceberCommandService
{
    public function __construct(
        private readonly ContaStatusService $statusSvc,
        private readonly FinanceiroLedgerService $ledger,
        private readonly FinanceiroAuditoriaService $audit,
        private readonly ComunicacaoApiClient $comms,
        private readonly ContaAzulExportDispatchService $contaAzulExports,
        private readonly RecorrenciaFinanceiraService $recorrencias,
    ) {}

    public function criar(array $dados): ContaReceber
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

        return DB::transaction(function () use ($dados) {
            $pagamentoInicial = $dados['pagamento_inicial'] ?? null;
            unset($dados['pagamento_inicial'], $dados['parcelamento']);

            $dados = $this->normalizarDados($dados);
            $dados['status'] = $dados['status'] ?? ContaStatus::ABERTA->value;

            $conta = ContaReceber::create($dados);

            $this->syncValores($conta);
            $this->statusSvc->syncReceber($conta->fresh());

            if (is_array($pagamentoInicial)) {
                $this->registrarPagamentoInicial($conta->fresh(), $pagamentoInicial);
            }

            $fresh = $conta->fresh(['cliente', 'pedido.cliente', 'categoria', 'centroCusto', 'parcelamento', 'recorrencia', 'pagamentos.usuario']);
            $this->audit->log('created', $conta, null, $fresh->toArray());

            try {
                $this->comms->enviarCobranca($fresh);
            } catch (\Throwable $e) {
                logger()->warning('[Comunicacao] Falha ao enfileirar cobrança', [
                    'conta_id' => $fresh->id,
                    'error' => $e->getMessage(),
                ]);
            }

            if (!is_array($pagamentoInicial)) {
                $this->exportarTituloContaAzulBestEffort((int) $fresh->id, 'conta_receber_criada');
            }

            return $fresh;
        });
    }

    public function atualizar(ContaReceber $conta, array $dados): ContaReceber
    {
        return DB::transaction(function () use ($conta, $dados) {

            $antes = $conta->fresh()->toArray();

            $conta->fill($this->normalizarDados($dados, $conta))->save();

            $this->syncValores($conta);
            $this->statusSvc->syncReceber($conta->fresh());

            $fresh = $conta->fresh(['recorrencia']);
            $this->audit->log('updated', $conta, $antes, $fresh->toArray());

            $this->exportarTituloContaAzulBestEffort((int) $fresh->id, 'conta_receber_atualizada');

            return $fresh;
        });
    }

    public function deletar(ContaReceber $conta, bool $confirmarEstornos = false): void
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
            $conta = ContaReceber::query()
                ->with(['cliente', 'pedido.cliente', 'recorrencia', 'pagamentos.usuario', 'pagamentos.contaFinanceira'])
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

            $antes = $conta->fresh(['cliente', 'pedido.cliente', 'recorrencia', 'pagamentos.usuario', 'pagamentos.contaFinanceira'])->toArray();

            foreach ($conta->pagamentos as $pagamento) {
                $this->estornarPagamentoParaExclusao($conta, $pagamento);
                $this->estornarBaixaContaAzulBestEffort((int) $pagamento->id, 'exclusao_conta_receber');
            }

            if ($conta->pagamentos->isNotEmpty()) {
                $this->syncValores($conta->fresh());
                $this->statusSvc->syncReceber($conta->fresh());
            }

            $this->excluirTituloContaAzulBestEffort((int) $conta->id, 'exclusao_conta_receber');

            $conta->delete();

            $this->audit->log('deleted', $conta, $antes, null);
        });
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
            $this->exportarBaixaContaAzulBestEffort((int) $pagamento->id, 'pagamento_registrado');

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

            $fresh = $conta->fresh(['cliente', 'pedido.cliente', 'recorrencia', 'pagamentos.usuario']);

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

    private function estornarPagamentoParaExclusao(ContaReceber $conta, ContaReceberPagamento $pagamento): void
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
            'motivo' => 'delete_conta_receber',
            'conta_receber_id' => (int) $conta->id,
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
        return collect($pagamentos)->map(fn (ContaReceberPagamento $pagamento) => [
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

    private function exportarTituloContaAzulBestEffort(int $contaReceberId, string $evento): void
    {
        try {
            DB::afterCommit(function () use ($contaReceberId, $evento): void {
                try {
                    $this->contaAzulExports->titulo($contaReceberId, null, ['evento' => $evento]);
                } catch (Throwable $e) {
                    $this->logFalhaExportacaoContaAzul('titulo', [
                        'conta_receber_id' => $contaReceberId,
                        'evento' => $evento,
                    ], $e);
                }
            });
        } catch (Throwable $e) {
            $this->logFalhaExportacaoContaAzul('titulo', [
                'conta_receber_id' => $contaReceberId,
                'evento' => $evento,
            ], $e);
        }
    }

    private function exportarBaixaContaAzulBestEffort(int $pagamentoId, string $evento): void
    {
        try {
            DB::afterCommit(function () use ($pagamentoId, $evento): void {
                try {
                    $this->contaAzulExports->baixa($pagamentoId, null, ['evento' => $evento]);
                } catch (Throwable $e) {
                    $this->logFalhaExportacaoContaAzul('baixa', [
                        'pagamento_id' => $pagamentoId,
                        'evento' => $evento,
                    ], $e);
                }
            });
        } catch (Throwable $e) {
            $this->logFalhaExportacaoContaAzul('baixa', [
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
                    $this->contaAzulExports->estornarBaixa(ContaAzulEntityType::BAIXA, $pagamentoId, null, [
                        'evento' => $evento,
                    ]);
                } catch (Throwable $e) {
                    $this->logFalhaExportacaoContaAzul('estorno_baixa', [
                        'pagamento_id' => $pagamentoId,
                        'evento' => $evento,
                    ], $e);
                }
            });
        } catch (Throwable $e) {
            $this->logFalhaExportacaoContaAzul('estorno_baixa', [
                'pagamento_id' => $pagamentoId,
                'evento' => $evento,
            ], $e);
        }
    }

    private function excluirTituloContaAzulBestEffort(int $contaReceberId, string $evento): void
    {
        try {
            DB::afterCommit(function () use ($contaReceberId, $evento): void {
                try {
                    $this->contaAzulExports->excluirTituloFinanceiro(ContaAzulEntityType::TITULO, $contaReceberId, null, [
                        'evento' => $evento,
                    ]);
                } catch (Throwable $e) {
                    $this->logFalhaExportacaoContaAzul('delete_titulo', [
                        'conta_receber_id' => $contaReceberId,
                        'evento' => $evento,
                    ], $e);
                }
            });
        } catch (Throwable $e) {
            $this->logFalhaExportacaoContaAzul('delete_titulo', [
                'conta_receber_id' => $contaReceberId,
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

    private function criarParcelado(array $dados): ContaReceber
    {
        return DB::transaction(function () use ($dados) {
            $parcelamentoDados = $dados['parcelamento'] ?? [];
            $pagamentoInicial = $dados['pagamento_inicial'] ?? null;
            unset($dados['parcelamento'], $dados['pagamento_inicial']);

            $dados = $this->normalizarDados($dados);
            $valorTotal = $this->toDecimalFloat($dados['valor_liquido'] ?? $dados['valor_bruto'] ?? 0);
            $valorEntrada = $this->toDecimalFloat($parcelamentoDados['valor_entrada'] ?? 0);
            $quantidade = max(1, (int)($parcelamentoDados['quantidade_parcelas'] ?? 1));
            $intervalo = max(1, (int)($parcelamentoDados['intervalo_meses'] ?? 1));

            if ($valorEntrada >= $valorTotal) {
                throw ValidationException::withMessages([
                    'parcelamento.valor_entrada' => 'A entrada deve ser menor que o valor total.',
                ]);
            }

            $parcelamento = FinanceiroParcelamento::create([
                'tipo' => 'receber',
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
                $conta = ContaReceber::create($payload);
                $this->syncValores($conta);
                $this->statusSvc->syncReceber($conta->fresh());
                $contas[] = $conta->fresh();
            }

            $target = collect($contas)->first(fn (ContaReceber $conta) => (bool)$conta->is_entrada) ?? ($contas[0] ?? null);
            if ($target && is_array($pagamentoInicial)) {
                $this->registrarPagamentoInicial($target, $pagamentoInicial);
            }

            $fresh = ($target ?: $contas[0])->fresh(['cliente', 'pedido.cliente', 'categoria', 'centroCusto', 'parcelamento', 'recorrencia', 'pagamentos.usuario']);
            $this->audit->log('created_installments', $parcelamento, null, [
                'parcelamento' => $parcelamento->fresh()->toArray(),
                'contas' => collect($contas)->pluck('id')->values()->all(),
            ]);

            return $fresh;
        });
    }

    private function criarRecorrente(array $dados): ContaReceber
    {
        return DB::transaction(function () use ($dados) {
            $recorrenciaDados = $dados['recorrencia'] ?? [];
            $pagamentoInicial = $dados['pagamento_inicial'] ?? null;
            unset($dados['recorrencia'], $dados['parcelamento'], $dados['pagamento_inicial']);

            $dados = $this->normalizarDados($dados);
            $dados['status'] = ContaStatus::ABERTA->value;

            $datas = $this->recorrencias->datas($recorrenciaDados, (string) $dados['data_vencimento']);
            $serie = $this->recorrencias->criarSerie($dados, $recorrenciaDados, 'RECEBER', auth()->id());

            $contas = [];
            foreach ($datas as $data) {
                $payload = $dados;
                $payload['data_vencimento'] = $data->toDateString();
                $payload['despesa_recorrente_id'] = $serie->id;
                $payload['recorrencia_competencia'] = $data->toDateString();

                $conta = ContaReceber::create($payload);
                $this->syncValores($conta);
                $this->statusSvc->syncReceber($conta->fresh());
                $freshConta = $conta->fresh();
                $this->recorrencias->registrarExecucao($serie, $freshConta, $data);
                $contas[] = $freshConta;
            }

            $primeiraConta = $contas[0] ?? null;
            if ($primeiraConta && is_array($pagamentoInicial)) {
                $this->registrarPagamentoInicial($primeiraConta, $pagamentoInicial);
            }

            $fresh = $primeiraConta->fresh(['cliente', 'pedido.cliente', 'categoria', 'centroCusto', 'recorrencia', 'pagamentos.usuario']);
            $this->audit->log('created_recurring', $serie, null, [
                'recorrencia' => $serie->fresh()->toArray(),
                'contas' => collect($contas)->pluck('id')->values()->all(),
            ]);

            try {
                $this->comms->enviarCobranca($fresh);
            } catch (\Throwable $e) {
                logger()->warning('[Comunicacao] Falha ao enfileirar cobranÃ§a recorrente', [
                    'conta_id' => $fresh->id,
                    'error' => $e->getMessage(),
                ]);
            }

            foreach ($contas as $idx => $conta) {
                if (is_array($pagamentoInicial) && $idx === 0) {
                    continue;
                }
                $this->exportarTituloContaAzulBestEffort((int) $conta->id, 'conta_receber_recorrente_criada');
            }

            return $fresh;
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
        $descricao = trim((string)($base['descricao'] ?? 'Conta a receber'));
        $entradaDate = Carbon::parse($parcelamentoDados['data_entrada'] ?? $base['data_emissao'] ?? now())->toDateString();
        $primeiroVencimento = Carbon::parse($parcelamentoDados['primeiro_vencimento'] ?? $base['data_vencimento'] ?? now());
        $comum = $base;
        $comum['status'] = ContaStatus::ABERTA->value;
        $comum['desconto'] = 0;
        $comum['juros'] = 0;
        $comum['multa'] = 0;
        $comum['valor_recebido'] = 0;
        $comum['parcelamento_id'] = $parcelamento->id;
        $comum['parcelas_total'] = $quantidade;

        if ($valorEntrada > 0) {
            $payloads[] = [
                ...$comum,
                'descricao' => "{$descricao} - Entrada",
                'data_vencimento' => $entradaDate,
                'valor_bruto' => $valorEntrada,
                'valor_liquido' => $valorEntrada,
                'saldo_aberto' => $valorEntrada,
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
                'valor_liquido' => $valor,
                'saldo_aberto' => $valor,
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
                    'valor' => $this->toDecimalFloat($parcela['valor'] ?? 0),
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

        $descricao = trim((string)($base['descricao'] ?? 'Conta a receber'));
        $comum = $base;
        $comum['status'] = ContaStatus::ABERTA->value;
        $comum['desconto'] = 0;
        $comum['juros'] = 0;
        $comum['multa'] = 0;
        $comum['valor_recebido'] = 0;
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
                'valor_liquido' => $parcela['valor'],
                'saldo_aberto' => $parcela['valor'],
                'parcela_numero' => $numero,
                'is_entrada' => $isEntrada,
            ];
        })->all();
    }

    private function registrarPagamentoInicial(ContaReceber $conta, array $dados): void
    {
        $valor = $this->toDecimalFloat($dados['valor'] ?? 0);
        if ($valor <= 0 || $valor > (float)$conta->saldo_aberto) {
            throw ValidationException::withMessages([
                'pagamento_inicial.valor' => 'O valor do pagamento inicial deve ser maior que zero e menor ou igual ao saldo da parcela.',
            ]);
        }

        $this->registrarPagamento($conta, $dados);
    }

    private function normalizarDados(array $dados, ?ContaReceber $conta = null): array
    {
        $out = $dados;

        if (array_key_exists('descricao', $out) && $out['descricao'] !== null) {
            $out['descricao'] = trim((string)$out['descricao']);
        }
        if (array_key_exists('numero_documento', $out)) {
            $out['numero_documento'] = $out['numero_documento'] !== null
                ? trim((string)$out['numero_documento'])
                : null;
        }
        if (array_key_exists('observacoes', $out)) {
            $out['observacoes'] = $out['observacoes'] !== null
                ? trim((string)$out['observacoes'])
                : null;
        }
        if (array_key_exists('forma_recebimento', $out)) {
            $out['forma_recebimento'] = $out['forma_recebimento'] !== null
                ? trim((string)$out['forma_recebimento'])
                : null;
        }

        foreach (['valor_bruto','desconto','juros','multa','valor_recebido'] as $field) {
            if (array_key_exists($field, $out)) {
                $out[$field] = $this->toDecimal($out[$field]);
            } elseif ($conta && in_array($field, ['desconto','juros','multa','valor_recebido'], true)) {
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
                'valor_recebido' => 'Valor recebido não pode ser maior que o valor líquido.',
            ]);
        }

        $out['valor_liquido'] = $valorLiquido;
        $out['valor_recebido'] = $valorRecebido;
        $out['saldo_aberto'] = $saldoAberto;

        return $out;
    }

    private function toDecimal(mixed $value): string
    {
        if ($value === null || $value === '') return '0.00';
        $v = is_string($value) ? str_replace(',', '.', trim($value)) : (string)$value;
        if (function_exists('bcadd')) {
            return bcadd($v, '0', 2);
        }
        return number_format((float)$v, 2, '.', '');
    }

    private function toDecimalFloat(mixed $value): float
    {
        if ($value === null || $value === '') return 0.0;
        if (is_string($value)) {
            $value = str_contains($value, ',')
                ? str_replace(['.', ','], ['', '.'], trim($value))
                : trim($value);
        }
        return round((float)$value, 2);
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

    private function bcAdd(string $a, string $b): string
    {
        if (function_exists('bcadd')) return bcadd($a, $b, 2);
        return number_format(((float)$a + (float)$b), 2, '.', '');
    }

    private function bcSub(string $a, string $b): string
    {
        if (function_exists('bcsub')) return bcsub($a, $b, 2);
        return number_format(((float)$a - (float)$b), 2, '.', '');
    }

    private function bcComp(string $a, string $b): int
    {
        if (function_exists('bccomp')) return bccomp($a, $b, 2);
        $af = (float)$a;
        $bf = (float)$b;
        return $af <=> $bf;
    }

    private function moneyCents(float $value): int
    {
        return (int) round($value * 100);
    }
}
