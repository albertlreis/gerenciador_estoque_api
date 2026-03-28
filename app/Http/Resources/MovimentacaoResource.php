<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MovimentacaoResource extends JsonResource
{
    public function toArray($request): array
    {
        $variacao = $this->variacao;
        $produto = $variacao?->produto;

        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'quantidade' => $this->quantidade,
            'data_movimentacao' => $this->data_movimentacao,
            'produto_id' => $produto?->id,
            'produto_nome' => $variacao?->nome_completo,
            'produto_nome_pai' => $produto?->nome,
            'produto_codigo_produto' => $produto?->codigo_produto,
            'produto_referencia' => $variacao?->referencia,
            'produto_sku_interno' => $variacao?->sku_interno,
            'produto_chave_variacao' => $variacao?->chave_variacao,
            'produto_identificador' => $variacao?->sku_interno ?: ($variacao?->referencia ?: $variacao?->chave_variacao),
            'deposito_origem_id' => $this->depositoOrigem?->id,
            'deposito_origem_nome' => $this->depositoOrigem?->nome,
            'deposito_destino_id' => $this->depositoDestino?->id,
            'deposito_destino_nome' => $this->depositoDestino?->nome,
            'usuario_id' => $this->usuario?->id,
            'usuario_nome' => $this->usuario?->nome,
            'observacao' => $this->observacao,
            'lote_id' => $this->lote_id,
            'ref_type' => $this->ref_type,
            'ref_id' => $this->ref_id,
        ];
    }
}
