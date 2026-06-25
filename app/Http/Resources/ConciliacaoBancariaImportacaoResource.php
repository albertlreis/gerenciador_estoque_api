<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ConciliacaoBancariaImportacaoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => (int) $this->id,
            'conta_financeira_id' => (int) $this->conta_financeira_id,
            'banco_codigo' => $this->banco_codigo,
            'banco_nome' => $this->banco_nome,
            'agencia' => $this->agencia,
            'conta' => $this->conta,
            'conta_dv' => $this->conta_dv,
            'moeda' => $this->moeda,
            'data_inicio' => $this->data_inicio?->format('Y-m-d'),
            'data_fim' => $this->data_fim?->format('Y-m-d'),
            'saldo_final' => $this->saldo_final !== null ? (float) $this->saldo_final : null,
            'saldo_final_em' => $this->saldo_final_em?->toDateTimeString(),
            'arquivo_hash' => $this->arquivo_hash,
            'origem' => $this->origem ?: 'ofx',
            'origem_referencia' => $this->origem_referencia,
            'status' => $this->status,
            'resumo' => $this->resumo_json ?: [
                'total' => 0,
                'sugeridas' => 0,
                'pendentes' => 0,
                'conflitos' => 0,
                'conciliadas' => 0,
            ],
            'conta_financeira' => $this->whenLoaded('contaFinanceira', fn () => [
                'id' => $this->contaFinanceira?->id,
                'nome' => $this->contaFinanceira?->nome,
            ]),
            'transacoes' => ConciliacaoBancariaTransacaoResource::collection($this->whenLoaded('transacoes')),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
