<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ConsignacaoResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'pedido_id' => $this->pedido_id,
            'cliente_nome' => optional($this->pedido->cliente)->nome,
            'produto_nome' => optional($this->produtoVariacao)->nome_completo,
            'quantidade' => $this->quantidade,
            'data_envio' => optional($this->data_envio)->format('d/m/Y'),
            'prazo_resposta' => optional($this->prazo_resposta)->format('d/m/Y'),
            'status' => $this->status,
            'observacoes' => $this->observacoes,
        ];
    }
}
