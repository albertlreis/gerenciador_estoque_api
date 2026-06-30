<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\JsonResource;

class EstoqueLocalizacaoPendenteResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $variacao = $this->resource->relationLoaded('variacao') ? $this->variacao : null;
        $produto = $variacao?->relationLoaded('produto') ? $variacao->produto : null;
        $deposito = $this->resource->relationLoaded('deposito') ? $this->deposito : null;
        $dataEntradaAtual = $this->data_entrada_estoque_atual;
        $ultimaVendaEm = $this->ultima_venda_em;
        $diasSemVenda = null;

        if ($ultimaVendaEm || $dataEntradaAtual) {
            $timezone = config('app.timezone', 'America/Belem');
            $baseDiasSemVenda = $ultimaVendaEm ?: $dataEntradaAtual;
            $diasSemVenda = CarbonImmutable::parse($baseDiasSemVenda, $timezone)
                ->startOfDay()
                ->diffInDays(CarbonImmutable::now($timezone)->startOfDay());
        }

        return [
            'estoque_id' => $this->id,
            'produto_id' => $produto?->id,
            'variacao_id' => $this->id_variacao,
            'produto_nome' => $variacao?->nome_completo,
            'produto_nome_pai' => $produto?->nome,
            'produto_codigo_produto' => $produto?->codigo_produto,
            'produto_referencia' => $variacao?->referencia,
            'produto_sku_interno' => $variacao?->sku_interno,
            'produto_chave_variacao' => $variacao?->chave_variacao,
            'produto_identificador' => $variacao?->sku_interno ?: ($variacao?->referencia ?: $variacao?->chave_variacao),
            'deposito_id' => $this->id_deposito,
            'deposito_nome' => $deposito?->nome,
            'quantidade' => (int) ($this->quantidade ?? 0),
            'quantidade_reservada_cliente' => (int) ($this->quantidade_reservada_cliente ?? 0),
            'data_entrada_estoque_atual' => $dataEntradaAtual ? CarbonImmutable::parse($dataEntradaAtual)->toDateString() : null,
            'ultima_venda_em' => $ultimaVendaEm ? CarbonImmutable::parse($ultimaVendaEm)->toDateString() : null,
            'dias_sem_venda' => $diasSemVenda !== null ? (int) $diasSemVenda : null,
            'localizacao' => null,
            'localizacao_id' => null,
        ];
    }
}
