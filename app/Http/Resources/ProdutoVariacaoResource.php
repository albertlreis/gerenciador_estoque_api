<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property int $produto_id
 * @property string $nome
 * @property string $nome_completo
 * @property float $preco
 * @property float|null $preco_promocional
 * @property float $custo
 * @property string|null $referencia
 * @property string|null $codigo_barras
 */
class ProdutoVariacaoResource extends JsonResource
{
    /**
     * Transforma o recurso em um array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        $preco = $this->preco;
        $precoPromocional = $this->preco_promocional;
        $temDesconto = $precoPromocional !== null && $precoPromocional < $preco;

        // Ordena atributos no resource também (cautela extra)
        $atributosOrdenados = $this->whenLoaded('atributos', function () {
            return $this->atributos->sortBy(function ($a) {
                return mb_strtolower(($a->atributo ?? '') . ' ' . ($a->valor ?? ''));
            });
        });

        return [
            'id' => $this->id,
            'produto_id' => $this->produto_id,
            'nome' => $this->nome,
            'nome_completo' => $this->nome_completo,
            'referencia' => $this->referencia,
            'codigo_barras' => $this->codigo_barras,
            'preco' => $preco,
            'preco_promocional' => $temDesconto ? $precoPromocional : null,
            'custo' => $this->custo,
            'estoque_total' => $this->whenLoaded('estoque', fn() => $this->estoque->quantidade ?? 0),
            'estoque_outlet_total' => $this->whenLoaded('outlets', fn() => $this->outlets->sum('quantidade')),
            'outlet_restante_total' => $this->whenLoaded('outlets', fn() => $this->outlets->sum('quantidade_restante')),
            'estoque' => $this->whenLoaded('estoque', fn() => [
                'quantidade' => $this->estoque->quantidade ?? 0,
            ]),
            'outlet' => new ProdutoVariacaoOutletResource($this->whenLoaded('outlet')),
            'outlets' => ProdutoVariacaoOutletResource::collection($this->whenLoaded('outlets')),
            'atributos' => ProdutoVariacaoAtributoResource::collection($atributosOrdenados ?? $this->whenLoaded('atributos')),
            'produto' => $this->whenLoaded('produto', fn () => [
                'id'     => $this->produto->id,
                'nome'   => $this->produto->nome,
                'imagem' => $this->produto->imagemPrincipal?->url,
            ]),
        ];
    }
}
