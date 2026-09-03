<?php

namespace App\Services;

use App\Models\ProdutoVariacaoOutlet;
use App\Support\ProductIdentifierSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class OutletItensQueryService
{
    public function __construct(private readonly OutletCatalogoPricingService $pricing)
    {
    }

    public function query(Request|array $filters): Builder
    {
        $input = $filters instanceof Request ? $filters->all() : $filters;
        $categorias = array_values(array_filter((array) ($input['id_categoria'] ?? $input['categoria_id'] ?? [])));
        $busca = trim((string) ($input['q'] ?? $input['busca'] ?? ''));
        $outletIds = array_values(array_filter((array) ($input['outlet_ids'] ?? [])));

        return ProdutoVariacaoOutlet::query()
            ->where('quantidade_restante', '>', 0)
            ->when($outletIds !== [], fn (Builder $query) => $query->whereIn('produto_variacao_outlets.id', $outletIds))
            ->with([
                'motivo:id,slug,nome',
                'imagemSelecionada',
                'formasPagamento.formaPagamento',
                'variacao:id,produto_id,referencia,sku_interno,nome,preco,custo',
                'variacao.atributos',
                'variacao.imagem',
                'variacao.imagens',
                'variacao.produto:id,id_categoria,nome,codigo_produto,altura,largura,profundidade',
                'variacao.produto.imagemPrincipal',
                'variacao.produto.categoria:id,nome',
            ])
            ->when($categorias !== [], fn (Builder $query) => $query->whereHas(
                'variacao.produto',
                fn (Builder $produto) => $produto->whereIn('id_categoria', $categorias)
            ))
            ->when($busca !== '', function (Builder $query) use ($busca) {
                $like = '%' . addcslashes(mb_strtolower($busca), '%_\\') . '%';
                $query->whereHas('variacao', function (Builder $variacao) use ($busca, $like) {
                    $variacao->whereRaw('LOWER(referencia) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(sku_interno) LIKE ?', [$like])
                        ->orWhereHas('produto', function (Builder $produto) use ($like) {
                            $produto->whereRaw('LOWER(nome) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(codigo_produto) LIKE ?', [$like]);
                        });
                    ProductIdentifierSearch::whereAny($variacao, [
                        'produto_variacoes.referencia',
                        'produto_variacoes.sku_interno',
                        'produto_variacoes.chave_variacao',
                        'produto_variacoes.codigo_barras',
                    ], $busca, 'or');
                    $variacao->orWhereHas('produto', function (Builder $produto) use ($busca) {
                        ProductIdentifierSearch::whereAny($produto, ['produtos.codigo_produto'], $busca);
                    });
                    $variacao->orWhereHas('codigosHistoricos', function (Builder $codigo) use ($busca) {
                        ProductIdentifierSearch::whereAny($codigo, [
                            'codigo',
                            'codigo_origem',
                            'codigo_modelo',
                        ], $busca);
                    });
                });
            })
            ->orderBy('id');
    }

    public function paginate(Request $request): LengthAwarePaginator
    {
        return $this->query($request)->paginate(
            min(100, max(1, (int) $request->input('per_page', 10)))
        );
    }

    public function serialize(ProdutoVariacaoOutlet $outlet): array
    {
        $pricing = $this->pricing->buildOutlet($outlet);
        $variacao = $outlet->variacao;
        $produto = $variacao?->produto;

        return [
            'outlet_id' => (int) $outlet->id,
            'produto_id' => (int) $produto?->id,
            'produto_nome' => $produto?->nome,
            'codigo_produto' => $produto?->codigo_produto,
            'categoria' => $produto?->categoria ? [
                'id' => (int) $produto->categoria->id,
                'nome' => $produto->categoria->nome,
            ] : null,
            'variacao' => [
                'id' => (int) $variacao?->id,
                'nome' => $variacao?->nome,
                'referencia' => $variacao?->referencia,
                'sku_interno' => $variacao?->sku_interno,
            ],
            'motivo' => $outlet->motivo ? [
                'id' => (int) $outlet->motivo->id,
                'slug' => $outlet->motivo->slug,
                'nome' => $outlet->motivo->nome,
            ] : null,
            'quantidade' => (int) $outlet->quantidade,
            'quantidade_restante' => (int) $outlet->quantidade_restante,
            'produto_variacao_imagem_id' => $outlet->produto_variacao_imagem_id,
            'preco_base' => $pricing['preco_base'],
            'melhor_condicao' => $pricing['melhor_condicao'],
            'condicoes' => $pricing['condicoes'],
        ];
    }
}
