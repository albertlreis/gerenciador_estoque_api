<?php

namespace App\Http\Resources;

use App\Models\ProdutoImagem;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProdutoListaResource extends JsonResource
{
    public function toArray($request): array
    {
        $variacoes = $this->getRelationValue('variacoes') ?? collect();
        $imagemPrincipal = $this->imagemPrincipal?->url ?? $this->imagemPrincipal?->url_completa;

        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'id_categoria' => $this->id_categoria,
            'id_fornecedor' => $this->id_fornecedor,
            'altura' => $this->altura,
            'largura' => $this->largura,
            'profundidade' => $this->profundidade,
            'peso' => $this->peso,
            'ativo' => $this->ativo,
            'is_outlet' => $variacoes->contains(fn ($v) => (int) ($v->outlet_restante_total ?? 0) > 0),
            'imagem_principal' => $this->normalizarUrlImagem($imagemPrincipal),
            'variacoes' => $this->whenLoaded('variacoes', function () {
                return $this->variacoes->map(function ($v) {
                    return [
                        'id' => $v->id,
                        'produto_id' => $v->produto_id,
                        'referencia' => $v->referencia,
                        'preco' => (float) ($v->preco ?? 0),
                        'estoque_total' => (int) ($v->estoque_total ?? 0),
                        'outlet_restante_total' => (int) ($v->outlet_restante_total ?? 0),
                        'imagem_url' => $v->imagem_url,
                    ];
                })->values();
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
}
