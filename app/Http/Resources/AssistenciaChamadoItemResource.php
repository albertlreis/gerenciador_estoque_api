<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property int $chamado_id
 * @property int|null $variacao_id
 * @property \Illuminate\Support\Carbon|null $prazo_finalizacao
 * @property \Illuminate\Support\Carbon|null $data_envio
 * @property \Illuminate\Support\Carbon|null $data_retorno
 * @property float|null $valor_orcado
 */
class AssistenciaChamadoItemResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $variacaoArr = $this->relationLoaded('variacao') && $this->variacao
            ? [
                'id'            => $this->variacao->id,
                'nome_completo' => $this->variacao->nome_completo,
                'referencia'    => $this->variacao->referencia,
                'produto'       => $this->variacao->produto?->only(['id','nome']),
            ]
            : null;

        return [
            'id'                 => $this->id,
            'chamado_id'         => $this->chamado_id,

            'variacao_id'        => $this->variacao_id,
            'variacao'           => $variacaoArr,

            'defeito'            => $this->defeito?->only(['id','codigo','descricao']),
            'status_item'        => $this->status_item?->value ?? $this->status_item,

            'nota_numero'        => $this->nota_numero,
            'prazo_finalizacao'  => optional($this->prazo_finalizacao)->toDateString(),

            'pedido_item_id'     => $this->pedido_item_id,
            'consignacao_id'     => $this->consignacao_id,

            'deposito_origem_id'      => $this->deposito_origem_id,
            'deposito_assistencia_id' => $this->deposito_assistencia_id,

            'rastreio_envio'     => $this->rastreio_envio,
            'rastreio_retorno'   => $this->rastreio_retorno,
            'data_envio'         => $this->data_envio?->toDateString(),
            'data_retorno'       => $this->data_retorno?->toDateString(),

            'valor_orcado'       => $this->valor_orcado !== null ? (float) $this->valor_orcado : null,
            'aprovacao'          => $this->aprovacao?->value ?? $this->aprovacao,
            'data_aprovacao'     => $this->data_aprovacao?->toDateString(),

            'observacoes'        => $this->observacoes,
        ];
    }
}
