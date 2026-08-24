<?php

namespace App\Services;

use Illuminate\Support\Collection;

class OutletCatalogoPricingService
{
    /**
     * Serializa a precificacao de um unico registro outlet. Esta e a fonte
     * canonica usada pela listagem, carrinho, pedido e exportacoes.
     */
    public function buildOutlet(object $outlet): array
    {
        $variacao = $outlet->getRelationValue('variacao') ?? $outlet->variacao;
        $precoBase = round((float) ($variacao?->preco ?? 0), 2);

        $condicoes = collect($outlet->getRelationValue('formasPagamento') ?? $outlet->formasPagamento ?? [])
            ->filter(fn ($condicao) => (bool) ($condicao->formaPagamento?->ativo ?? false))
            ->map(function ($condicao) use ($outlet, $precoBase) {
                $forma = $condicao->formaPagamento;
                $desconto = round(max(0, min(100, (float) $condicao->percentual_desconto)), 2);
                $parcelas = $condicao->max_parcelas ?? $forma?->max_parcelas_default;

                return [
                    'outlet_pagamento_id' => (int) $condicao->id,
                    'outlet_id' => (int) $outlet->id,
                    'forma_pagamento_id' => (int) $condicao->forma_pagamento_id,
                    'forma_pagamento' => (string) ($forma?->nome ?? ''),
                    'percentual_desconto' => $desconto,
                    'max_parcelas' => $parcelas ? (int) $parcelas : null,
                    'preco_final' => round($precoBase * (1 - ($desconto / 100)), 2),
                ];
            })
            ->sortBy([
                ['preco_final', 'asc'],
                ['percentual_desconto', 'desc'],
                ['max_parcelas', 'desc'],
                ['outlet_pagamento_id', 'asc'],
            ])
            ->values();

        return [
            'preco_base' => $precoBase,
            'melhor_condicao' => $condicoes->first(),
            'condicoes' => $condicoes->all(),
        ];
    }

    public function buildSnapshot(object $outlet, object $condicao): array
    {
        $pricing = $this->buildOutlet($outlet);
        $selecionada = collect($pricing['condicoes'])
            ->firstWhere('outlet_pagamento_id', (int) $condicao->id);

        if (!$selecionada) {
            throw new \DomainException('A condicao comercial nao esta ativa para este outlet.');
        }

        return [
            'outlet_id' => (int) $outlet->id,
            'outlet_pagamento_id' => (int) $selecionada['outlet_pagamento_id'],
            'outlet_preco_base' => $pricing['preco_base'],
            'outlet_forma_pagamento_id' => (int) $selecionada['forma_pagamento_id'],
            'outlet_forma_pagamento' => $selecionada['forma_pagamento'],
            'outlet_percentual_desconto' => $selecionada['percentual_desconto'],
            'outlet_max_parcelas' => $selecionada['max_parcelas'],
            'outlet_preco_final' => $selecionada['preco_final'],
        ];
    }

    public function build($variacoes): array
    {
        $default = [
            'preco_venda' => null,
            'preco_outlet' => null,
            'preco_final_venda' => null,
            'percentual_desconto' => 0.0,
            'pagamento_label' => null,
            'pagamento_detalhes' => null,
            'condicao_principal' => null,
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

                if ($outlets->isEmpty()) {
                    return null;
                }

                $condicoes = $outlets
                    ->flatMap(function ($outlet) use ($precoBase) {
                        return collect($outlet->getRelationValue('formasPagamento') ?? [])
                            ->map(function ($forma) use ($outlet, $precoBase) {
                        $formaModel = $forma->getRelationValue('formaPagamento');
                        $nomeForma = $formaModel?->nome;
                        $parcelasMax = $forma->max_parcelas ?? $formaModel?->max_parcelas_default;
                        $desconto = max(0, min(100, (float) ($forma->percentual_desconto ?? 0)));

                        return [
                            'outlet_id' => (int) ($outlet->id ?? 0),
                            'outlet_pagamento_id' => (int) ($forma->id ?? 0),
                            'forma_pagamento_id' => (int) ($forma->forma_pagamento_id ?? $formaModel?->id ?? 0),
                            'forma_pagamento' => $nomeForma,
                            'percentual_desconto' => $desconto,
                            'max_parcelas' => $parcelasMax ? (int) $parcelasMax : null,
                            'preco_final_venda' => round($precoBase * (1 - ($desconto / 100)), 2),
                        ];
                            });
                    })
                    ->filter(fn ($item) => !empty($item['forma_pagamento']))
                    ->unique(fn ($item) => implode('|', [
                        $item['outlet_id'],
                        $item['forma_pagamento_id'],
                        $item['percentual_desconto'],
                        $item['max_parcelas'] ?? '',
                    ]))
                    ->values();

                if ($condicoes->isEmpty()) {
                    return null;
                }

                $melhorCondicao = $condicoes
                    ->sortBy([
                        ['preco_final_venda', 'asc'],
                        ['percentual_desconto', 'desc'],
                        ['max_parcelas', 'desc'],
                        ['outlet_pagamento_id', 'asc'],
                    ])
                    ->first();

                return [
                    'preco_venda' => round($precoBase, 2),
                    'preco_final_venda' => $melhorCondicao['preco_final_venda'],
                    'preco_outlet' => $melhorCondicao['percentual_desconto'] > 0
                        ? $melhorCondicao['preco_final_venda']
                        : null,
                    'percentual_desconto' => $melhorCondicao['percentual_desconto'],
                    'condicao_principal' => $melhorCondicao,
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

        $condicaoPrincipal = $melhorOferta['condicao_principal'] ?? null;
        $pagamentoLabel = null;
        if (!empty($condicaoPrincipal['forma_pagamento'])) {
            $pagamentoLabel = $condicaoPrincipal['forma_pagamento'];
            if (!empty($condicaoPrincipal['max_parcelas']) && (int) $condicaoPrincipal['max_parcelas'] > 1) {
                $pagamentoLabel .= " (ate {$condicaoPrincipal['max_parcelas']}x)";
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
            'condicao_principal' => $condicaoPrincipal,
            'pagamento_condicoes' => $melhorOferta['pagamento_condicoes'] ?? [],
        ];
    }
}
