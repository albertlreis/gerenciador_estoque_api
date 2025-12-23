<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DespesaRecorrenteExecucao */
class DespesaRecorrenteExecucaoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'despesa_recorrente_id' => $this->despesa_recorrente_id,

            'competencia' => optional($this->competencia)->format('Y-m-d'),
            'data_prevista' => optional($this->data_prevista)->format('Y-m-d'),
            'data_geracao' => optional($this->data_geracao)?->toISOString(),

            'conta_pagar_id' => $this->conta_pagar_id,
            'status' => $this->status,
            'erro_msg' => $this->erro_msg,

            'meta' => $this->meta_json,

            'created_at' => optional($this->created_at)?->toISOString(),
            'updated_at' => optional($this->updated_at)?->toISOString(),
        ];
    }
}
