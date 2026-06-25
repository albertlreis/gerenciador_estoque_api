<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ProdutoEstoqueResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $estoques = $this->estoquesComLocalizacao instanceof Collection
            ? $this->estoquesComLocalizacao
            : collect();

        $estoque = $estoques->firstWhere('quantidade', '>', 0) ?? $estoques->first();
        $deposito = $estoque?->deposito;
        $localizacao = $estoque?->localizacao;
        $custoUnitario = $this->custo ?? null;
        $dataEntradaAtual = $estoque?->data_entrada_estoque_atual ?? $this->data_entrada_estoque_atual ?? null;
        $ultimaVendaEm = $estoque?->ultima_venda_em ?? $this->ultima_venda_em ?? null;
        $diasSemVenda = $this->dias_sem_venda ?? null;
        $estoqueOutletTotal = (int) ($this->quantidade_outlet ?? $this->estoque_outlet_total ?? 0);
        $outletRestanteTotal = (int) ($this->quantidade_outlet_restante ?? $this->outlet_restante_total ?? 0);

        if ($diasSemVenda === null && ($ultimaVendaEm || $dataEntradaAtual)) {
            $timezone = config('app.timezone', 'America/Belem');
            $baseDiasSemVenda = $ultimaVendaEm ?: $dataEntradaAtual;
            $diasSemVenda = CarbonImmutable::parse($baseDiasSemVenda, $timezone)
                ->startOfDay()
                ->diffInDays(CarbonImmutable::now($timezone)->startOfDay());
        }

        return [
            'produto_id' => $this->produto_id ?? $this->produto?->id,
            'estoque_id' => $estoque?->id,
            'variacao_id' => $this->id,
            'produto_nome' => $this->nome_completo,
            'produto_nome_pai' => $this->produto?->nome,
            'produto_codigo_produto' => $this->produto?->codigo_produto,
            'produto_referencia' => $this->referencia,
            'produto_sku_interno' => $this->sku_interno,
            'produto_chave_variacao' => $this->chave_variacao,
            'produto_identificador' => $this->sku_interno ?: ($this->referencia ?: $this->chave_variacao),
            'deposito_nome' => $deposito?->nome ?? '-',
            'deposito_id' => $deposito?->id ?? null,
            'quantidade' => (int) ($this->quantidade_estoque ?? 0),
            'estoque_outlet_total' => $estoqueOutletTotal,
            'outlet_restante_total' => $outletRestanteTotal,
            'is_outlet' => $outletRestanteTotal > 0,
            'custo_unitario' => $custoUnitario !== null ? (float) $custoUnitario : null,
            'data_entrada_estoque_atual' => $dataEntradaAtual ? CarbonImmutable::parse($dataEntradaAtual)->toDateString() : null,
            'ultima_venda_em' => $ultimaVendaEm ? CarbonImmutable::parse($ultimaVendaEm)->toDateString() : null,
            'dias_sem_venda' => $diasSemVenda !== null ? (int) $diasSemVenda : null,
            'localizacao' => $localizacao ? [
                'id' => $localizacao->id,
                'deposito_id' => $localizacao->deposito_id,
                'area' => $localizacao->area,
                'corredor' => $localizacao->corredor,
                'setor' => $localizacao->setor,
                'coluna' => $localizacao->coluna,
                'codigo_composto' => $localizacao->codigo_composto,
                'observacoes' => $localizacao->observacoes,
                'ativo' => (bool) $localizacao->ativo,
            ] : null,
        ];
    }
}
