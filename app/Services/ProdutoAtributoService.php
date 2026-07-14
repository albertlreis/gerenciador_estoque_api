<?php

namespace App\Services;

use App\Helpers\StringHelper;
use App\Models\ProdutoVariacaoAtributo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Serviço de sugestões de atributos e valores para autocomplete.
 */
class ProdutoAtributoService
{
    /**
     * Retorna uma lista (até $limit) de nomes de atributos já usados, normalizados.
     * Filtra por $q (trecho) quando informado.
     *
     * @return array<string>
     */
    public function sugerirNomes(?string $q = null, int $limit = 20): array
    {
        if ($limit <= 0) {
            return [];
        }

        $filtroNormalizado = StringHelper::normalizarAtributo((string) $q);
        $nomes = $this->nomesComFrequencia();

        if ($filtroNormalizado !== '') {
            $nomes = $nomes->filter(
                fn (array $item): bool => str_contains($item['nome_normalizado'], $filtroNormalizado)
            );
        }

        return $nomes
            ->groupBy('nome_normalizado')
            ->map(fn (Collection $itens, string $nome): array => [
                'nome' => $nome,
                'ocorrencias' => $itens->sum('ocorrencias'),
            ])
            ->sort(function (array $a, array $b): int {
                $porFrequencia = $b['ocorrencias'] <=> $a['ocorrencias'];

                return $porFrequencia !== 0
                    ? $porFrequencia
                    : strcmp($a['nome'], $b['nome']);
            })
            ->take($limit)
            ->pluck('nome')
            ->values()
            ->all();
    }

    /**
     * Retorna uma lista (até $limit) de valores já usados para um atributo.
     * Pesquisa por $q (trecho) quando informado.
     *
     * @return array<string>
     */
    public function sugerirValores(string $atributoNome, ?string $q = null, int $limit = 20): array
    {
        if ($limit <= 0) {
            return [];
        }

        $atributoNormalizado = StringHelper::normalizarAtributo($atributoNome);
        if ($atributoNormalizado === '') {
            return [];
        }

        $nomesEquivalentes = $this->nomesComFrequencia()
            ->where('nome_normalizado', $atributoNormalizado)
            ->pluck('nome_original')
            ->values()
            ->all();

        if ($nomesEquivalentes === []) {
            return [];
        }

        $filtroNormalizado = StringHelper::normalizarAtributo((string) $q);
        $valores = ProdutoVariacaoAtributo::query()
            ->whereIn(DB::raw('TRIM(atributo)'), $nomesEquivalentes)
            ->whereNotNull('valor')
            ->whereRaw("TRIM(valor) <> ''")
            ->selectRaw('TRIM(valor) as valor_original, COUNT(*) as ocorrencias')
            ->groupByRaw('TRIM(valor)')
            ->get()
            ->map(fn (ProdutoVariacaoAtributo $item): array => [
                'valor_original' => (string) $item->getAttribute('valor_original'),
                'valor_normalizado' => StringHelper::normalizarAtributo(
                    (string) $item->getAttribute('valor_original')
                ),
                'ocorrencias' => (int) $item->getAttribute('ocorrencias'),
            ])
            ->filter(fn (array $item): bool => $item['valor_normalizado'] !== '');

        if ($filtroNormalizado !== '') {
            $valores = $valores->filter(
                fn (array $item): bool => str_contains($item['valor_normalizado'], $filtroNormalizado)
            );
        }

        return $valores
            ->groupBy('valor_normalizado')
            ->map(function (Collection $itens, string $valorNormalizado): array {
                $representante = $itens
                    ->sort(function (array $a, array $b): int {
                        $porFrequencia = $b['ocorrencias'] <=> $a['ocorrencias'];

                        return $porFrequencia !== 0
                            ? $porFrequencia
                            : strcmp($a['valor_original'], $b['valor_original']);
                    })
                    ->first();

                return [
                    'valor' => $representante['valor_original'],
                    'valor_normalizado' => $valorNormalizado,
                    'ocorrencias' => $itens->sum('ocorrencias'),
                ];
            })
            ->sort(function (array $a, array $b): int {
                $porFrequencia = $b['ocorrencias'] <=> $a['ocorrencias'];

                return $porFrequencia !== 0
                    ? $porFrequencia
                    : strcmp($a['valor_normalizado'], $b['valor_normalizado']);
            })
            ->take($limit)
            ->pluck('valor')
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array{nome_original: string, nome_normalizado: string, ocorrencias: int}>
     */
    private function nomesComFrequencia(): Collection
    {
        return ProdutoVariacaoAtributo::query()
            ->whereNotNull('atributo')
            ->whereRaw("TRIM(atributo) <> ''")
            ->selectRaw('TRIM(atributo) as nome_original, COUNT(*) as ocorrencias')
            ->groupByRaw('TRIM(atributo)')
            ->get()
            ->map(fn (ProdutoVariacaoAtributo $item): array => [
                'nome_original' => (string) $item->getAttribute('nome_original'),
                'nome_normalizado' => StringHelper::normalizarAtributo(
                    (string) $item->getAttribute('nome_original')
                ),
                'ocorrencias' => (int) $item->getAttribute('ocorrencias'),
            ])
            ->filter(fn (array $item): bool => $item['nome_normalizado'] !== '')
            ->values();
    }
}
