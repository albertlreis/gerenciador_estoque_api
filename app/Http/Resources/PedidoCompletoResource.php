<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PedidoCompletoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero_externo,
            'data_pedido' => $this->data_pedido,
            'status' => optional($this->statusAtual)->status,
            'cliente' => $this->cliente ? [
                'id' => $this->cliente->id,
                'nome' => $this->cliente->nome,
                'email' => $this->cliente->email,
                'telefone' => $this->cliente->telefone,
            ] : null,
            'parceiro' => $this->parceiro ? [
                'id' => $this->parceiro->id,
                'nome' => $this->parceiro->nome,
            ] : null,
            'usuario' => $this->usuario ? [
                'id' => $this->usuario->id,
                'nome' => $this->usuario->nome,
            ] : null,
            'valor_total' => $this->valor_total,
            'observacoes' => $this->observacoes,
            'itens' => PedidoItemResource::collection($this->itens),
            'historico' => PedidoStatusResource::collection($this->historicoStatus->sortBy('data_status')->values()),
            'devolucoes' => PedidoDevolucaoResource::collection($this->whenLoaded('devolucoes')),
        ];
    }
}
