<?php

namespace App\Http\Resources;

use App\Helpers\AuthHelper;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoItemResource extends JsonResource
{
    public function toArray($request): array
    {
        $podeVerCusto = AuthHelper::podeVerCustoPedido();
        $precoVenda = (float) ($this->preco_unitario ?? 0);
        $quantidade = (int) ($this->quantidade ?? 0);

        $item = [
            'id' => $this->id,
            'variacao_id' => $this->id_variacao,
            'produto_id' => $this->variacao->produto_id ?? null,
            'nome_produto' => $this->variacao->produto->nome ?? '-',
            'referencia' => $this->variacao->referencia ?? '-',
            'quantidade' => $quantidade,
            'preco_venda' => $precoVenda,
            'preco_unitario' => $precoVenda,
            'subtotal' => $this->subtotal,
            'id_deposito' => $this->id_deposito,
            'observacoes' => $this->observacoes,
            'imagem' => $this->variacao->produto->imagens->first()->url_completa ?? null,
            'atributos' => AtributoResource::collection($this->variacao->atributos),
        ];

        if ($podeVerCusto) {
            $precoCusto = $this->custo_unitario;
            if ($precoCusto === null) {
                $precoCusto = $this->variacao->custo ?? 0;
            }
            $precoCusto = (float) $precoCusto;
            $item['preco_custo'] = $precoCusto;
            $item['total_custo'] = round($precoCusto * $quantidade, 2);
        }

        return $item;
    }
}
