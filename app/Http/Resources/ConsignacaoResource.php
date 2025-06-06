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

            // Nome resumido do cliente e produto para listagem
            'cliente_nome' => optional($this->pedido->cliente)->nome,
            'produto_nome' => optional($this->produtoVariacao)->nome_completo,

            'status' => $this->status,
            'quantidade' => $this->quantidade,

            // Datas formatadas para exibição
            'data_envio' => optional($this->data_envio)->format('d/m/Y'),
            'prazo_resposta' => optional($this->prazo_resposta)->format('d/m/Y'),

            // Indicadores úteis para alertas e badges
            'dias_para_vencer' => $this->prazo_resposta
                ? Carbon::now()->diffInDays($this->prazo_resposta, false)
                : null,
            'vencida' => $this->prazo_resposta && $this->prazo_resposta->isPast(),
        ];
    }
}
