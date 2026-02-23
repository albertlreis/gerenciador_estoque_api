<?php

namespace App\Services;

use App\DTOs\FiltroLancamentoFinanceiroDTO;
use App\Enums\LancamentoStatus;
use App\Enums\LancamentoTipo;
use App\Models\LancamentoFinanceiro;
use App\Repositories\LancamentoFinanceiroRepository;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LancamentoFinanceiroService
{
    public function __construct(
        protected LancamentoFinanceiroRepository $repo,
        protected AuditLogger $auditLogger
    ) {}

    public function listar(FiltroLancamentoFinanceiroDTO $f): LengthAwarePaginator
    {
        return $this->repo->queryBase($f)->paginate(
            perPage: $f->perPage,
            page: $f->page
        );
    }

    public function obter(int $id): LancamentoFinanceiro
    {
        return $this->repo->findOrFail($id);
    }

    public function criar(array $data): LancamentoFinanceiro
    {
        $payload = $this->prepararPayloadParaPersistencia($data);
        $payload['created_by'] = $payload['created_by'] ?? (Auth::id() ?: null);

        $model = $this->repo->create($payload);

        $this->auditLogger->logCreate(
            $model,
            'financeiro',
            "Lancamento financeiro criado: #{$model->id}"
        );

        return $model->fresh(['categoria', 'conta', 'criador', 'centroCusto']);
    }

    private function isAutomatico(LancamentoFinanceiro $m): bool
    {
        return !empty($m->pagamento_type) || !empty($m->pagamento_id)
            || (!empty($m->referencia_type) && !empty($m->referencia_id));
    }

    public function atualizar(LancamentoFinanceiro $model, array $data): LancamentoFinanceiro
    {
        $before = $model->getAttributes();

        if ($this->isAutomatico($model)) {
            $allowed = array_intersect_key($data, array_flip(['status', 'observacoes']));
            $payload = $this->prepararPayloadParaPersistencia($allowed, $model);
            $updated = $this->repo->update($model, $payload);
            $this->registrarAuditoriaUpdate($updated, $before);
            return $updated->fresh(['categoria', 'conta', 'criador', 'centroCusto']);
        }

        $payload = $this->prepararPayloadParaPersistencia($data, $model);
        $updated = $this->repo->update($model, $payload);
        $this->registrarAuditoriaUpdate($updated, $before);

        return $updated->fresh(['categoria', 'conta', 'criador', 'centroCusto']);
    }

    public function remover(LancamentoFinanceiro $model): void
    {
        if ($this->isAutomatico($model)) {
            throw ValidationException::withMessages([
                'lancamento' => 'Este lancamento foi gerado automaticamente e nao pode ser removido. Cancele-o (status) ou estorne na origem.',
            ]);
        }

        $this->auditLogger->logDelete(
            $model,
            'financeiro',
            "Lancamento financeiro removido: #{$model->id}"
        );

        $this->repo->delete($model);
    }

    /**
     * @return array{
     *   receitas_confirmadas:string,
     *   despesas_confirmadas:string,
     *   saldo_confirmado:string,
     *   cancelados:string,
     *   pago?:string,
     *   pendente?:string,
     *   atrasado?:string
     * }
     */
    public function totais(FiltroLancamentoFinanceiroDTO $f): array
    {
        $base = $this->repo->queryBase($f)->reorder();

        $stConfirmado = LancamentoStatus::CONFIRMADO->value;
        $stCancelado = LancamentoStatus::CANCELADO->value;
        $tpReceita = LancamentoTipo::RECEITA->value;
        $tpDespesa = LancamentoTipo::DESPESA->value;

        $receitasConfirmadas = (clone $base)
            ->where('status', $stConfirmado)
            ->where('tipo', $tpReceita)
            ->sum('valor');

        $despesasConfirmadas = (clone $base)
            ->where('status', $stConfirmado)
            ->where('tipo', $tpDespesa)
            ->sum('valor');

        $cancelados = (clone $base)
            ->where('status', $stCancelado)
            ->sum('valor');

        $saldo = (float) $receitasConfirmadas - (float) $despesasConfirmadas;

        $out = [
            'receitas_confirmadas' => number_format((float) $receitasConfirmadas, 2, '.', ''),
            'despesas_confirmadas' => number_format((float) $despesasConfirmadas, 2, '.', ''),
            'saldo_confirmado' => number_format((float) $saldo, 2, '.', ''),
            'cancelados' => number_format((float) $cancelados, 2, '.', ''),
        ];

        $out['pago'] = $out['receitas_confirmadas'];
        $out['pendente'] = number_format(0, 2, '.', '');
        $out['atrasado'] = number_format(0, 2, '.', '');

        return $out;
    }

    private function prepararPayloadParaPersistencia(array $data, ?LancamentoFinanceiro $current = null): array
    {
        $p = $this->normalizarPayload($data);

        $tipo = $p['tipo'] ?? ($current?->tipo?->value ?? null);
        $status = $p['status'] ?? ($current?->status?->value ?? null);

        if (!$tipo && !$current) {
            throw ValidationException::withMessages(['tipo' => 'Tipo e obrigatorio (receita|despesa).']);
        }

        $status = $status ?: LancamentoStatus::CONFIRMADO->value;

        $tipoEnum = $tipo ? LancamentoTipo::tryFrom((string) $tipo) : null;
        if ($tipo && !$tipoEnum) {
            throw ValidationException::withMessages(['tipo' => 'Tipo invalido. Use receita ou despesa.']);
        }

        $statusEnum = LancamentoStatus::tryFrom((string) $status);
        if (!$statusEnum) {
            throw ValidationException::withMessages(['status' => 'Status invalido. Use confirmado ou cancelado.']);
        }

        $p['tipo'] = $tipoEnum?->value;
        $p['status'] = $statusEnum->value;

        if (empty($p['data_movimento'])) {
            if (!empty($p['data_pagamento'])) {
                $p['data_movimento'] = Carbon::parse($p['data_pagamento']);
            } elseif (!$current?->data_movimento) {
                $p['data_movimento'] = now();
            }
        }

        if (isset($p['descricao']) && $p['descricao'] === '') {
            throw ValidationException::withMessages(['descricao' => 'Descricao nao pode ser vazia.']);
        }

        if (array_key_exists('valor', $p)) {
            $v = (float) $p['valor'];
            if ($v <= 0) {
                throw ValidationException::withMessages(['valor' => 'Valor deve ser maior que zero.']);
            }
        }

        return Arr::only($p, [
            'descricao',
            'tipo',
            'status',
            'categoria_id',
            'centro_custo_id',
            'conta_id',
            'valor',
            'data_pagamento',
            'data_movimento',
            'competencia',
            'observacoes',
            'referencia_type',
            'referencia_id',
            'pagamento_type',
            'pagamento_id',
            'created_by',
        ]);
    }

    private function normalizarPayload(array $data): array
    {
        $p = $data;

        if (array_key_exists('descricao', $p)) {
            $p['descricao'] = trim((string) $p['descricao']);
        }

        if (array_key_exists('tipo', $p) && $p['tipo'] !== null) {
            $p['tipo'] = strtolower((string) $p['tipo']);
        }

        if (array_key_exists('status', $p) && $p['status'] !== null) {
            $p['status'] = strtolower((string) $p['status']);
        }

        if (array_key_exists('data_pagamento', $p)) {
            $p['data_pagamento'] = $p['data_pagamento'] ? Carbon::parse($p['data_pagamento']) : null;
        }

        if (array_key_exists('data_movimento', $p)) {
            $p['data_movimento'] = $p['data_movimento'] ? Carbon::parse($p['data_movimento']) : null;
        }

        if (array_key_exists('competencia', $p)) {
            $p['competencia'] = $p['competencia'] ? Carbon::parse($p['competencia'])->toDateString() : null;
        }

        return $p;
    }

    private function registrarAuditoriaUpdate(LancamentoFinanceiro $model, array $before): void
    {
        $dirty = $this->diffDirty($before, $model->getAttributes());

        $this->auditLogger->logUpdate(
            $model,
            'financeiro',
            "Lancamento financeiro atualizado: #{$model->id}",
            [
                '__before' => $before,
                '__dirty' => $dirty,
            ]
        );

        if (!array_key_exists('status', $dirty)) {
            return;
        }

        $statusNovo = $model->status?->value ?? $model->status;
        $statusAntigo = Arr::get($before, 'status');
        $acao = $statusNovo === 'cancelado' ? 'REVERSAL' : 'STATUS_CHANGE';

        $this->auditLogger->logCustom(
            'LancamentoFinanceiro',
            $model->id,
            'financeiro',
            $acao,
            "Status do lancamento #{$model->id} alterado para {$statusNovo}",
            [
                'status' => [
                    'old' => $statusAntigo,
                    'new' => $statusNovo,
                ],
            ]
        );
    }

    private function diffDirty(array $before, array $after): array
    {
        $dirty = [];
        foreach ($after as $field => $value) {
            $anterior = Arr::get($before, $field);
            if ((string) $anterior !== (string) $value) {
                $dirty[$field] = $value;
            }
        }

        return $dirty;
    }
}
