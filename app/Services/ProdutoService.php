<?php

namespace App\Services;

use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;

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
                'sku' => $var['sku'],
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
                'sku' => $var['sku'],
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

        ProdutoVariacao::where('produto_id', $produto->id)->whereNotIn('id', $idsVariacoes)->each(function ($v) {
            $v->atributos()->delete();
            $v->delete();
        });

        return $produto->load('variacoes.atributos');
    }

    public function listarProdutosFiltrados($request)
    {
        $query = Produto::with(['variacoes.atributos']);

        if ($request->filled('nome')) {
            $query->where('nome', 'like', '%' . $request->nome . '%');
        }

        if ($request->filled('id_categoria')) {
            $categorias = is_array($request->id_categoria) ? $request->id_categoria : [$request->id_categoria];
            $query->whereIn('id_categoria', $categorias);
        }

        if ($request->has('ativo')) {
            $ativo = filter_var($request->ativo, FILTER_VALIDATE_BOOLEAN);
            $query->where('ativo', $ativo);
        }

        if ($request->filled('atributos') && is_array($request->atributos)) {
            foreach ($request->atributos as $atributo => $valores) {
                $query->whereHas('variacoes.atributos', function ($q) use ($atributo, $valores) {
                    $q->where('atributo', $atributo)
                        ->whereIn('valor', is_array($valores) ? $valores : [$valores]);
                });
            }
        }

        $query->orderBy('created_at', 'desc');

        return $query->paginate($request->get('per_page', 15));
    }
}
