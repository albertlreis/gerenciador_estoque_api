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
            'cliente_nome' => optional($this->pedido->cliente)->nome,
            'produto_nome' => optional($this->produtoVariacao)->nome_completo,
            'status' => $this->status,
            'prazo_resposta' => optional($this->prazo_resposta)->format('d/m/Y'),
            'data_envio' => optional($this->data_envio)->format('d/m/Y'),
            'dias_para_vencer' => $this->prazo_resposta
                ? Carbon::now()->diffInDays($this->prazo_resposta, false)
                : null,
            'vencida' => $this->prazo_resposta && $this->prazo_resposta->isPast(),
            'quantidade' => $this->quantidade,
//            'quantidade_resposta' => $this->quantidade_resposta,
//            'motivo' => $this->motivo,
            'observacoes' => $this->observacoes,
            'pedido' => $this->whenLoaded('pedido', function () {
                return [
                    'id' => $this->pedido->id,
                    'numero' => $this->pedido->numero,
                    'data' => $this->pedido->data_pedido,
                    'cliente' => $this->pedido->cliente->nome ?? null,
                    'usuario' => [
                        'id' => $this->pedido->usuario->id ?? null,
                        'nome' => $this->pedido->usuario->nome ?? null,
                        'email' => $this->pedido->usuario->email ?? null,
                    ],
                ];
            }),
            'produto_variacao' => $this->whenLoaded('produtoVariacao', function () {
                return [
                    'id' => $this->produtoVariacao->id,
                    'descricao' => $this->produtoVariacao->descricao,
                    'referencia' => $this->produtoVariacao->referencia,
                    'produto' => [
                        'id' => $this->produtoVariacao->produto->id ?? null,
                        'nome' => $this->produtoVariacao->produto->nome ?? null,
                    ]
                ];
            })
        ];
    }
}
