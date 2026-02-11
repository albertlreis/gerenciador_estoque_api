<?php

namespace App\Http\Resources;

use App\Helpers\AuthHelper;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoItemResource extends JsonResource
{
    public function toArray($request): array
    {
        $canViewCusto = AuthHelper::hasPermissao('pedidos.visualizar.todos');
        $custoUnitario = $this->variacao?->custo;
        $quantidade = (int) $this->quantidade;

        return [
            'id' => $this->id,
            'id_variacao' => $this->id_variacao,
            'id_deposito' => $this->id_deposito,
            'produto_id' => $this->variacao->produto_id ?? null,
            'nome_produto' => $this->variacao->produto->nome ?? '-',
            'referencia' => $this->variacao->referencia ?? '-',
            'quantidade' => $this->quantidade,
            'preco_unitario' => $this->preco_unitario,
            'subtotal' => $this->subtotal,
            'custo_unitario' => $this->when(
                $canViewCusto,
                $custoUnitario !== null ? (float) $custoUnitario : null
            ),
            'custo_subtotal' => $this->when(
                $canViewCusto && $custoUnitario !== null,
                (float) $custoUnitario * $quantidade
            ),
            'imagem' => $this->variacao->produto->imagens->first()->url_completa ?? null,
            'atributos' => AtributoResource::collection($this->variacao->atributos),
        ];
    }
}
