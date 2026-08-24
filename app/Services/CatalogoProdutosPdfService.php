<?php

namespace App\Services;

use App\Models\Produto;
use App\Models\ProdutoVariacao;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CatalogoProdutosPdfService
{
    public function __construct(
        private readonly OutletCatalogoPricingService $outletPricing,
        private readonly PdfImageService $pdfImage,
    ) {
    }

    /**
     * @param Collection<int, Produto> $produtos
     * @param array<int, int>|null $variationIds
     * @return array<int, array<string, mixed>>
     */
    public function build(Collection $produtos, ?array $variationIds = null): array
    {
        $selecionadas = $variationIds === null
            ? null
            : array_fill_keys(array_map('intval', $variationIds), true);

        return $produtos
            ->flatMap(function (Produto $produto) use ($selecionadas) {
                return collect($produto->variacoes ?? [])
                    ->filter(fn (ProdutoVariacao $variacao) => $selecionadas === null || isset($selecionadas[(int) $variacao->id]))
                    ->map(function (ProdutoVariacao $variacao) use ($produto) {
                        $variacao->setRelation('produto', $produto);

                        return $variacao;
                    });
            })
            ->groupBy(fn (ProdutoVariacao $variacao) => $this->groupKey($variacao))
            ->map(fn (Collection $variacoes) => $this->buildCard($variacoes))
            ->filter()
            ->sortBy(fn (array $card) => Str::lower(($card['nome'] ?? '') . '|' . ($card['referencia'] ?? '')))
            ->values()
            ->all();
    }

    private function groupKey(ProdutoVariacao $variacao): string
    {
        $identidade = trim((string) ($variacao->referencia ?: $variacao->sku_interno ?: $variacao->chave_variacao ?: ''));
        if ($identidade === '') {
            return 'VAR-' . (int) $variacao->id;
        }

        return Str::upper((string) preg_replace('/\s+/u', '', $identidade));
    }

    /**
     * @param Collection<int, ProdutoVariacao> $variacoes
     * @return array<string, mixed>|null
     */
    private function buildCard(Collection $variacoes): ?array
    {
        if ($variacoes->isEmpty()) {
            return null;
        }

        $produtos = $variacoes
            ->map(fn (ProdutoVariacao $variacao) => $variacao->produto)
            ->filter()
            ->sortBy('id')
            ->values();
        /** @var Produto|null $produto */
        $produto = $produtos->first();

        $precos = $variacoes
            ->map(function (ProdutoVariacao $variacao) {
                $precoBase = round((float) ($variacao->preco ?? 0), 2);
                if ($precoBase <= 0) {
                    return null;
                }

                $pricing = $this->outletPricing->build(collect([$variacao]));
                $temOutlet = $pricing['preco_final_venda'] !== null;

                return [
                    'variacao' => $variacao,
                    'preco' => $temOutlet ? (float) $pricing['preco_final_venda'] : $precoBase,
                    'preco_original' => $temOutlet ? (float) $pricing['preco_venda'] : null,
                    'outlet' => $temOutlet,
                    'percentual_desconto' => $temOutlet ? (float) $pricing['percentual_desconto'] : 0.0,
                    'pagamento_label' => $temOutlet ? $pricing['pagamento_label'] : null,
                ];
            })
            ->filter()
            ->values();

        if ($precos->isEmpty()) {
            /** @var ProdutoVariacao $variacaoPrincipal */
            $variacaoPrincipal = $variacoes
                ->sortBy(fn (ProdutoVariacao $variacao) => sprintf(
                    '%010d|%010d',
                    (int) ($variacao->produto_id ?? PHP_INT_MAX),
                    (int) ($variacao->id ?? PHP_INT_MAX),
                ))
                ->first();
            $melhorPreco = [
                'preco' => null,
                'preco_original' => null,
                'outlet' => false,
                'percentual_desconto' => 0.0,
                'pagamento_label' => null,
            ];
            $precoLabel = 'Preço sob consulta';
        } else {
            $precosConsiderados = $precos->contains(fn (array $item) => $item['outlet'])
                ? $precos->where('outlet', true)->values()
                : $precos;
            $precosDistintos = $precosConsiderados
                ->pluck('preco')
                ->map(fn ($preco) => round((float) $preco, 2))
                ->unique()
                ->values();
            $melhorPreco = $precosConsiderados
                ->sortBy([
                    ['preco', 'asc'],
                    ['percentual_desconto', 'desc'],
                ])
                ->first();
            /** @var ProdutoVariacao $variacaoPrincipal */
            $variacaoPrincipal = $melhorPreco['variacao'];
            $precoLabel = $this->formatMoney((float) $melhorPreco['preco']);
            if ($precosDistintos->count() > 1) {
                $precoLabel = 'A partir de ' . $precoLabel;
            }
        }

        $atributos = $variacoes
            ->flatMap(fn (ProdutoVariacao $variacao) => collect($variacao->atributos ?? []))
            ->map(function ($atributo) {
                $nome = trim((string) ($atributo->atributo_label ?? $atributo->atributo ?? ''));
                $valor = trim((string) ($atributo->valor ?? ''));

                return trim($nome . ($nome !== '' && $valor !== '' ? ': ' : '') . $valor);
            })
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $disponivel = $variacoes->sum(fn (ProdutoVariacao $variacao) => $this->quantidadeDisponivel($variacao)) > 0;
        return [
            'nome' => $produto?->nome ?? $variacaoPrincipal->nome ?? 'Produto',
            'categoria_nome' => $produto?->categoria?->nome,
            'referencia' => $variacaoPrincipal->sku_interno
                ?: $variacaoPrincipal->referencia
                ?: $variacaoPrincipal->chave_variacao
                ?: ('#' . $variacaoPrincipal->id),
            'altura' => $variacaoPrincipal->dimensao_3 ?? $produto?->altura,
            'largura' => $variacaoPrincipal->dimensao_1 ?? $produto?->largura,
            'profundidade' => $variacaoPrincipal->dimensao_2 ?? $produto?->profundidade,
            'atributos' => $atributos,
            'preco_label' => $precoLabel,
            'preco_sob_consulta' => $precos->isEmpty(),
            'preco_original_label' => $melhorPreco['preco'] !== null
                && !empty($melhorPreco['preco_original'])
                && (float) $melhorPreco['preco_original'] > (float) $melhorPreco['preco']
                    ? $this->formatMoney((float) $melhorPreco['preco_original'])
                    : null,
            'percentual_desconto' => (float) $melhorPreco['percentual_desconto'],
            'pagamento_label' => $melhorPreco['pagamento_label'],
            'is_outlet' => (bool) $melhorPreco['outlet'],
            'disponivel' => $disponivel,
            'imagem_src' => $this->pdfImage->fromProdutoVariacaoProdutoFirstForCatalogOrPlaceholder($variacaoPrincipal),
        ];
    }

    private function quantidadeDisponivel(ProdutoVariacao $variacao): int
    {
        if (!$variacao->relationLoaded('estoques')) {
            return 0;
        }

        return (int) $variacao->estoques->sum(function ($estoque) {
            $fisico = (int) ($estoque->quantidade ?? 0);
            $reservado = method_exists($estoque, 'quantidadeReservadaAberta')
                ? (int) $estoque->quantidadeReservadaAberta()
                : 0;

            return max(0, $fisico - $reservado);
        });
    }

    private function formatMoney(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }
}
