<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PedidoSeparacaoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'numero_externo' => $this->numero_externo,
            'data_pedido' => optional($this->data_pedido)->toDateString(),
            'valor_total' => (float) $this->valor_total,
            'status' => $this->statusAtual?->status?->value ?? $this->statusAtual?->status,
            'separacao_status' => $this->separacao_status,
            'is_consignacao' => $this->consignacoes()->exists(),
            'cliente' => $this->cliente ? [
                'id' => $this->cliente->id,
                'nome' => $this->cliente->nome,
                'telefone' => $this->cliente->telefone,
            ] : null,
            'parceiro' => $this->parceiro ? [
                'id' => $this->parceiro->id,
                'nome' => $this->parceiro->nome,
            ] : null,
            'vendedor' => $this->usuario ? [
                'id' => $this->usuario->id,
                'nome' => $this->usuario->nome,
            ] : null,
            'total_itens' => (int) $this->itens->sum('quantidade'),
            'separado_em' => optional($this->separado_em)->toIso8601String(),
            'entregue_em' => optional($this->entregue_em)->toIso8601String(),
            'separado_por' => $this->separadoPor ? [
                'id' => $this->separadoPor->id,
                'nome' => $this->separadoPor->nome,
            ] : null,
            'entregue_por' => $this->entreguePor ? [
                'id' => $this->entreguePor->id,
                'nome' => $this->entreguePor->nome,
            ] : null,
            'itens' => $this->itens->map(fn ($item) => [
                'id' => $item->id,
                'quantidade' => (int) $item->quantidade,
                'deposito_id' => $item->id_deposito,
                'produto' => $item->variacao?->produto?->nome,
                'referencia' => $item->variacao?->referencia,
                'atributos' => $item->variacao?->atributos?->map(fn ($atributo) => [
                    'atributo' => $atributo->atributo,
                    'valor' => $atributo->valor,
                ])->values() ?? [],
            ])->values(),
        ];
    }
}
