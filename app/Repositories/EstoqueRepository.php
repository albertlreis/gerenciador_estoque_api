<?php

namespace App\Repositories;

use App\DTOs\FiltroEstoqueDTO;
use App\Models\ProdutoVariacao;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Repositório de consultas do estoque.
 */
class EstoqueRepository
{
    /**
     * Monta a query base (ProdutoVariacao) com filtros e agregações.
     *
     * Retorna variações com:
     * - produto
     * - atributos
     * - quantidade_estoque (withSum)
     *
     * @param FiltroEstoqueDTO $filtros
     * @return Builder<ProdutoVariacao>
     */
    public function queryBase(FiltroEstoqueDTO $filtros): Builder
    {
        $query = ProdutoVariacao::query()
            ->whereHas('produto', fn ($q) => $q->where('ativo', 1))
            ->with(['produto.categoria', 'produto.fornecedor', 'atributos'])
            ->withSum(
                ['estoques as quantidade_estoque' => function ($q) use ($filtros) {
                    if ($filtros->deposito) {
                        $q->where('id_deposito', $filtros->deposito);
                    }
                }],
                'quantidade'
            );

        if ($filtros->categoria) {
            $query->whereHas('produto', fn ($q) => $q->where('id_categoria', $filtros->categoria));
        }

        if ($filtros->fornecedor) {
            $query->whereHas('produto', fn ($q) => $q->where('id_fornecedor', $filtros->fornecedor));
        }

        if ($filtros->produto) {
            $term = trim($filtros->produto);

            $query->where(function (Builder $q) use ($term) {
                // 1) FULLTEXT (quando termo for "minimamente" útil)
                // MySQL FULLTEXT ignora termos muito curtos dependendo da config, então evitamos forçar.
                if (mb_strlen($term) >= 3) {
                    $boolean = $this->toBooleanFullText($term);

                    $q->whereHas('produto', function ($sub) use ($boolean) {
                        // MATCH em produtos.nome
                        $sub->whereRaw('MATCH(nome) AGAINST (? IN BOOLEAN MODE)', [$boolean]);
                    });

                    // OU MATCH em produto_variacoes (referencia e nome)
                    $q->orWhereRaw(
                        'MATCH(produto_variacoes.referencia, produto_variacoes.nome) AGAINST (? IN BOOLEAN MODE)',
                        [$boolean]
                    );

                    // 2) Reforço para referência "prefixo" quando parece referência
                    if ($this->looksLikeReference($term)) {
                        $q->orWhere('produto_variacoes.referencia', 'like', $term . '%');
                    }

                    // 3) Fallback LIKE (garante "qualquer pedaço" mesmo se FT falhar)
                    $q->orWhereHas('produto', fn ($sub) => $sub->where('nome', 'like', "%{$term}%"))
                        ->orWhere('produto_variacoes.referencia', 'like', "%{$term}%");
                } else {
                    // Termo muito curto: vai só de LIKE + prefixo (se for referência)
                    if ($this->looksLikeReference($term)) {
                        $q->where('produto_variacoes.referencia', 'like', $term . '%');
                    }

                    $q->orWhereHas('produto', fn ($sub) => $sub->where('nome', 'like', "%{$term}%"))
                        ->orWhere('produto_variacoes.referencia', 'like', "%{$term}%");
                }
            });
        }

        // Filtros de movimentação aplicáveis ao estoque atual:
        // - periodo: limita pela data da movimentação
        // - tipo: limita pelo tipo da movimentação
        $hasPeriodo = $filtros->periodo && count($filtros->periodo) === 2;
        $hasTipo = !empty($filtros->tipo);

        if ($hasPeriodo || $hasTipo) {
            $inicio = null;
            $final = null;

            if ($hasPeriodo) {
                [$ini, $fim] = $filtros->periodo;
                $inicio = $ini ? Carbon::parse($ini)->startOfDay() : null;
                $final = $fim ? Carbon::parse($fim)->endOfDay() : null;
            }

            $query->whereExists(function ($sub) use ($inicio, $final, $hasPeriodo, $filtros) {
                $sub->selectRaw('1')
                    ->from('estoque_movimentacoes as em')
                    ->whereColumn('em.id_variacao', 'produto_variacoes.id');

                if ($hasPeriodo && $inicio && $final) {
                    $sub->whereBetween('em.data_movimentacao', [$inicio, $final]);
                }

                if (!empty($filtros->tipo)) {
                    $sub->where('em.tipo', $filtros->tipo);
                }

                if ($filtros->deposito) {
                    $deposito = (int) $filtros->deposito;
                    $sub->where(function ($q) use ($deposito) {
                        $q->where('em.id_deposito_origem', $deposito)
                            ->orWhere('em.id_deposito_destino', $deposito);
                    });
                }
            });
        }

        if ($filtros->deposito) {
            if ($filtros->zerados) {
                $query->havingRaw('(quantidade_estoque IS NULL OR quantidade_estoque = ?)', [0]);
            } else {
                $query->havingRaw('quantidade_estoque > ?', [0]);
            }
        } else {
            if ($filtros->zerados) {
                $query->havingRaw('(quantidade_estoque IS NULL OR quantidade_estoque = ?)', [0]);
            }
        }

        return $query;
    }

    /**
     * Converte um termo livre em uma query FULLTEXT BOOLEAN MODE:
     * - Divide por espaços
     * - Mantém palavras com "prefix wildcard" (*)
     * - Ex: "mesa madeira" => "+mesa* +madeira*"
     *
     * @param string $term
     * @return string
     */
    private function toBooleanFullText(string $term): string
    {
        $term = preg_replace('/\s+/', ' ', trim($term)) ?? trim($term);
        if ($term === '') return '';

        $parts = array_filter(explode(' ', $term), fn ($p) => $p !== '');

        // + exige que o termo exista; * permite prefixo
        // Evita caracteres que quebram boolean mode
        $safeParts = array_map(function ($p) {
            $p = preg_replace('/[^\p{L}\p{N}_-]/u', '', $p) ?? $p; // letras/números/_/-
            $p = trim($p);
            if ($p === '') return null;
            return '+' . $p . '*';
        }, $parts);

        $safeParts = array_values(array_filter($safeParts));

        return implode(' ', $safeParts);
    }

    /**
     * Heurística simples: identifica se o termo "parece" uma referência:
     * - Sem espaços
     * - Tem dígito OU mistura letras/dígitos
     * - Curto/médio (ex.: 2..40)
     *
     * @param string $term
     * @return bool
     */
    private function looksLikeReference(string $term): bool
    {
        $t = trim($term);
        if ($t === '' || str_contains($t, ' ')) return false;
        $len = mb_strlen($t);
        if ($len < 2 || $len > 40) return false;

        // tem pelo menos um número ou tem hífen/barra comum em referência
        return (bool) preg_match('/\d|[-\/_]/', $t);
    }

    /**
     * Aplica eager loading pesado apenas quando necessário.
     *
     * Observação: prefira centralizar eager loading aqui (não no Model),
     * para evitar relações carregarem coisas "sem querer" em outros lugares.
     *
     * @param Builder<ProdutoVariacao> $query
     * @param FiltroEstoqueDTO $filtros
     * @return Builder<ProdutoVariacao>
     */
    public function aplicarRelacoesDeEstoque(Builder $query, FiltroEstoqueDTO $filtros): Builder
    {
        if ($filtros->zerados) {
            return $query;
        }

        return $query->with([
            'estoquesComLocalizacao' => function ($q) use ($filtros) {
                // Se o filtro for por depósito, alinhe a "linha" ao mesmo depósito do withSum
                if ($filtros->deposito) {
                    $q->where('id_deposito', $filtros->deposito);
                } else {
                    // Sem filtro de depósito: traga apenas estoques positivos,
                    // para não "grudar" no primeiro registro zerado de outro depósito
                    $q->where('quantidade', '>', 0);
                }

                // Deixa determinístico qual vem primeiro (o Resource pega o first())
                $q->orderByDesc('quantidade')
                    ->orderBy('id_deposito');

                // Eager load do que a tela precisa
                $q->with([
                    'deposito',
                    'localizacao.area',
                    'localizacao.valores.dimensao',
                ]);
            },
        ]);
    }
}
