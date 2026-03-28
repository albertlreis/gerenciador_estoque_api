<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property int $produto_id
 * @property string|null $nome
 * @property string|null $nome_completo
 * @property float|null $preco
 * @property float|null $preco_promocional
 * @property float|null $custo
 * @property string|null $referencia
 * @property string|null $codigo_barras
 */
class ProdutoVariacaoResource extends JsonResource
{
    public function toArray($request): array
    {
        $preco = (float) ($this->preco ?? 0);
        $precoPromocional = $this->preco_promocional;
        $temDesconto = $precoPromocional !== null && (float) $precoPromocional < $preco;

        // Ordena atributos no resource (extra segurança)
        $atributosOrdenados = $this->whenLoaded('atributos', function () {
            return $this->atributos->sortBy(function ($a) {
                return mb_strtolower(($a->atributo ?? '') . ' ' . ($a->valor ?? ''));
            })->values();
        });

        // Estoque agregado (plural "estoques")
        $estoqueTotal = 0;
        if ($this->relationLoaded('estoques')) {
            $estoqueTotal = (int) $this->estoques->sum('quantidade');
        } elseif ($this->relationLoaded('estoque')) {
            // fallback legado (se existir relação singular)
            $estoqueTotal = (int) ($this->estoque?->quantidade ?? 0);
        }

        // Outlet agregados
        $estoqueOutletTotal = $this->whenLoaded('outlets', fn () => (int) $this->outlets->sum('quantidade'));
        $outletRestanteTotal = $this->whenLoaded('outlets', fn () => (int) $this->outlets->sum('quantidade_restante'));

        return [
            'id' => $this->id,
            'produto_id' => $this->produto_id,
            'nome' => $this->nome,
            'nome_completo' => $this->nome_completo,
            'referencia' => $this->referencia,
            'sku_interno' => $this->sku_interno,
            'chave_variacao' => $this->chave_variacao,
            'codigo_barras' => $this->codigo_barras,
            'imagem_url' => $this->imagem_url,
            'dimensao_1' => $this->dimensao_1,
            'dimensao_2' => $this->dimensao_2,
            'dimensao_3' => $this->dimensao_3,
            'cor' => $this->cor,
            'lado' => $this->lado,
            'material_oficial' => $this->material_oficial,
            'acabamento_oficial' => $this->acabamento_oficial,
            'conflito_codigo' => (bool) $this->conflito_codigo,
            'status_revisao' => $this->status_revisao?->value ?? $this->status_revisao,

            'preco' => $preco,
            'preco_promocional' => $temDesconto ? (float) $precoPromocional : null,
            'custo' => (float) ($this->custo ?? 0),

            // ✅ estoques compat + agregado
            'estoque_total' => $estoqueTotal,
            'quantidade_disponivel' => $estoqueTotal,
//            'estoque' => ['quantidade' => $estoqueTotal],
            'estoques' => EstoqueResource::collection($this->whenLoaded('estoques')),

            // ✅ outlets
            'estoque_outlet_total' => $estoqueOutletTotal,
            'outlet_restante_total' => $outletRestanteTotal,
            'outlet' => new ProdutoVariacaoOutletResource($this->whenLoaded('outlet')),
            'outlets' => ProdutoVariacaoOutletResource::collection($this->whenLoaded('outlets')),

            // ✅ atributos (ordenados se carregados)
            'atributos' => ProdutoVariacaoAtributoResource::collection(
                $atributosOrdenados ?? $this->whenLoaded('atributos')
            ),
            'codigos_historicos' => $this->whenLoaded('codigosHistoricos', function () {
                return $this->codigosHistoricos->map(fn ($codigo) => [
                    'id' => $codigo->id,
                    'codigo' => $codigo->codigo,
                    'codigo_origem' => $codigo->codigo_origem,
                    'codigo_modelo' => $codigo->codigo_modelo,
                    'fonte' => $codigo->fonte,
                    'aba_origem' => $codigo->aba_origem,
                    'principal' => (bool) $codigo->principal,
                ])->values();
            }),

            'produto' => $this->whenLoaded('produto', fn () => [
                'id'     => $this->produto->id,
                'nome'   => $this->produto->nome,
                'codigo_produto' => $this->produto->codigo_produto,
                'imagem' => $this->produto->imagemPrincipal?->url,
            ]),
        ];
    }
}
