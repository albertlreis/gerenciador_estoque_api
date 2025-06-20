<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsignacaoResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'pedido_id' => $this->pedido_id,
            'numero_externo' => $this->pedido->numero_externo,
            'cliente_nome' => $this->pedido->cliente->nome ?? '-',
            'vendedor_nome' => $this->pedido->usuario->nome ?? '-',
            'quantidade' => $this->quantidade,
            'data_envio' => $this->data_envio?->format('d/m/Y'),
            'prazo_resposta' => $this->prazo_resposta?->format('d/m/Y'),
            'status' => $this->status
        ];
    }
}
