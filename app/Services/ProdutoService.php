<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProdutoService
{
    public function store(array $data): Produto
    {
        $produto = Produto::create([
            'nome' => $data['nome'],
            'descricao' => $data['descricao'] ?? null,
            'id_categoria' => $data['id_categoria'],
            'fabricante' => $data['fabricante'] ?? null,
            'ativo' => $data['ativo'] ?? true,
        ]);

        foreach ($data['variacoes'] as $var) {
            $variacao = ProdutoVariacao::create([
                'produto_id' => $produto->id,
                'nome' => $var['nome'],
                'preco' => $var['preco'],
                'custo' => $var['custo'],
                'referencia' => $var['referencia'],
                'codigo_barras' => $var['codigo_barras'] ?? null,
            ]);

            foreach ($var['atributos'] ?? [] as $attr) {
                ProdutoVariacaoAtributo::create([
                    'id_variacao' => $variacao->id,
                    'atributo' => $attr['atributo'],
                    'valor' => $attr['valor'],
                ]);
            }
        }

        return $produto->load('variacoes.atributos');
    }

    public function update(Produto $produto, array $data): Produto
    {
        $produto->update([
            'nome' => $data['nome'],
            'descricao' => $data['descricao'] ?? null,
            'id_categoria' => $data['id_categoria'],
            'fabricante' => $data['fabricante'] ?? null,
            'ativo' => $data['ativo'] ?? true,
        ]);

        $idsVariacoes = [];

        foreach ($data['variacoes'] as $var) {
            $variacao = isset($var['id'])
                ? ProdutoVariacao::findOrFail($var['id'])
                : new ProdutoVariacao(['produto_id' => $produto->id]);

            $variacao->fill([
                'produto_id' => $produto->id,
                'nome' => $var['nome'],
                'preco' => $var['preco'],
                'custo' => $var['custo'],
                'referencia' => $var['referencia'],
                'codigo_barras' => $var['codigo_barras'] ?? null,
            ])->save();

            $idsVariacoes[] = $variacao->id;

            $idsAtributos = [];

            foreach ($var['atributos'] ?? [] as $attr) {
                $atributo = isset($attr['id'])
                    ? ProdutoVariacaoAtributo::findOrFail($attr['id'])
                    : new ProdutoVariacaoAtributo(['id_variacao' => $variacao->id]);

                $atributo->fill([
                    'atributo' => $attr['atributo'],
                    'valor' => $attr['valor'],
                ])->save();

                $idsAtributos[] = $atributo->id;
            }

            $variacao->atributos()->whereNotIn('id', $idsAtributos)->delete();
        }

        ProdutoVariacao::where('produto_id', $produto->id)
            ->whereNotIn('id', $idsVariacoes)
            ->each(function ($v) {
                $v->atributos()->delete();
                $v->delete();
            });

        return $produto->load('variacoes.atributos');
    }

    public function listarProdutosFiltrados($request): LengthAwarePaginator
    {
        $query = Produto::with([
            'categoria',
            'variacoes.atributos',
            'variacoes.estoque',
            'variacoes.outlet',
            'variacoes.outlets',
            'imagemPrincipal'
        ]);

        if (!empty($request->nome)) {
            $query->where('nome', 'ILIKE', '%' . $request->nome . '%');
        }

        if (!empty($request->id_categoria)) {
            $idsSelecionados = is_array($request->id_categoria) ? $request->id_categoria : [$request->id_categoria];
            $idsExpandidos = Categoria::expandirIdsComFilhos($idsSelecionados);
            $query->whereIn('id_categoria', $idsExpandidos);
        }

        if (!is_null($request->ativo)) {
            $query->where('ativo', (bool) $request->ativo);
        }

        if (!is_null($request->is_outlet)) {
            if ($request->is_outlet) {
                $query->whereHas('variacoes.outlet', function ($q) {
                    $q->where('quantidade_restante', '>', 0);
                })->whereHas('variacoes.estoque', function ($q) {
                    $q->where('quantidade', '>', 0);
                });
            } else {
                $query->whereDoesntHave('variacoes.outlet', function ($q) {
                    $q->where('quantidade_restante', '>', 0);
                });
            }
        } elseif (!empty($request->estoque_status)) {
            if ($request->estoque_status === 'com_estoque') {
                $query->whereHas('variacoes.estoque', fn($q) => $q->where('quantidade', '>', 0));
            } elseif ($request->estoque_status === 'sem_estoque') {
                $query->where(function ($q) {
                    $q->whereDoesntHave('variacoes.estoque')
                        ->orWhereHas('variacoes.estoque', fn($q2) => $q2->where('quantidade', '<=', 0));
                });
            }
        }

        if (!empty($request->atributos) && is_array($request->atributos)) {
            foreach ($request->atributos as $atributo => $valores) {
                if (!empty($valores)) {
                    $query->whereHas('variacoes.atributos', function ($q) use ($atributo, $valores) {
                        $q->where('atributo', $atributo)
                            ->whereIn('valor', is_array($valores) ? $valores : [$valores]);
                    });
                }
            }
        }

        $query->orderByDesc('created_at');

        return $query->paginate($request->get('per_page', 15));
    }

}
