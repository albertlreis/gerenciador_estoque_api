<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AssistenciaChamadoItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'chamado_id' => $this->chamado_id,
            'produto_id' => $this->produto_id,
            'variacao_id' => $this->variacao_id,
            'numero_serie' => $this->numero_serie,
            'lote' => $this->lote,
            'defeito' => new AssistenciaDefeitoResource($this->whenLoaded('defeito')),
            'descricao_defeito_livre' => $this->descricao_defeito_livre,
            'status_item' => $this->status_item?->value ?? $this->status_item,
            'pedido_id' => $this->pedido_id,
            'pedido_item_id' => $this->pedido_item_id,
            'consignacao_id' => $this->consignacao_id,
            'consignacao_item_id' => $this->consignacao_item_id,
            'deposito_origem_id' => $this->deposito_origem_id,
            'assistencia_id' => $this->assistencia_id,
            'deposito_assistencia_id' => $this->deposito_assistencia_id,
            'rastreio_envio' => $this->rastreio_envio,
            'rastreio_retorno' => $this->rastreio_retorno,
            'data_envio' => $this->data_envio?->toDateString(),
            'data_retorno' => $this->data_retorno?->toDateString(),
            'valor_orcado' => $this->valor_orcado !== null ? (float) $this->valor_orcado : null,
            'aprovacao' => $this->aprovacao?->value ?? $this->aprovacao,
            'data_aprovacao' => $this->data_aprovacao?->toDateString(),
            'observacoes' => $this->observacoes,
        ];
    }
}
