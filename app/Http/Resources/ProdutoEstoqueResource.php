<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Resource para linha de "Estoque Atual por Produto e Depósito".
 * Agora expõe a localização no novo formato:
 * - setor, coluna, nivel, codigo_composto
 * - area (id, nome)
 * - dimensoes: [{dimensao_id, nome, valor}]
 */
class ProdutoEstoqueResource extends JsonResource
{
    /**
     * Converte o recurso em array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        // estoquesComLocalizacao é uma Collection de Estoque; pegamos o primeiro da linha agrupada
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

        if ($diasSemVenda === null && $ultimaVendaEm) {
            $timezone = config('app.timezone', 'America/Belem');
            $diasSemVenda = CarbonImmutable::parse($ultimaVendaEm, $timezone)
                ->startOfDay()
                ->diffInDays(CarbonImmutable::now($timezone)->startOfDay());
        }

        // Monta estrutura de dimensões dinâmicas
        $dimensoes = [];
        if ($localizacao && $localizacao->relationLoaded('valores')) {
            $dimensoes = $localizacao->valores->map(function ($v) {
                return [
                    'dimensao_id' => $v->dimensao_id,
                    'nome'        => $v->dimensao?->nome,
                    'valor'       => $v->valor,
                ];
            })->values()->toArray();
        }

        return [
            'produto_id'    => $this->produto_id ?? $this->produto?->id,
            'estoque_id'    => $estoque?->id,
            'variacao_id'   => $this->id,
            'produto_nome'  => $this->nome_completo,
            'produto_referencia' => $this->referencia,
            'deposito_nome' => $deposito?->nome ?? '—',
            'deposito_id'   => $deposito?->id ?? '—',
            'quantidade'    => (int) ($this->quantidade_estoque ?? 0),
            'custo_unitario' => $custoUnitario !== null ? (float) $custoUnitario : null,
            'data_entrada_estoque_atual' => $dataEntradaAtual ? CarbonImmutable::parse($dataEntradaAtual)->toDateString() : null,
            'ultima_venda_em' => $ultimaVendaEm ? CarbonImmutable::parse($ultimaVendaEm)->toDateString() : null,
            'dias_sem_venda' => $diasSemVenda !== null ? (int) $diasSemVenda : null,

            'localizacao' => $localizacao ? [
                'id'              => $localizacao->id,
                'setor'           => $localizacao->setor,
                'coluna'          => $localizacao->coluna,
                'nivel'           => $localizacao->nivel,
                'codigo_composto' => $localizacao->codigo_composto,
                'observacoes'     => $localizacao->observacoes,
                'area'            => $localizacao->relationLoaded('area') ? [
                    'id'   => $localizacao->area?->id,
                    'nome' => $localizacao->area?->nome,
                ] : null,
                'dimensoes'       => $dimensoes,
            ] : null,
        ];
    }
}
