<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ConciliacaoBancariaTransacaoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'importacao_id' => (int) $this->importacao_id,
            'conta_financeira_id' => (int) $this->conta_financeira_id,
            'fit_id' => $this->fit_id,
            'identificador' => $this->identificador,
            'origem' => $this->origem ?: 'ofx',
            'origem_transacao_id' => $this->origem_transacao_id,
            'data_movimento' => $this->data_movimento?->format('Y-m-d'),
            'valor' => (float) $this->valor,
            'tipo_ofx' => $this->tipo_ofx,
            'checknum' => $this->checknum,
            'memo' => $this->memo,
            'status' => $this->status,
            'forma_pagamento' => $this->forma_pagamento,
            'candidato_tipo' => $this->candidato_tipo,
            'candidato_id' => $this->candidato_id ? (int) $this->candidato_id : null,
            'candidato_score' => $this->candidato_score ? (int) $this->candidato_score : null,
            'candidato_motivo' => $this->candidato_motivo,
            'candidato' => $this->candidato_json,
            'pagamento_type' => $this->pagamento_type,
            'pagamento_id' => $this->pagamento_id ? (int) $this->pagamento_id : null,
            'lancamento_financeiro_id' => $this->lancamento_financeiro_id ? (int) $this->lancamento_financeiro_id : null,
            'conciliado_em' => $this->conciliado_em?->toDateTimeString(),
            'conciliado_por' => $this->conciliado_por ? (int) $this->conciliado_por : null,
            'observacoes' => $this->observacoes,
            'conta_financeira' => $this->whenLoaded('contaFinanceira', fn () => [
                'id' => $this->contaFinanceira?->id,
                'nome' => $this->contaFinanceira?->nome,
            ]),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
