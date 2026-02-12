<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProdutoResource extends JsonResource
{
    public function toArray($request): array
    {
        $variacoes = $this->getRelationValue('variacoes') ?? collect();
        $outletCatalogo = $this->buildOutletCatalogoData($variacoes);

        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'descricao' => $this->descricao,
            'id_categoria' => $this->id_categoria,
            'id_fornecedor' => $this->id_fornecedor,
            'altura' => $this->altura,
            'largura' => $this->largura,
            'profundidade' => $this->profundidade,
            'peso' => $this->peso,
            'categoria' => $this->whenLoaded('categoria'),
            'ativo' => $this->ativo,
            'motivo_desativacao' => $this->motivo_desativacao,
            'estoque_minimo' => $this->estoque_minimo,
            'is_outlet' => $variacoes->contains(fn ($v) =>
                $v->relationLoaded('outlet') && $v->outlet?->quantidade_restante > 0
            ),
            'estoque_total' => $this->getEstoqueTotalAttributeSafely(),
            'estoque_resumo' => $this->getEstoqueResumoAttributeSafely(),
            'estoque_outlet_total' => $this->getEstoqueOutletTotalAttributeSafely(),
            'depositos_disponiveis' => $this->getDepositosDisponiveisAttributeSafely(),
            // Campos novos e opcionais para o catalogo outlet (retrocompativel)
            'preco_venda' => $outletCatalogo['preco_venda'],
            'preco_outlet' => $outletCatalogo['preco_outlet'],
            'preco_final_venda' => $outletCatalogo['preco_final_venda'],
            'percentual_desconto' => $outletCatalogo['percentual_desconto'],
            'pagamento_label' => $outletCatalogo['pagamento_label'],
            'pagamento_detalhes' => $outletCatalogo['pagamento_detalhes'],
            'pagamento_condicoes' => $outletCatalogo['pagamento_condicoes'],
            'outlet_catalogo' => $outletCatalogo,
            'imagem_principal' => $this->imagemPrincipal?->url_completa,
            'data_ultima_saida' => $this->data_ultima_saida,
            'manual_conservacao' => $this->manual_conservacao
                ? Storage::url($this->manual_conservacao)
                : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'fornecedor' => $this->fornecedor,
            'variacoes' => ProdutoVariacaoResource::collection($this->whenLoaded('variacoes')),
            'imagens' => $this->imagens->map(function ($imagem) {
                return [
                    'id' => $imagem->id,
                    'url' => $imagem->url,
                    'url_completa' => $imagem->url_completa,
                    'principal' => $imagem->principal,
                ];
            }),
        ];
    }

    private function getEstoqueTotalAttributeSafely(): int
    {
        $variacoes = $this->getRelationValue('variacoes') ?? collect();

        return (int) $variacoes->sum(function ($v) {
            $estoques = $v->getRelationValue('estoques');
            if ($estoques instanceof \Illuminate\Support\Collection) {
                return (float) $estoques->sum('quantidade');
            }

            // fallback caso exista legado "estoque" (singular)
            $estoque = $v->getRelationValue('estoque');
            return (float) ($estoque?->quantidade ?? 0);
        });
    }

    private function getEstoqueOutletTotalAttributeSafely(): int
    {
        $variacoes = $this->getRelationValue('variacoes') ?? collect();
        return $variacoes->sum(fn($v) => $v->getRelationValue('outlets')?->sum('quantidade') ?? 0);
    }

    private function getDepositosDisponiveisAttributeSafely(): array
    {
        $variacoes = $this->getRelationValue('variacoes') ?? collect();
        if (!$variacoes instanceof \Illuminate\Support\Collection || $variacoes->isEmpty()) {
            return [];
        }

        $mapa = [];

        foreach ($variacoes as $variacao) {
            if (!$variacao->relationLoaded('estoques')) {
                continue;
            }
            foreach ($variacao->estoques as $estoque) {
                $quantidade = (float) ($estoque->quantidade ?? 0);
                if ($quantidade <= 0) {
                    continue;
                }

                $depositoId = $estoque->deposito_id ?? $estoque->id_deposito ?? null;
                $depositoNome = null;
                if ($estoque->relationLoaded('deposito')) {
                    $depositoNome = $estoque->deposito?->nome;
                }

                $key = $depositoId ?: ($depositoNome ?? spl_object_id($estoque));
                if (!isset($mapa[$key])) {
                    $mapa[$key] = [
                        'deposito_id' => $depositoId,
                        'nome' => $depositoNome,
                        'saldo' => 0.0,
                    ];
                }
                $mapa[$key]['saldo'] += $quantidade;
            }
        }

        return collect($mapa)
            ->sortBy(fn ($d) => $d['nome'] ?? '')
            ->values()
            ->all();
    }

    private function getEstoqueResumoAttributeSafely(): array
    {
        $variacoes = $this->getRelationValue('variacoes') ?? collect();

        if (!$variacoes instanceof \Illuminate\Support\Collection || $variacoes->isEmpty()) {
            return [
                'total_variacoes' => 0,
                'variacoes_com_estoque' => 0,
                'variacoes_sem_estoque' => 0,
                'total_disponivel' => 0,
                'max_disponivel' => 0,
            ];
        }

        $quantidades = $variacoes->map(fn ($variacao) => $this->getQuantidadeVariacaoSafely($variacao));
        $comEstoque = $quantidades->filter(fn ($qtd) => $qtd > 0)->count();
        $semEstoque = $quantidades->filter(fn ($qtd) => $qtd <= 0)->count();
        $totalDisponivel = (int) $quantidades->filter(fn ($qtd) => $qtd > 0)->sum();
        $maxDisponivel = (int) $quantidades->max();

        return [
            'total_variacoes' => (int) $variacoes->count(),
            'variacoes_com_estoque' => (int) $comEstoque,
            'variacoes_sem_estoque' => (int) $semEstoque,
            'total_disponivel' => max(0, $totalDisponivel),
            'max_disponivel' => max(0, $maxDisponivel),
        ];
    }

    private function getQuantidadeVariacaoSafely($variacao): int
    {
        $estoques = $variacao->getRelationValue('estoques');
        if ($estoques instanceof \Illuminate\Support\Collection) {
            return (int) $estoques->sum('quantidade');
        }

        $estoque = $variacao->getRelationValue('estoque');
        return (int) ($estoque?->quantidade ?? 0);
    }

    private function buildOutletCatalogoData($variacoes): array
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

        if (!$variacoes instanceof \Illuminate\Support\Collection || $variacoes->isEmpty()) {
            return $default;
        }

        $ofertas = $variacoes
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
