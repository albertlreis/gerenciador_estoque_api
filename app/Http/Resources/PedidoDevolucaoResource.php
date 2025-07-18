<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource para representar uma devolução (com ou sem troca) associada a um pedido.
 *
 * @property int $id
 * @property string $tipo
 * @property string $motivo
 * @property string $status
 * @property \Illuminate\Support\Collection $itens
 * @property \App\Models\Credito|null $credito
 */
class PedidoDevolucaoResource extends JsonResource
{
    /**
     * Transforma o recurso em array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'status' => $this->status,
            'motivo' => $this->motivo,
            'itens' => $this->itens->map(fn ($item) => [
                'id' => $item->id,
                'quantidade' => $item->quantidade,
                'nome_produto' => optional($item->pedidoItem->variacao->produto)->nome,
                'nome_variacao' => optional($item->pedidoItem->variacao)->nome_completo,
                'trocas' => $item->trocaItens->map(fn ($troca) => [
                    'quantidade' => $troca->quantidade,
                    'preco_unitario' => $troca->preco_unitario,
                    'nome_completo' => optional($troca->variacaoNova)->nome_completo,
                ]),
            ]),
            'credito' => $this->credito ? [
                'valor' => $this->credito->valor,
                'utilizado' => $this->credito->utilizado,
                'data_validade' => optional($this->credito->data_validade)?->toDateString(),
            ] : null,
        ];
    }
}
