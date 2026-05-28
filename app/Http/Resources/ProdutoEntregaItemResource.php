<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoEntregaItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'tipo_origem' => $this->tipo_origem,
            'origem_id' => $this->origem_id,
            'pedido_id' => $this->pedido_id,
            'pedido_item_id' => $this->pedido_item_id,
            'pedido_fabrica_item_id' => $this->pedido_fabrica_item_id,
            'consignacao_id' => $this->consignacao_id,
            'assistencia_item_id' => $this->assistencia_item_id,
            'devolucao_item_id' => $this->devolucao_item_id,
            'id_variacao' => $this->id_variacao,
            'quantidade_total' => $this->quantidade_total,
            'quantidade_reservada' => $this->quantidade_reservada,
            'quantidade_recebida' => $this->quantidade_recebida,
            'quantidade_expedida' => $this->quantidade_expedida,
            'quantidade_entregue' => $this->quantidade_entregue,
            'id_deposito_origem' => $this->id_deposito_origem,
            'id_deposito_destino' => $this->id_deposito_destino,
            'status' => $this->status,
            'previsao_entrega' => optional($this->previsao_entrega)?->toDateString(),
            'bloqueio_motivo' => $this->bloqueio_motivo,
            'pedido' => $this->whenLoaded('pedido'),
            'pedido_item' => $this->whenLoaded('pedidoItem'),
            'variacao' => $this->whenLoaded('variacao'),
            'deposito_origem' => $this->whenLoaded('depositoOrigem'),
            'deposito_destino' => $this->whenLoaded('depositoDestino'),
            'eventos' => $this->whenLoaded('eventos'),
            'created_at' => optional($this->created_at)?->toDateTimeString(),
            'updated_at' => optional($this->updated_at)?->toDateTimeString(),
        ];
    }
}
