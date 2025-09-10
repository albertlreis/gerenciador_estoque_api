<?php

namespace App\Services;

use App\Models\ProdutoVariacaoAtributo;

/**
 * Serviço de sugestões de atributos e valores para autocomplete.
 */
class ProdutoAtributoService
{
    /**
     * Retorna uma lista (até $limit) de nomes de atributos já usados, normalizados.
     * Filtra por $q (trecho) quando informado.
     *
     * @param string|null $q
     * @param int $limit
     * @return array<string>
     */
    public function sugerirNomes(?string $q = null, int $limit = 20): array
    {
        $query = ProdutoVariacaoAtributo::query()
            ->selectRaw('LOWER(TRIM(atributo)) as nome_norm')
            ->when($q, function ($qb) use ($q) {
                $q = mb_strtolower(trim($q));
                $qb->whereRaw('LOWER(TRIM(atributo)) LIKE ?', ["%{$q}%"]);
            })
            ->groupBy('nome_norm')
            ->orderByRaw('COUNT(*) DESC')
            ->limit($limit);

        return $query->pluck('nome_norm')->all();
    }

    /**
     * Retorna uma lista (até $limit) de valores já usados para um atributo.
     * Pesquisa por $q (trecho) quando informado.
     *
     * @param string $atributoNome
     * @param string|null $q
     * @param int $limit
     * @return array<string>
     */
    public function sugerirValores(string $atributoNome, ?string $q = null, int $limit = 20): array
    {
        $attrNorm = mb_strtolower(trim($atributoNome));

        $query = ProdutoVariacaoAtributo::query()
            ->whereRaw('LOWER(TRIM(atributo)) = ?', [$attrNorm])
            ->selectRaw('TRIM(valor) as valor_norm')
            ->when($q, function ($qb) use ($q) {
                $q = trim($q);
                $qb->whereRaw('TRIM(valor) LIKE ?', ["%{$q}%"]);
            })
            ->groupBy('valor_norm')
            ->orderByRaw('COUNT(*) DESC')
            ->limit($limit);

        return $query->pluck('valor_norm')->all();
    }
}
