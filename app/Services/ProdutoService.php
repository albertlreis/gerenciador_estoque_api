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
                'id_produto' => $produto->id,
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
                : new ProdutoVariacao(['id_produto' => $produto->id]);

            $variacao->fill([
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

        ProdutoVariacao::where('id_produto', $produto->id)->whereNotIn('id', $idsVariacoes)->each(function ($v) {
            $v->atributos()->delete();
            $v->delete();
        });

        return $produto->load('variacoes.atributos');
    }
}
