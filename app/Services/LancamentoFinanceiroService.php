<?php

namespace App\Services;

use App\DTOs\FiltroLancamentoFinanceiroDTO;
use App\Models\LancamentoFinanceiro;
use App\Repositories\LancamentoFinanceiroRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

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
        $payload = $this->normalizarPayload($data);

        $payload['created_by'] = $payload['created_by'] ?? (Auth::id() ?: null);

        $this->validarRegrasDeStatus($payload);

        return $this->repo->create($payload)->fresh(['categoria','conta','criador']);
    }

    public function atualizar(LancamentoFinanceiro $model, array $data): LancamentoFinanceiro
    {
        $payload = $this->normalizarPayload($data);

        // Regra: se vier status pago sem data_pagamento, setar agora
        $merged = array_merge($model->toArray(), $payload);
        $this->validarRegrasDeStatus($merged);

        return $this->repo->update($model, $payload);
    }

    public function remover(LancamentoFinanceiro $model): void
    {
        $this->repo->delete($model);
    }

    /**
     * Totais: pago, pendente, atrasado (derivado)
     * Retorna valores SEMPRE respeitando os filtros informados.
     *
     * @return array{pago:string, pendente:string, atrasado:string}
     */
    public function totais(FiltroLancamentoFinanceiroDTO $f): array
    {
        // Base com todos os filtros do index
        $base = $this->repo->queryBase($f);

        // Importante: para totals não queremos order/paginate atrapalhando
        // (order não interfere no sum, mas vamos “limpar” por segurança)
        $base = $base->reorder();

        // Pago
        $totalPago = (clone $base)
            ->where('status', 'pago')
            ->sum('valor');

        // Pendente
        $totalPendente = (clone $base)
            ->where('status', 'pendente')
            ->sum('valor');

        // Atrasado (pendente + vencido)
        $totalAtrasado = (clone $base)
            ->where('status', 'pendente')
            ->where('data_vencimento', '<', now())
            ->sum('valor');

        return [
            'pago'     => number_format((float)$totalPago, 2, '.', ''),
            'pendente' => number_format((float)$totalPendente, 2, '.', ''),
            'atrasado' => number_format((float)$totalAtrasado, 2, '.', ''),
        ];
    }

    private function normalizarPayload(array $data): array
    {
        $p = $data;

        if (array_key_exists('descricao', $p)) {
            $p['descricao'] = trim((string)$p['descricao']);
        }
        if (array_key_exists('status', $p) && $p['status'] === null) {
            unset($p['status']);
        }
        if (array_key_exists('tipo', $p) && $p['tipo'] !== null) {
            $p['tipo'] = strtolower((string)$p['tipo']);
        }

        // Normaliza datas (aceita string, deixa o cast do model trabalhar)
        if (isset($p['data_vencimento'])) {
            $p['data_vencimento'] = Carbon::parse($p['data_vencimento']);
        }
        if (array_key_exists('data_pagamento', $p)) {
            $p['data_pagamento'] = $p['data_pagamento'] ? Carbon::parse($p['data_pagamento']) : null;
        }
        if (array_key_exists('competencia', $p)) {
            $p['competencia'] = $p['competencia'] ? Carbon::parse($p['competencia'])->toDateString() : null;
        }

        return $p;
    }

    private function validarRegrasDeStatus(array &$payload): void
    {
        $status = $payload['status'] ?? 'pendente';

        if ($status === 'pago') {
            if (empty($payload['data_pagamento'])) {
                // regra: pago precisa data_pagamento
                $payload['data_pagamento'] = now();
            }
        }

        if ($status === 'pendente') {
            // regra: pendente não deve manter data_pagamento
            if (array_key_exists('data_pagamento', $payload)) {
                $payload['data_pagamento'] = null;
            }
        }

        if ($status === 'cancelado') {
            // cancelado pode manter ou não data_pagamento — aqui vamos zerar por padrão
            if (array_key_exists('data_pagamento', $payload)) {
                $payload['data_pagamento'] = null;
            }
        }
    }
}
