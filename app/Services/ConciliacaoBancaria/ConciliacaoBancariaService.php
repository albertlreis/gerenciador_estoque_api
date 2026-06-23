<?php

namespace App\Services\ConciliacaoBancaria;

use App\Models\ConciliacaoBancariaImportacao;
use App\Models\ConciliacaoBancariaTransacao;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\ContaPagarPagamento;
use App\Models\ContaReceber;
use App\Models\ContaReceberPagamento;
use App\Models\Fornecedor;
use App\Models\LancamentoFinanceiro;
use App\Services\ContaPagarCommandService;
use App\Services\ContaReceberCommandService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConciliacaoBancariaService
{
    public function __construct(
        private readonly OfxParser $parser,
        private readonly ConciliacaoBancariaMatcher $matcher,
        private readonly ContaPagarCommandService $contaPagarCommand,
        private readonly ContaReceberCommandService $contaReceberCommand,
    ) {}

    public function importarOfx(UploadedFile $file, int $contaFinanceiraId): ConciliacaoBancariaImportacao
    {
        $conta = ContaFinanceira::query()->findOrFail($contaFinanceiraId);
        $raw = (string) file_get_contents($file->getRealPath());
        $parsed = $this->parser->parse($raw);

        $this->validarContaOfx($conta, $parsed);

        return DB::transaction(function () use ($raw, $parsed, $conta) {
            $importacao = ConciliacaoBancariaImportacao::create([
                'conta_financeira_id' => $conta->id,
                'banco_codigo' => $parsed['banco_codigo'],
                'banco_nome' => $parsed['banco_nome'],
                'agencia' => $parsed['agencia'],
                'conta' => $parsed['conta'],
                'conta_dv' => $parsed['conta_dv'],
                'moeda' => $parsed['moeda'] ?: 'BRL',
                'data_inicio' => $parsed['data_inicio'],
                'data_fim' => $parsed['data_fim'],
                'saldo_final' => $parsed['saldo_final'],
                'saldo_final_em' => $parsed['saldo_final_em'],
                'arquivo_hash' => hash('sha256', $raw),
                'status' => 'processada',
                'created_by' => auth()->id(),
            ]);

            foreach ($parsed['transacoes'] as $transaction) {
                $this->upsertTransacao($importacao, $conta, $transaction);
            }

            $this->atualizarResumo($importacao);

            return $importacao->fresh([
                'contaFinanceira',
                'transacoes' => fn ($q) => $q->orderBy('data_movimento')->orderBy('id'),
            ]);
        });
    }

    public function listarTransacoes(array $filters): LengthAwarePaginator
    {
        return ConciliacaoBancariaTransacao::query()
            ->with(['importacao', 'contaFinanceira', 'lancamentoFinanceiro'])
            ->when(!empty($filters['importacao_id']), fn ($q) => $q->where('importacao_id', (int) $filters['importacao_id']))
            ->when(!empty($filters['conta_financeira_id']), fn ($q) => $q->where('conta_financeira_id', (int) $filters['conta_financeira_id']))
            ->when(!empty($filters['status']), fn ($q) => $q->where('status', (string) $filters['status']))
            ->orderByDesc('data_movimento')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function definirCandidato(ConciliacaoBancariaTransacao $transacao, ?string $tipo, ?int $id, ?string $formaPagamento = null): ConciliacaoBancariaTransacao
    {
        return DB::transaction(function () use ($transacao, $tipo, $id, $formaPagamento) {
            $transacao = ConciliacaoBancariaTransacao::query()->lockForUpdate()->findOrFail($transacao->id);

            abort_if($transacao->status === 'conciliado', 422, 'Transacao ja conciliada.');

            if (!$tipo || !$id) {
                $transacao->fill($this->clearCandidateColumns() + [
                    'status' => 'pendente',
                    'observacoes' => 'Candidato removido manualmente.',
                ])->save();

                return $transacao->fresh(['importacao', 'contaFinanceira']);
            }

            if ($tipo === 'fornecedor_provavel') {
                return $this->definirFornecedorProvavel($transacao, $id, $formaPagamento);
            }

            $model = $this->resolveCandidateModel($tipo, $id);
            $this->assertCandidateCompatible($transacao, $tipo, $model);

            $candidate = $this->manualCandidate($tipo, $model);
            $transacao->fill($this->candidateColumns([
                'status' => 'sugerido',
                'candidato' => $candidate,
            ]) + [
                'status' => 'sugerido',
                'observacoes' => 'Candidato selecionado manualmente.',
                'forma_pagamento' => $formaPagamento ?: $transacao->forma_pagamento ?: $this->matcher->inferFormaPagamento($transacao->memo),
            ])->save();

            return $transacao->fresh(['importacao', 'contaFinanceira']);
        });
    }

    private function definirFornecedorProvavel(ConciliacaoBancariaTransacao $transacao, int $fornecedorId, ?string $formaPagamento = null): ConciliacaoBancariaTransacao
    {
        $fornecedor = Fornecedor::query()->findOrFail($fornecedorId);
        $candidate = [
            'tipo' => 'fornecedor_provavel',
            'id' => (int) $fornecedor->id,
            'score' => 100,
            'motivo' => 'Fornecedor selecionado manualmente; sem titulo aberto com valor/data',
            'label' => 'Fornecedor provavel #' . $fornecedor->id . ' - ' . ($fornecedor->nome ?: '-'),
            'confirmavel' => false,
        ];

        $transacao->fill(array_merge($this->clearCandidateColumns(), [
            'status' => 'pendente',
            'candidato_score' => (int) $candidate['score'],
            'candidato_motivo' => $candidate['motivo'],
            'candidato_json' => [$candidate],
            'observacoes' => 'Fornecedor provavel selecionado manualmente.',
            'forma_pagamento' => $formaPagamento ?: $transacao->forma_pagamento ?: $this->matcher->inferFormaPagamento($transacao->memo),
        ]))->save();

        return $transacao->fresh(['importacao', 'contaFinanceira']);
    }

    public function confirmarTransacao(ConciliacaoBancariaTransacao $transacao, ?string $formaPagamento = null): ConciliacaoBancariaTransacao
    {
        return DB::transaction(function () use ($transacao, $formaPagamento) {
            $transacao = ConciliacaoBancariaTransacao::query()
                ->with(['importacao', 'contaFinanceira'])
                ->lockForUpdate()
                ->findOrFail($transacao->id);

            abort_if($transacao->status === 'conciliado', 422, 'Transacao ja conciliada.');
            abort_if(!$transacao->candidato_tipo || !$transacao->candidato_id, 422, 'Selecione um candidato antes de confirmar.');

            $forma = $formaPagamento ?: $transacao->forma_pagamento ?: $this->matcher->inferFormaPagamento($transacao->memo);
            $candidate = $this->resolveCandidateModel($transacao->candidato_tipo, (int) $transacao->candidato_id);
            $this->assertCandidateCompatible($transacao, $transacao->candidato_tipo, $candidate);

            if ($candidate instanceof LancamentoFinanceiro) {
                $transacao->fill([
                    'status' => 'conciliado',
                    'forma_pagamento' => $forma,
                    'pagamento_type' => $candidate->pagamento_type,
                    'pagamento_id' => $candidate->pagamento_id,
                    'lancamento_financeiro_id' => $candidate->id,
                    'conciliado_em' => now(),
                    'conciliado_por' => auth()->id(),
                    'observacoes' => trim(($transacao->observacoes ? $transacao->observacoes . "\n" : '') . 'Conciliado por OFX com lancamento financeiro existente.'),
                ])->save();

                $this->atualizarSaldoConta($transacao);
                $this->atualizarResumo($transacao->importacao);

                return $transacao->fresh(['importacao', 'contaFinanceira', 'lancamentoFinanceiro']);
            }

            $pagamento = $candidate instanceof ContaPagarPagamento || $candidate instanceof ContaReceberPagamento
                ? $candidate
                : $this->registrarPagamento($transacao, $candidate, $forma);

            $lancamento = $this->lancamentoPorPagamento($pagamento);

            $transacao->fill([
                'status' => 'conciliado',
                'forma_pagamento' => $forma,
                'pagamento_type' => get_class($pagamento),
                'pagamento_id' => (int) $pagamento->getKey(),
                'lancamento_financeiro_id' => $lancamento?->id,
                'conciliado_em' => now(),
                'conciliado_por' => auth()->id(),
                'observacoes' => trim(($transacao->observacoes ? $transacao->observacoes . "\n" : '') . 'Conciliado por OFX.'),
            ])->save();

            $this->atualizarSaldoConta($transacao);
            $this->atualizarResumo($transacao->importacao);

            return $transacao->fresh(['importacao', 'contaFinanceira', 'lancamentoFinanceiro']);
        });
    }

    /**
     * @param array<int,int>|null $ids
     * @return array{confirmadas:int,erros:array<int,array{id:int,message:string}>}
     */
    public function confirmarImportacao(ConciliacaoBancariaImportacao $importacao, ?array $ids = null, ?string $formaPagamento = null): array
    {
        $query = $importacao->transacoes()
            ->where('status', '!=', 'conciliado')
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('id');

        $confirmadas = 0;
        $erros = [];

        foreach ($query->get() as $transacao) {
            try {
                $this->confirmarTransacao($transacao, $formaPagamento);
                $confirmadas++;
            } catch (\Throwable $e) {
                $erros[] = [
                    'id' => (int) $transacao->id,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $this->atualizarResumo($importacao->fresh());

        return compact('confirmadas', 'erros');
    }

    public function reanalisarImportacao(ConciliacaoBancariaImportacao $importacao): ConciliacaoBancariaImportacao
    {
        return DB::transaction(function () use ($importacao) {
            $importacao = ConciliacaoBancariaImportacao::query()
                ->lockForUpdate()
                ->findOrFail($importacao->id);

            $transacoes = $importacao->transacoes()
                ->where('status', '!=', 'conciliado')
                ->orderBy('id')
                ->get();

            foreach ($transacoes as $transacao) {
                if ($this->isManualCandidate($transacao)) {
                    continue;
                }

                $match = $this->matcher->match($this->transactionPayload($transacao), (int) $transacao->conta_financeira_id);

                $transacao->fill($this->candidateColumns($match) + [
                    'status' => $match['status'],
                    'observacoes' => $match['observacao'] ?? null,
                    'forma_pagamento' => $transacao->forma_pagamento ?: $this->matcher->inferFormaPagamento($transacao->memo),
                ])->save();
            }

            $this->atualizarResumo($importacao);

            return $importacao->fresh([
                'contaFinanceira',
                'transacoes' => fn ($q) => $q->orderBy('data_movimento')->orderBy('id'),
            ]);
        });
    }

    /**
     * @param array<string,mixed> $transaction
     */
    private function upsertTransacao(ConciliacaoBancariaImportacao $importacao, ContaFinanceira $conta, array $transaction): ConciliacaoBancariaTransacao
    {
        $existing = ConciliacaoBancariaTransacao::query()
            ->where('conta_financeira_id', $conta->id)
            ->where('identificador', (string) $transaction['identificador'])
            ->first();

        $match = $existing?->status === 'conciliado' || ($existing && $this->isManualCandidate($existing))
            ? null
            : $this->matcher->match($transaction, (int) $conta->id);

        $base = [
            'importacao_id' => $importacao->id,
            'conta_financeira_id' => $conta->id,
            'fit_id' => $transaction['fit_id'] ?? null,
            'identificador' => $transaction['identificador'],
            'hash_unico' => $transaction['hash_unico'],
            'data_movimento' => $transaction['data_movimento'],
            'valor' => $transaction['valor'],
            'tipo_ofx' => $transaction['tipo_ofx'] ?? null,
            'checknum' => $transaction['checknum'] ?? null,
            'memo' => $transaction['memo'] ?? null,
            'forma_pagamento' => $existing?->forma_pagamento ?: $this->matcher->inferFormaPagamento($transaction['memo'] ?? null),
        ];

        if ($match !== null) {
            $base = array_merge($base, $this->candidateColumns($match), [
                'status' => $match['status'],
                'observacoes' => $match['observacao'] ?? null,
            ]);
        }

        $transacao = ConciliacaoBancariaTransacao::updateOrCreate(
            [
                'conta_financeira_id' => $conta->id,
                'identificador' => (string) $transaction['identificador'],
            ],
            $base
        );

        return $transacao;
    }

    /**
     * @return array<string,mixed>
     */
    private function transactionPayload(ConciliacaoBancariaTransacao $transacao): array
    {
        return [
            'fit_id' => $transacao->fit_id,
            'identificador' => $transacao->identificador,
            'hash_unico' => $transacao->hash_unico,
            'data_movimento' => $transacao->data_movimento?->toDateString(),
            'valor' => (float) $transacao->valor,
            'tipo_ofx' => $transacao->tipo_ofx,
            'checknum' => $transacao->checknum,
            'memo' => $transacao->memo,
        ];
    }

    private function isManualCandidate(ConciliacaoBancariaTransacao $transacao): bool
    {
        $observacoes = strtolower((string) $transacao->observacoes);

        return str_contains($observacoes, 'manual');
    }

    private function registrarPagamento(ConciliacaoBancariaTransacao $transacao, Model $candidate, string $forma): ContaPagarPagamento|ContaReceberPagamento
    {
        $valor = $transacao->valorAbsoluto();
        $payload = [
            'data_pagamento' => $transacao->data_movimento->toDateString(),
            'valor' => $valor,
            'forma_pagamento' => $forma,
            'conta_financeira_id' => (int) $transacao->conta_financeira_id,
            'observacoes' => "Baixa criada pela conciliacao bancaria OFX #{$transacao->id}",
        ];

        if ($candidate instanceof ContaPagar) {
            $this->contaPagarCommand->registrarPagamento($candidate, $payload);

            return $candidate->pagamentos()
                ->whereDate('data_pagamento', $payload['data_pagamento'])
                ->where('conta_financeira_id', $payload['conta_financeira_id'])
                ->where('valor', number_format($valor, 2, '.', ''))
                ->latest('id')
                ->firstOrFail();
        }

        if ($candidate instanceof ContaReceber) {
            $this->contaReceberCommand->registrarPagamento($candidate, $payload);

            return $candidate->pagamentos()
                ->whereDate('data_pagamento', $payload['data_pagamento'])
                ->where('conta_financeira_id', $payload['conta_financeira_id'])
                ->where('valor', number_format($valor, 2, '.', ''))
                ->latest('id')
                ->firstOrFail();
        }

        throw ValidationException::withMessages([
            'candidato' => 'Candidato invalido para baixa.',
        ]);
    }

    private function lancamentoPorPagamento(Model $pagamento): ?LancamentoFinanceiro
    {
        return LancamentoFinanceiro::query()
            ->where('pagamento_type', get_class($pagamento))
            ->where('pagamento_id', (int) $pagamento->getKey())
            ->latest('id')
            ->first();
    }

    private function atualizarSaldoConta(ConciliacaoBancariaTransacao $transacao): void
    {
        $importacao = $transacao->importacao;
        if (!$importacao || $importacao->saldo_final === null || !$importacao->saldo_final_em) {
            return;
        }

        $conta = $transacao->contaFinanceira;
        $saldoFinalEm = Carbon::parse($importacao->saldo_final_em);

        if ($conta->saldo_atual_em && Carbon::parse($conta->saldo_atual_em)->greaterThanOrEqualTo($saldoFinalEm)) {
            return;
        }

        $conta->forceFill([
            'saldo_atual' => $importacao->saldo_final,
            'saldo_atual_em' => $saldoFinalEm,
        ])->save();
    }

    private function atualizarResumo(ConciliacaoBancariaImportacao $importacao): void
    {
        $counts = $importacao->transacoes()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($value) => (int) $value)
            ->all();

        $total = array_sum($counts);
        $conciliadas = (int) ($counts['conciliado'] ?? 0);

        $importacao->forceFill([
            'status' => $total > 0 && $conciliadas === $total ? 'conciliada' : 'processada',
            'resumo_json' => [
                'total' => $total,
                'sugeridas' => (int) ($counts['sugerido'] ?? 0),
                'pendentes' => (int) ($counts['pendente'] ?? 0),
                'conflitos' => (int) ($counts['conflito'] ?? 0),
                'conciliadas' => $conciliadas,
            ],
        ])->save();
    }

    /**
     * @param array<string,mixed> $parsed
     */
    private function validarContaOfx(ContaFinanceira $conta, array $parsed): void
    {
        $bankLocal = $this->digits($conta->banco_codigo);
        $bankOfx = $this->digits($parsed['banco_codigo'] ?? null);
        if ($bankLocal !== '' && $bankOfx !== '' && ltrim($bankLocal, '0') !== ltrim($bankOfx, '0')) {
            throw ValidationException::withMessages([
                'conta_financeira_id' => 'O banco do OFX nao corresponde a conta financeira selecionada.',
            ]);
        }

        $localConta = $this->digits((string) $conta->conta . (string) $conta->conta_dv);
        $localContaSemDv = $this->digits((string) $conta->conta);
        $ofxConta = $this->digits((string) ($parsed['conta'] ?? '') . (string) ($parsed['conta_dv'] ?? ''));

        if ($localConta === '' || $ofxConta === '') {
            return;
        }

        $matches = $localConta === $ofxConta;
        if (!$matches && $conta->conta_dv === null && $localContaSemDv !== '') {
            $matches = $localContaSemDv === $ofxConta || $localContaSemDv === substr($ofxConta, 0, strlen($localContaSemDv));
        }

        if (!$matches) {
            throw ValidationException::withMessages([
                'conta_financeira_id' => 'A conta bancaria do OFX nao corresponde a conta financeira selecionada.',
            ]);
        }
    }

    private function assertCandidateCompatible(ConciliacaoBancariaTransacao $transacao, string $tipo, Model $candidate): void
    {
        $debitTypes = ['conta_pagar', 'conta_pagar_pagamento', 'lancamento_financeiro'];
        $creditTypes = ['conta_receber', 'conta_receber_pagamento', 'lancamento_financeiro'];

        if ($transacao->valor < 0 && !in_array($tipo, $debitTypes, true)) {
            throw ValidationException::withMessages(['candidato' => 'Debitos do OFX devem conciliar contas a pagar.']);
        }
        if ($transacao->valor > 0 && !in_array($tipo, $creditTypes, true)) {
            throw ValidationException::withMessages(['candidato' => 'Creditos do OFX devem conciliar contas a receber.']);
        }

        if ($candidate instanceof LancamentoFinanceiro) {
            $lancamentoTipo = $candidate->tipo instanceof \BackedEnum ? $candidate->tipo->value : (string) $candidate->tipo;

            if ($transacao->valor < 0 && $lancamentoTipo !== 'despesa') {
                throw ValidationException::withMessages(['candidato' => 'Debitos do OFX devem conciliar lancamentos de despesa.']);
            }

            if ($transacao->valor > 0 && $lancamentoTipo !== 'receita') {
                throw ValidationException::withMessages(['candidato' => 'Creditos do OFX devem conciliar lancamentos de receita.']);
            }

            if ($candidate->conta_id && (int) $candidate->conta_id !== (int) $transacao->conta_financeira_id) {
                throw ValidationException::withMessages([
                    'candidato' => 'O lancamento candidato pertence a outra conta financeira.',
                ]);
            }
        }

        $valor = $transacao->valorAbsoluto();
        $candidateValue = match (true) {
            $candidate instanceof ContaPagarPagamento,
            $candidate instanceof ContaReceberPagamento => (float) $candidate->valor,
            $candidate instanceof ContaPagar,
            $candidate instanceof ContaReceber => (float) $candidate->saldo_aberto,
            $candidate instanceof LancamentoFinanceiro => (float) $candidate->valor,
            default => null,
        };

        if ($candidateValue === null || $this->moneyCents($candidateValue) !== $this->moneyCents($valor)) {
            throw ValidationException::withMessages([
                'candidato' => 'O valor do candidato deve ser igual ao valor da transacao bancaria.',
            ]);
        }

        if (($candidate instanceof ContaPagarPagamento || $candidate instanceof ContaReceberPagamento)
            && (int) $candidate->conta_financeira_id !== (int) $transacao->conta_financeira_id) {
            throw ValidationException::withMessages([
                'candidato' => 'O pagamento candidato pertence a outra conta financeira.',
            ]);
        }
    }

    private function resolveCandidateModel(string $tipo, int $id): Model
    {
        return match ($tipo) {
            'conta_pagar' => ContaPagar::query()->findOrFail($id),
            'conta_receber' => ContaReceber::query()->findOrFail($id),
            'conta_pagar_pagamento' => ContaPagarPagamento::query()->findOrFail($id),
            'conta_receber_pagamento' => ContaReceberPagamento::query()->findOrFail($id),
            'lancamento_financeiro' => LancamentoFinanceiro::query()->findOrFail($id),
            default => throw ValidationException::withMessages(['candidato_tipo' => 'Tipo de candidato invalido.']),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function manualCandidate(string $tipo, Model $model): array
    {
        return [
            'tipo' => $tipo,
            'id' => (int) $model->getKey(),
            'score' => 100,
            'motivo' => 'Selecionado manualmente',
            'label' => $this->candidateLabel($tipo, $model),
        ];
    }

    private function candidateLabel(string $tipo, Model $model): string
    {
        return match ($tipo) {
            'conta_pagar' => 'Conta a pagar #' . $model->getKey() . ' - ' . ($model->descricao ?: '-'),
            'conta_receber' => 'Conta a receber #' . $model->getKey() . ' - ' . ($model->descricao ?: '-'),
            'conta_pagar_pagamento' => 'Pagamento conta a pagar #' . $model->getKey(),
            'conta_receber_pagamento' => 'Pagamento conta a receber #' . $model->getKey(),
            'lancamento_financeiro' => 'Lancamento financeiro #' . $model->getKey() . ' - ' . ($model->descricao ?: '-'),
            default => $tipo . ' #' . $model->getKey(),
        };
    }

    /**
     * @param array<string,mixed> $match
     * @return array<string,mixed>
     */
    private function candidateColumns(array $match): array
    {
        $candidate = $match['candidato'] ?? null;
        $candidates = $match['candidatos'] ?? null;
        $json = $candidates ?: $candidate;

        if (!is_array($candidate)) {
            $score = $match['score'] ?? null;

            return [
                ...$this->clearCandidateColumns(),
                'candidato_score' => $score !== null ? (int) $score : null,
                'candidato_json' => $json ?: null,
                'candidato_motivo' => $match['observacao'] ?? null,
            ];
        }

        return [
            'candidato_tipo' => $candidate['tipo'] ?? null,
            'candidato_id' => isset($candidate['id']) ? (int) $candidate['id'] : null,
            'candidato_score' => isset($candidate['score']) ? (int) $candidate['score'] : null,
            'candidato_motivo' => $candidate['motivo'] ?? ($match['observacao'] ?? null),
            'candidato_json' => $json ?: null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function clearCandidateColumns(): array
    {
        return [
            'candidato_tipo' => null,
            'candidato_id' => null,
            'candidato_score' => null,
            'candidato_motivo' => null,
            'candidato_json' => null,
        ];
    }

    private function digits(mixed $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    private function moneyCents(float $value): int
    {
        return (int) round($value * 100);
    }
}
