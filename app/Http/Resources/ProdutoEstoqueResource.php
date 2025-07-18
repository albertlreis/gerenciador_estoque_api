<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

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
        $estoque = $this->estoquesComLocalizacao instanceof Collection
            ? $this->estoquesComLocalizacao->first()
            : null;

        $localizacao = $estoque?->localizacao;
        $deposito = $estoque?->deposito;

        return [
            'produto_id' => $this->produto_id ?? $this->produto?->id,
            'estoque_id' => $estoque?->id,
            'produto_nome' => $this->nome_completo,
            'deposito_nome' => $deposito?->nome ?? '—',
            'deposito_id' => $deposito?->id ?? '—',
            'quantidade' => (int) $this->quantidade_estoque,

            'localizacao' => [
                'id' => $localizacao?->id,
                'corredor' => $localizacao?->corredor,
                'prateleira' => $localizacao?->prateleira,
                'coluna' => $localizacao?->coluna,
                'nivel' => $localizacao?->nivel,
                'observacoes' => $localizacao?->observacoes,
            ],
        ];
    }
}
