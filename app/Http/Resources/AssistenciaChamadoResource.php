<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AssistenciaChamadoResource extends JsonResource
{
    public function toArray($request): array
    {
        $prazoMax = null;
        if ($this->relationLoaded('itens')) {
            $prazoMax = optional(
                $this->itens->max('prazo_finalizacao')
            )->toDateString();
        }

        return [
            'id'              => $this->id,
            'numero'          => $this->numero,
            'status'          => $this->status?->value,
            'prioridade'      => $this->prioridade?->value,
            'sla_data_limite' => optional($this->sla_data_limite)->toDateString(),
            'prazo_max'       => $prazoMax,
            'origem_tipo'     => $this->origem_tipo,
            'origem_id'       => $this->origem_id,

            'assistencia'     => $this->assistencia?->only(['id','nome']),

            'local_reparo'      => $this->local_reparo?->value,
            'custo_responsavel' => $this->custo_responsavel?->value,

            'pedido' => $this->whenLoaded('pedido', function () {
                $pedido = $this->pedido; // relação já carregada

                return [
                    'id'      => $pedido->id,
                    'numero'  => $pedido->numero_externo,
                    'data'    => $pedido->data_pedido,
                    'cliente' => $pedido->cliente?->nome,
                    'fornecedor' => $pedido->parceiro?->only(['id','nome']),
                    'pedidos_fabrica' => collect($pedido->pedidosFabrica ?? [])
                        ->unique('id')
                        ->map(fn ($pf) => [
                            'id'                    => $pf->id,
                            'status'                => $pf->status,
                            'data_previsao_entrega' => optional($pf->data_previsao_entrega)->toDateString(),
                        ])->values(),
                ];
            }),

            'observacoes' => $this->observacoes,
            'logs'        => AssistenciaChamadoLogResource::collection($this->whenLoaded('logs')),
            'itens'       => AssistenciaChamadoItemResource::collection($this->whenLoaded('itens')),
            'updated_at'  => optional($this->updated_at)->toDateTimeString(),
        ];
    }
}
