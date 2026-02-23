<?php

namespace App\Http\Resources;

use App\Models\ProdutoImagem;
use App\Services\OutletCatalogoPricingService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProdutoResource extends JsonResource
{
    public function toArray($request): array
    {
        $variacoes = $this->getRelationValue('variacoes') ?? collect();
        $outletCatalogo = app(OutletCatalogoPricingService::class)->build($variacoes);

        $imagemPrincipal = $this->imagemPrincipal?->url ?? $this->imagemPrincipal?->url_completa;
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
            'is_outlet' => $variacoes->contains(function ($v) {
                if ($v->relationLoaded('outlets')) {
                    return $v->outlets->sum('quantidade_restante') > 0;
                }
                if ($v->relationLoaded('outlet')) {
                    return $v->outlet?->quantidade_restante > 0;
                }
                return false;
            }),
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
            'manual_conservacao' => $this->normalizarUrlManual($this->manual_conservacao),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'fornecedor' => $this->fornecedor,
            'variacoes' => ProdutoVariacaoResource::collection($this->whenLoaded('variacoes')),
            'imagens' => $this->imagens->map(function ($imagem) {
                $urlBase = $imagem->url ?? $imagem->url_completa;
                return [
                    'id' => $imagem->id,
                    'url' => $imagem->url,
                    'url_completa' => $this->normalizarUrlImagem($urlBase),
                    'principal' => $imagem->principal,
                ];
            }),
        ];
    }

    private function normalizarUrlImagem(?string $valor): ?string
    {
        if (!$valor) {
            return null;
        }

        $valor = trim($valor);
        if (Str::startsWith($valor, ['http://', 'https://'])) {
            return $valor;
        }

        if (Str::startsWith($valor, ['/storage/', 'storage/', '/uploads/', 'uploads/'])) {
            return $valor[0] === '/' ? $valor : '/' . $valor;
        }

        $valor = ltrim($valor, '/');
        if (Str::startsWith($valor, ProdutoImagem::FOLDER . '/')) {
            return Storage::disk(ProdutoImagem::DISK)->url($valor);
        }

        return Storage::disk(ProdutoImagem::DISK)->url(ProdutoImagem::FOLDER . '/' . $valor);
    }

    private function normalizarUrlManual(?string $valor): ?string
    {
        if (!$valor) {
            return null;
        }

        $valor = trim($valor);
        if (Str::startsWith($valor, ['http://', 'https://'])) {
            return $valor;
        }

        if (Str::startsWith($valor, ['/storage/', 'storage/', '/uploads/', 'uploads/'])) {
            return $valor[0] === '/' ? $valor : '/' . $valor;
        }

        $valor = ltrim($valor, '/');
        if (Str::startsWith($valor, 'manuais/')) {
            return Storage::disk('public')->url($valor);
        }

        return '/uploads/manuais/' . $valor;
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

}
