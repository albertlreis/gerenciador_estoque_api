<?php

namespace App\Services;

use App\Models\ContaFinanceira;
use App\Models\LancamentoFinanceiro;
use App\Models\TransferenciaFinanceira;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferenciaFinanceiraService
{
    /**
     * Cria a transferência e gera 2 lançamentos automaticamente:
     *  - saída (conta origem)
     *  - entrada (conta destino)
     */
    public function criar(array $data): TransferenciaFinanceira
    {
        $p = $this->normalizar($data);

        $this->validarPayload($p);

        return DB::transaction(function () use ($p) {
            $origem = ContaFinanceira::query()->findOrFail((int)$p['conta_origem_id']);
            $destino = ContaFinanceira::query()->findOrFail((int)$p['conta_destino_id']);

            $transferencia = TransferenciaFinanceira::query()->create([
                'conta_origem_id'  => (int)$p['conta_origem_id'],
                'conta_destino_id' => (int)$p['conta_destino_id'],
                'valor'            => $p['valor'],
                'data_movimento'   => $p['data_movimento'],
                'observacoes'      => $p['observacoes'] ?? null,
                'status'           => $p['status'] ?? 'confirmado',
                'created_by'       => Auth::id() ?: null,
            ]);

            $this->criarLancamentosDaTransferencia($transferencia, $origem, $destino);

            return $transferencia->fresh(['contaOrigem', 'contaDestino', 'criador', 'lancamentos']);
        });
    }

    /**
     * Atualiza a transferência e sincroniza os 2 lançamentos vinculados.
     * Recomendação: não permitir update se já estiver cancelada (aqui eu bloqueio).
     */
    public function atualizar(TransferenciaFinanceira $t, array $data): TransferenciaFinanceira
    {
        if ($t->status === 'cancelado') {
            throw ValidationException::withMessages([
                'transferencia' => 'Transferência cancelada não pode ser alterada.',
            ]);
        }

        $p = $this->normalizar($data, $t);

        $this->validarPayload($p);

        return DB::transaction(function () use ($t, $p) {
            $origem = ContaFinanceira::query()->findOrFail((int)$p['conta_origem_id']);
            $destino = ContaFinanceira::query()->findOrFail((int)$p['conta_destino_id']);

            $t->update([
                'conta_origem_id'  => (int)$p['conta_origem_id'],
                'conta_destino_id' => (int)$p['conta_destino_id'],
                'valor'            => $p['valor'],
                'data_movimento'   => $p['data_movimento'],
                'observacoes'      => $p['observacoes'] ?? null,
                'status'           => $p['status'] ?? $t->status,
            ]);

            $this->syncLancamentosDaTransferencia($t->fresh(), $origem, $destino);

            return $t->fresh(['contaOrigem', 'contaDestino', 'criador', 'lancamentos']);
        });
    }

    /**
     * "Remove" no sentido de extrato confiável: cancela a transferência e cancela os 2 lançamentos.
     * (Evite deletar, para manter histórico.)
     */
    public function cancelar(TransferenciaFinanceira $t): TransferenciaFinanceira
    {
        return DB::transaction(function () use ($t) {
            $t->update(['status' => 'cancelado']);

            LancamentoFinanceiro::query()
                ->where('referencia_type', TransferenciaFinanceira::class)
                ->where('referencia_id', $t->id)
                ->update(['status' => 'cancelado']);

            return $t->fresh(['contaOrigem', 'contaDestino', 'criador', 'lancamentos']);
        });
    }

    /* ============================================================
     * Internals
     * ============================================================ */

    private function criarLancamentosDaTransferencia(
        TransferenciaFinanceira $t,
        ContaFinanceira $origem,
        ContaFinanceira $destino
    ): void {
        $dt = Carbon::parse($t->data_movimento);

        $descricaoBase = $this->descricaoBase($origem, $destino);

        // SAÍDA (origem)
        LancamentoFinanceiro::query()->create([
            'descricao'       => "Transferência enviada: {$descricaoBase}",
            'tipo'            => 'transferencia',
            'status'          => $t->status ?? 'confirmado',

            'categoria_id'    => null,
            'centro_custo_id' => null,
            'conta_id'        => $origem->id,

            'valor'           => $t->valor,

            'data_movimento'  => $dt,
            'data_pagamento'  => null,
            'competencia'     => null,

            'observacoes'     => $t->observacoes,

            'referencia_type' => TransferenciaFinanceira::class,
            'referencia_id'   => $t->id,

            'pagamento_type'  => null,
            'pagamento_id'    => null,

            'created_by'      => $t->created_by,
        ]);

        // ENTRADA (destino)
        LancamentoFinanceiro::query()->create([
            'descricao'       => "Transferência recebida: {$descricaoBase}",
            'tipo'            => 'transferencia',
            'status'          => $t->status ?? 'confirmado',

            'categoria_id'    => null,
            'centro_custo_id' => null,
            'conta_id'        => $destino->id,

            'valor'           => $t->valor,

            'data_movimento'  => $dt,
            'data_pagamento'  => null,
            'competencia'     => null,

            'observacoes'     => $t->observacoes,

            'referencia_type' => TransferenciaFinanceira::class,
            'referencia_id'   => $t->id,

            'pagamento_type'  => null,
            'pagamento_id'    => null,

            'created_by'      => $t->created_by,
        ]);
    }

    private function syncLancamentosDaTransferencia(
        TransferenciaFinanceira $t,
        ContaFinanceira $origem,
        ContaFinanceira $destino
    ): void {
        $base = LancamentoFinanceiro::query()
            ->where('referencia_type', TransferenciaFinanceira::class)
            ->where('referencia_id', $t->id)
            ->where('tipo', 'transferencia');

        // tenta achar pelos prefixos estáveis
        $saida = (clone $base)->where('descricao', 'like', 'Transferência enviada:%')->first();
        $entrada = (clone $base)->where('descricao', 'like', 'Transferência recebida:%')->first();

        // fallback: se alguém alterou a descrição (idealmente não deve), pega pelos 2 primeiros IDs
        if (!$saida || !$entrada) {
            $two = (clone $base)->orderBy('id')->limit(2)->get();
            $saida = $saida ?: ($two[0] ?? null);
            $entrada = $entrada ?: ($two[1] ?? null);
        }

        if (!$saida || !$entrada) {
            // se por qualquer motivo não existirem 2 lançamentos, recria
            (clone $base)->delete();
            $this->criarLancamentosDaTransferencia($t, $origem, $destino);
            return;
        }

        $dt = Carbon::parse($t->data_movimento);
        $descricaoBase = $this->descricaoBase($origem, $destino);

        $saida->update([
            'descricao'      => "Transferência enviada: {$descricaoBase}",
            'status'         => $t->status ?? 'confirmado',
            'conta_id'       => $origem->id,
            'valor'          => $t->valor,
            'data_movimento' => $dt,
            'observacoes'    => $t->observacoes,
        ]);

        $entrada->update([
            'descricao'      => "Transferência recebida: {$descricaoBase}",
            'status'         => $t->status ?? 'confirmado',
            'conta_id'       => $destino->id,
            'valor'          => $t->valor,
            'data_movimento' => $dt,
            'observacoes'    => $t->observacoes,
        ]);
    }

    private function descricaoBase(ContaFinanceira $origem, ContaFinanceira $destino): string
    {
        $o = trim((string)($origem->nome ?? ('Conta #' . $origem->id)));
        $d = trim((string)($destino->nome ?? ('Conta #' . $destino->id)));
        return "{$o} → {$d}";
    }

    /**
     * Normaliza payload (com fallback no model atual em updates)
     */
    private function normalizar(array $data, ?TransferenciaFinanceira $current = null): array
    {
        $p = $data;

        $p['conta_origem_id'] = $p['conta_origem_id'] ?? $current?->conta_origem_id ?? null;
        $p['conta_destino_id'] = $p['conta_destino_id'] ?? $current?->conta_destino_id ?? null;

        if (array_key_exists('valor', $p)) {
            $p['valor'] = (float)$p['valor'];
        } else {
            $p['valor'] = (float)($current?->valor ?? 0);
        }

        if (array_key_exists('data_movimento', $p)) {
            $p['data_movimento'] = $p['data_movimento']
                ? Carbon::parse($p['data_movimento'])
                : ($current?->data_movimento ? Carbon::parse($current->data_movimento) : now());
        } else {
            $p['data_movimento'] = $current?->data_movimento ? Carbon::parse($current->data_movimento) : now();
        }

        if (array_key_exists('observacoes', $p)) {
            $p['observacoes'] = $p['observacoes'] !== null ? (string)$p['observacoes'] : null;
        } else {
            $p['observacoes'] = $current?->observacoes ?? null;
        }

        if (array_key_exists('status', $p)) {
            $p['status'] = $p['status'] ? strtolower((string)$p['status']) : null;
        } else {
            $p['status'] = $current?->status ?? 'confirmado';
        }

        return $p;
    }

    private function validarPayload(array $p): void
    {
        $origem = (int)($p['conta_origem_id'] ?? 0);
        $destino = (int)($p['conta_destino_id'] ?? 0);

        if ($origem <= 0) {
            throw ValidationException::withMessages(['conta_origem_id' => 'Conta de origem é obrigatória.']);
        }
        if ($destino <= 0) {
            throw ValidationException::withMessages(['conta_destino_id' => 'Conta de destino é obrigatória.']);
        }
        if ($origem === $destino) {
            throw ValidationException::withMessages(['conta_destino_id' => 'Conta de destino deve ser diferente da origem.']);
        }

        $valor = (float)($p['valor'] ?? 0);
        if ($valor <= 0) {
            throw ValidationException::withMessages(['valor' => 'Valor deve ser maior que zero.']);
        }

        $status = (string)($p['status'] ?? 'confirmado');
        if (!in_array($status, ['confirmado', 'cancelado'], true)) {
            throw ValidationException::withMessages(['status' => 'Status inválido. Use confirmado ou cancelado.']);
        }
    }
}
