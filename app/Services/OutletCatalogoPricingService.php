<?php

namespace App\Services;

use Illuminate\Support\Collection;

class OutletCatalogoPricingService
{
    public function build($variacoes): array
    {
        $default = [
            'preco_venda' => null,
            'preco_outlet' => null,
            'preco_final_venda' => null,
            'percentual_desconto' => 0.0,
            'pagamento_label' => null,
            'pagamento_detalhes' => null,
            'pagamento_condicoes' => [],
        ];

        $colecaoVariacoes = $variacoes instanceof Collection ? $variacoes : collect($variacoes);
        if ($colecaoVariacoes->isEmpty()) {
            return $default;
        }

        $ofertas = $colecaoVariacoes
            ->map(function ($variacao) {
                $precoBase = (float) ($variacao->preco ?? 0);
                if ($precoBase <= 0) {
                    return null;
                }

                $outlets = collect($variacao->getRelationValue('outlets') ?? [])
                    ->filter(fn ($outlet) => (int) ($outlet->quantidade_restante ?? 0) > 0)
                    ->values();

                $formas = $outlets
                    ->flatMap(fn ($outlet) => collect($outlet->getRelationValue('formasPagamento') ?? []))
                    ->filter()
                    ->values();

                $desconto = (float) ($formas->max(fn ($forma) => (float) ($forma->percentual_desconto ?? 0)) ?? 0);
                $desconto = max(0, min(100, $desconto));
                $precoFinal = round($precoBase * (1 - ($desconto / 100)), 2);

                $condicoes = $formas
                    ->map(function ($forma) {
                        $formaModel = $forma->getRelationValue('formaPagamento');
                        $nomeForma = $formaModel?->nome;
                        $parcelasMax = $forma->max_parcelas ?? $formaModel?->max_parcelas_default;

                        return [
                            'forma_pagamento_id' => (int) ($forma->forma_pagamento_id ?? $formaModel?->id ?? 0),
                            'forma_pagamento' => $nomeForma,
                            'percentual_desconto' => (float) ($forma->percentual_desconto ?? 0),
                            'max_parcelas' => $parcelasMax ? (int) $parcelasMax : null,
                        ];
                    })
                    ->filter(fn ($item) => !empty($item['forma_pagamento']))
                    ->unique(fn ($item) => implode('|', [
                        $item['forma_pagamento_id'],
                        $item['percentual_desconto'],
                        $item['max_parcelas'] ?? '',
                    ]))
                    ->values();

                return [
                    'preco_venda' => round($precoBase, 2),
                    'preco_final_venda' => $precoFinal,
                    'preco_outlet' => $desconto > 0 ? $precoFinal : null,
                    'percentual_desconto' => $desconto,
                    'pagamento_condicoes' => $condicoes->all(),
                ];
            })
            ->filter()
            ->values();

        if ($ofertas->isEmpty()) {
            return $default;
        }

        $melhorOferta = $ofertas
            ->sortBy([
                ['preco_final_venda', 'asc'],
                ['percentual_desconto', 'desc'],
            ])
            ->first();

        $formasNomes = collect($melhorOferta['pagamento_condicoes'] ?? [])
            ->pluck('forma_pagamento')
            ->filter()
            ->unique()
            ->values();

        $parcelasMax = collect($melhorOferta['pagamento_condicoes'] ?? [])
            ->pluck('max_parcelas')
            ->filter(fn ($valor) => !is_null($valor))
            ->max();

        $pagamentoLabel = null;
        if ($formasNomes->isNotEmpty()) {
            $pagamentoLabel = $formasNomes->join(', ');
            if ($parcelasMax && (int) $parcelasMax > 1) {
                $pagamentoLabel .= " (ate {$parcelasMax}x)";
            }
        }

        $pagamentoDetalhes = null;
        if ($pagamentoLabel) {
            $pagamentoDetalhes = $melhorOferta['percentual_desconto'] > 0
                ? "Desconto de ate {$melhorOferta['percentual_desconto']}% conforme forma de pagamento."
                : 'Consulte condicoes de pagamento para este outlet.';
        }

        return [
            'preco_venda' => $melhorOferta['preco_venda'],
            'preco_outlet' => $melhorOferta['preco_outlet'],
            'preco_final_venda' => $melhorOferta['preco_final_venda'],
            'percentual_desconto' => $melhorOferta['percentual_desconto'],
            'pagamento_label' => $pagamentoLabel,
            'pagamento_detalhes' => $pagamentoDetalhes,
            'pagamento_condicoes' => $melhorOferta['pagamento_condicoes'] ?? [],
        ];
    }
}
