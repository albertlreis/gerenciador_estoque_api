<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ConsignacaoDetalhadaResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'pedido_id' => $this->pedido_id,
            'cliente_nome' => optional($this->pedido->cliente)->nome,
            'quantidade' => $this->quantidade,
            'data_envio' => optional($this->data_envio)->format('d/m/Y'),
            'prazo_resposta' => optional($this->prazo_resposta)->format('d/m/Y'),
            'status' => $this->status,
            'observacoes' => $this->observacoes,
            'produto_nome' => optional($this->produtoVariacao)->nome_completo,
            'produto' => [
                'id' => $this->produto_variacao_id,
                'nome' => optional($this->produtoVariacao->produto)->nome,
                'variacao' => $this->produtoVariacao->nome_completo,
                'descricao' => $this->produtoVariacao->descricao ?? null,
                'imagem' => optional($this->produtoVariacao->produto->imagemPrincipal)->url ?? null,
            ],
        ];
    }
}
