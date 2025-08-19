<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AssistenciaChamadoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'status' => $this->status?->value ?? $this->status,
            'prioridade' => $this->prioridade?->value ?? $this->prioridade,
            'origem_tipo' => $this->origem_tipo,
            'origem_id' => $this->origem_id,
            'cliente_id' => $this->cliente_id,
            'fornecedor_id' => $this->fornecedor_id,
            'assistencia' => new AssistenciaResource($this->whenLoaded('assistencia')),
            'sla_data_limite' => $this->sla_data_limite?->toDateString(),
            'canal_abertura' => $this->canal_abertura,
            'observacoes' => $this->observacoes,
            'itens' => AssistenciaChamadoItemResource::collection($this->whenLoaded('itens')),
            'logs' => AssistenciaChamadoLogResource::collection($this->whenLoaded('logs')),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}
