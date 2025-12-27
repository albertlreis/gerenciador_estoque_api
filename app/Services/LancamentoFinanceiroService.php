<?php

namespace App\Services;

use App\DTOs\FiltroLancamentoFinanceiroDTO;
use App\Enums\LancamentoStatus;
use App\Enums\LancamentoTipo;
use App\Models\LancamentoFinanceiro;
use App\Repositories\LancamentoFinanceiroRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LancamentoFinanceiroService
{
    public function __construct(
        protected LancamentoFinanceiroRepository $repo
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

        return $model->fresh(['categoria','conta','criador','centroCusto']);
    }

    public function atualizar(LancamentoFinanceiro $model, array $data): LancamentoFinanceiro
    {
        // prepara payload considerando estado atual do model
        $payload = $this->prepararPayloadParaPersistencia($data, $model);

        $updated = $this->repo->update($model, $payload);

        return $updated->fresh(['categoria','conta','criador','centroCusto']);
    }

    public function remover(LancamentoFinanceiro $model): void
    {
        $this->repo->delete($model);
    }

    /**
     * Totais do ledger respeitando os filtros informados.
     *
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
        $stCancelado  = LancamentoStatus::CANCELADO->value;

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

        $saldo = (float)$receitasConfirmadas - (float)$despesasConfirmadas;

        $out = [
            'receitas_confirmadas' => number_format((float)$receitasConfirmadas, 2, '.', ''),
            'despesas_confirmadas' => number_format((float)$despesasConfirmadas, 2, '.', ''),
            'saldo_confirmado'     => number_format((float)$saldo, 2, '.', ''),
            'cancelados'           => number_format((float)$cancelados, 2, '.', ''),
        ];

        // Compatibilidade (se seu front ainda espera pago/pendente/atrasado)
        $out['pago']     = $out['receitas_confirmadas']; // ou use total confirmado geral, se preferir
        $out['pendente'] = number_format(0, 2, '.', '');
        $out['atrasado'] = number_format(0, 2, '.', '');

        return $out;
    }

    /**
     * Normaliza + valida payload para salvar.
     * Se $current for informado, usa estado atual como fallback.
     */
    private function prepararPayloadParaPersistencia(array $data, ?LancamentoFinanceiro $current = null): array
    {
        $p = $this->normalizarPayload($data);

        // Defaults / fallback do model atual
        $tipo = $p['tipo'] ?? ($current?->tipo?->value ?? null);
        $status = $p['status'] ?? ($current?->status?->value ?? null);

        // tipo obrigatório em criação
        if (!$tipo && !$current) {
            throw ValidationException::withMessages(['tipo' => 'Tipo é obrigatório (receita|despesa).']);
        }

        // status default
        $status = $status ?: LancamentoStatus::CONFIRMADO->value;

        // valida enums (aceita string do request)
        $tipoEnum = $tipo ? LancamentoTipo::tryFrom((string)$tipo) : null;
        if ($tipo && !$tipoEnum) {
            throw ValidationException::withMessages(['tipo' => 'Tipo inválido. Use receita ou despesa.']);
        }

        $statusEnum = LancamentoStatus::tryFrom((string)$status);
        if (!$statusEnum) {
            throw ValidationException::withMessages(['status' => 'Status inválido. Use confirmado ou cancelado.']);
        }

        $p['tipo'] = $tipoEnum?->value;
        $p['status'] = $statusEnum->value;

        // data_movimento: regra do ledger
        // - se veio data_movimento, ok
        // - senão, se veio data_pagamento, usa como data_movimento
        // - senão, se não existe ainda, seta now()
        if (empty($p['data_movimento'])) {
            if (!empty($p['data_pagamento'])) {
                $p['data_movimento'] = Carbon::parse($p['data_pagamento']);
            } elseif (!$current?->data_movimento) {
                $p['data_movimento'] = now();
            }
        }

        // validações mínimas
        if (isset($p['descricao']) && $p['descricao'] === '') {
            throw ValidationException::withMessages(['descricao' => 'Descrição não pode ser vazia.']);
        }

        if (array_key_exists('valor', $p)) {
            $v = (float) $p['valor'];
            if ($v <= 0) {
                throw ValidationException::withMessages(['valor' => 'Valor deve ser maior que zero.']);
            }
        }

        // Se cancelado, não precisa “zerar datas”; mas você pode decidir:
        // Ex.: manter data_movimento para histórico e relatórios (recomendado).

        // Retorna apenas campos relevantes (evita “lixo” vindo do request)
        return Arr::only($p, [
            'descricao','tipo','status',
            'categoria_id','centro_custo_id','conta_id',
            'valor',
            'data_pagamento','data_movimento','competencia',
            'observacoes',
            'referencia_type','referencia_id',
            'pagamento_type','pagamento_id',
            'created_by',
        ]);
    }

    private function normalizarPayload(array $data): array
    {
        $p = $data;

        if (array_key_exists('descricao', $p)) {
            $p['descricao'] = trim((string)$p['descricao']);
        }

        if (array_key_exists('tipo', $p) && $p['tipo'] !== null) {
            $p['tipo'] = strtolower((string)$p['tipo']);
        }

        if (array_key_exists('status', $p) && $p['status'] !== null) {
            $p['status'] = strtolower((string)$p['status']);
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
}
