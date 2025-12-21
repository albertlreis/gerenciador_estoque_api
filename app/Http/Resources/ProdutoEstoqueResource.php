<?php

namespace App\Http\Resources;

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
