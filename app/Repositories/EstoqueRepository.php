<?php

namespace App\Repositories;

use App\DTOs\FiltroEstoqueDTO;
use App\Models\ProdutoVariacao;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
        $subqueryDataEntrada = DB::table('estoque as e')
            ->select('e.data_entrada_estoque_atual')
            ->whereColumn('e.id_variacao', 'produto_variacoes.id');

        $subqueryUltimaVenda = DB::table('estoque as e')
            ->select('e.ultima_venda_em')
            ->whereColumn('e.id_variacao', 'produto_variacoes.id');

        $subqueryDiasSemVenda = DB::table('estoque as e')
            ->selectRaw('DATEDIFF(CURDATE(), DATE(e.ultima_venda_em))')
            ->whereColumn('e.id_variacao', 'produto_variacoes.id')
            ->whereNotNull('e.ultima_venda_em');

        if ($filtros->deposito) {
            $subqueryDataEntrada->where('e.id_deposito', $filtros->deposito);
            $subqueryUltimaVenda->where('e.id_deposito', $filtros->deposito);
            $subqueryDiasSemVenda->where('e.id_deposito', $filtros->deposito);
        } else {
            $subqueryDataEntrada->where('e.quantidade', '>', 0);
            $subqueryUltimaVenda->where('e.quantidade', '>', 0);
            $subqueryDiasSemVenda->where('e.quantidade', '>', 0);
        }

        $subqueryDataEntrada
            ->orderByDesc('e.quantidade')
            ->orderBy('e.id_deposito')
            ->limit(1);

        $subqueryUltimaVenda
            ->orderByDesc('e.quantidade')
            ->orderBy('e.id_deposito')
            ->limit(1);

        $subqueryDiasSemVenda
            ->orderByDesc('e.ultima_venda_em')
            ->limit(1);

        $query = ProdutoVariacao::query()
            ->select('produto_variacoes.*')
            ->selectSub($subqueryDataEntrada, 'data_entrada_estoque_atual')
            ->selectSub($subqueryUltimaVenda, 'ultima_venda_em')
            ->selectSub($subqueryDiasSemVenda, 'dias_sem_venda')
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
            $termLikeAny = '%' . $this->escapeLike($term) . '%';
            $termLikePrefix = $this->escapeLike($term) . '%';

            $query->where(function (Builder $q) use ($term, $termLikeAny, $termLikePrefix) {
                if (mb_strlen($term) >= 3) {
                    $boolean = $this->toBooleanFullText($term);

                    $q->whereHas('produto', function (Builder $sub) use ($boolean, $termLikeAny) {
                        $sub->where(function (Builder $produtoQuery) use ($boolean, $termLikeAny) {
                            $produtoQuery->whereRaw('MATCH(nome) AGAINST (? IN BOOLEAN MODE)', [$boolean])
                                ->orWhereRaw("codigo_produto LIKE ? ESCAPE '\\\\'", [$termLikeAny]);
                        });
                    });

                    $q->orWhereRaw(
                        'MATCH(produto_variacoes.referencia, produto_variacoes.nome) AGAINST (? IN BOOLEAN MODE)',
                        [$boolean]
                    );
                }

                if ($this->looksLikeReference($term)) {
                    $q->orWhere(function (Builder $variationQuery) use ($termLikePrefix) {
                        $variationQuery->whereRaw("produto_variacoes.sku_interno LIKE ? ESCAPE '\\\\'", [$termLikePrefix])
                            ->orWhereRaw("produto_variacoes.referencia LIKE ? ESCAPE '\\\\'", [$termLikePrefix])
                            ->orWhereRaw("produto_variacoes.chave_variacao LIKE ? ESCAPE '\\\\'", [$termLikePrefix])
                            ->orWhereRaw("produto_variacoes.codigo_barras LIKE ? ESCAPE '\\\\'", [$termLikePrefix]);
                    });
                }

                $q->orWhereHas('produto', function (Builder $sub) use ($termLikeAny) {
                    $sub->whereRaw("nome LIKE ? ESCAPE '\\\\'", [$termLikeAny])
                        ->orWhereRaw("codigo_produto LIKE ? ESCAPE '\\\\'", [$termLikeAny]);
                })->orWhere(function (Builder $variationQuery) use ($termLikeAny) {
                    $variationQuery->whereRaw("produto_variacoes.sku_interno LIKE ? ESCAPE '\\\\'", [$termLikeAny])
                        ->orWhereRaw("produto_variacoes.referencia LIKE ? ESCAPE '\\\\'", [$termLikeAny])
                        ->orWhereRaw("produto_variacoes.chave_variacao LIKE ? ESCAPE '\\\\'", [$termLikeAny])
                        ->orWhereRaw("produto_variacoes.codigo_barras LIKE ? ESCAPE '\\\\'", [$termLikeAny])
                        ->orWhereRaw("produto_variacoes.nome LIKE ? ESCAPE '\\\\'", [$termLikeAny]);
                })->orWhereHas('codigosHistoricos', function (Builder $sub) use ($termLikeAny) {
                    $sub->whereRaw("codigo LIKE ? ESCAPE '\\\\'", [$termLikeAny])
                        ->orWhereRaw("codigo_origem LIKE ? ESCAPE '\\\\'", [$termLikeAny])
                        ->orWhereRaw("codigo_modelo LIKE ? ESCAPE '\\\\'", [$termLikeAny]);
                });
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

        if ($filtros->comEstoque) {
            $query->havingRaw('quantidade_estoque > ?', [0]);
        } elseif ($filtros->zerados) {
            $query->havingRaw('(quantidade_estoque IS NULL OR quantidade_estoque = ?)', [0]);
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

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
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
