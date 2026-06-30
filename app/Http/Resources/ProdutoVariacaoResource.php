<?php

namespace App\Http\Resources;

use App\Models\ProdutoImagem;
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
        $estoqueFisicoTotal = 0;
        $estoqueReservadoTotal = 0;
        if ($this->relationLoaded('estoques')) {
            foreach ($this->estoques as $estoque) {
                $quantidadeFisica = (int) ($estoque->quantidade ?? 0);
                $quantidadeReservada = method_exists($estoque, 'quantidadeReservadaAberta')
                    ? $estoque->quantidadeReservadaAberta()
                    : 0;

                $estoqueFisicoTotal += $quantidadeFisica;
                $estoqueReservadoTotal += $quantidadeReservada;
            }
        } elseif ($this->relationLoaded('estoque')) {
            $estoqueFisicoTotal = (int) ($this->estoque?->quantidade ?? 0);
            $estoqueReservadoTotal = $this->estoque && method_exists($this->estoque, 'quantidadeReservadaAberta')
                ? $this->estoque->quantidadeReservadaAberta()
                : 0;
        }
        $estoqueDisponivelTotal = max(0, $estoqueFisicoTotal - $estoqueReservadoTotal);

        $outletResource = function ($outlet) {
            if ($outlet && !$outlet->relationLoaded('variacao')) {
                $outlet->setRelation('variacao', $this->resource);
            }

            return $outlet ? new ProdutoVariacaoOutletResource($outlet) : null;
        };

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
            'imagem_url' => ProdutoImagem::normalizarUrlPublica($this->imagem?->url ?? $this->imagem_url),
            'imagens' => $this->whenLoaded('imagens', function () {
                return $this->imagens->map(fn ($imagem) => [
                    'id' => $imagem->id,
                    'id_variacao' => $imagem->id_variacao,
                    'url' => $imagem->url,
                    'url_completa' => ProdutoImagem::normalizarUrlPublica($imagem->url ?? $imagem->url_completa),
                    'principal' => (bool) $imagem->principal,
                    'ordem' => (int) ($imagem->ordem ?? 0),
                ])->values();
            }),
            'altura' => $this->dimensao_3,
            'largura' => $this->dimensao_1,
            'profundidade' => $this->dimensao_2,
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
            'estoque_total' => $estoqueDisponivelTotal,
            'quantidade_fisica' => $estoqueFisicoTotal,
            'quantidade_reservada' => $estoqueReservadoTotal,
            'quantidade_disponivel' => $estoqueDisponivelTotal,
//            'estoque' => ['quantidade' => $estoqueTotal],
            'estoques' => EstoqueResource::collection($this->whenLoaded('estoques')),

            // ✅ outlets
            'estoque_outlet_total' => $estoqueOutletTotal,
            'outlet_restante_total' => $outletRestanteTotal,
            'outlet' => $this->whenLoaded('outlet', fn () => $outletResource($this->outlet)),
            'outlets' => $this->whenLoaded('outlets', fn () => $this->outlets
                ->map(fn ($outlet) => $outletResource($outlet))
                ->values()),

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
                'imagem' => ProdutoImagem::normalizarUrlPublica($this->produto->imagemPrincipal?->url),
            ]),
        ];
    }
}
