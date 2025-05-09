<?php

namespace App\Http\Controllers;

use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\ProdutoVariacaoAtributo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProdutoController extends Controller
{
    public function index()
    {
        $produtos = Produto::with('categoria')->get();
        return response()->json($produtos);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nome'          => 'required|string|max:255',
            'descricao'     => 'nullable|string',
            'id_categoria'  => 'required|exists:categorias,id',
            'fabricante'    => 'nullable|string|max:255',
            'ativo'         => 'boolean',
            'variacoes'     => 'required|array|min:1',
            'variacoes.*.nome'          => 'required|string|max:255',
            'variacoes.*.preco'         => 'required|numeric|min:0',
            'variacoes.*.custo'         => 'required|numeric|min:0',
            'variacoes.*.sku'           => 'required|string|max:100|unique:produto_variacoes,sku',
            'variacoes.*.codigo_barras' => 'nullable|string|max:100|unique:produto_variacoes,codigo_barras',
            'variacoes.*.atributos'     => 'nullable|array',
            'variacoes.*.atributos.*.atributo' => 'required_with:variacoes.*.atributos|string|max:100',
            'variacoes.*.atributos.*.valor'    => 'required_with:variacoes.*.atributos|string|max:100',
        ]);

        try {
            DB::beginTransaction();

            // Cria o produto
            $produto = Produto::create([
                'nome'         => $validated['nome'],
                'descricao'    => $validated['descricao'] ?? null,
                'id_categoria' => $validated['id_categoria'],
                'fabricante'   => $validated['fabricante'] ?? null,
                'ativo'        => $validated['ativo'] ?? true,
            ]);

            // Cria variações e atributos vinculados
            foreach ($validated['variacoes'] as $var) {
                $variacao = ProdutoVariacao::create([
                    'id_produto'    => $produto->id,
                    'nome'          => $var['nome'],
                    'preco'         => $var['preco'],
                    'custo'         => $var['custo'],
                    'sku'           => $var['sku'],
                    'codigo_barras' => $var['codigo_barras'] ?? null,
                ]);

                // Se existirem atributos
                if (!empty($var['atributos'])) {
                    foreach ($var['atributos'] as $attr) {
                        ProdutoVariacaoAtributo::create([
                            'id_variacao' => $variacao->id,
                            'atributo'    => $attr['atributo'],
                            'valor'       => $attr['valor'],
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Produto cadastrado com sucesso.',
                'produto' => $produto->load('variacoes.atributos')
            ], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erro ao cadastrar produto.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function show(Produto $produto)
    {
        $produto->load('categoria', 'imagens');
        return response()->json($produto);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'nome'          => 'required|string|max:255',
            'descricao'     => 'nullable|string',
            'id_categoria'  => 'required|exists:categorias,id',
            'fabricante'    => 'nullable|string|max:255',
            'ativo'         => 'boolean',
            'variacoes'     => 'required|array|min:1',
            'variacoes.*.id'            => 'nullable|exists:produto_variacoes,id',
            'variacoes.*.nome'          => 'required|string|max:255',
            'variacoes.*.preco'         => 'required|numeric|min:0',
            'variacoes.*.custo'         => 'required|numeric|min:0',
            'variacoes.*.sku'           => 'required|string|max:100',
            'variacoes.*.codigo_barras' => 'nullable|string|max:100',
            'variacoes.*.atributos'     => 'nullable|array',
            'variacoes.*.atributos.*.id'       => 'nullable|exists:produto_variacao_atributos,id',
            'variacoes.*.atributos.*.atributo' => 'required_with:variacoes.*.atributos|string|max:100',
            'variacoes.*.atributos.*.valor'    => 'required_with:variacoes.*.atributos|string|max:100',
        ]);

        try {
            DB::beginTransaction();

            $produto = Produto::findOrFail($id);
            $produto->update([
                'nome'         => $validated['nome'],
                'descricao'    => $validated['descricao'] ?? null,
                'id_categoria' => $validated['id_categoria'],
                'fabricante'   => $validated['fabricante'] ?? null,
                'ativo'        => $validated['ativo'] ?? true,
            ]);

            $variacaoIdsRecebidas = [];

            foreach ($validated['variacoes'] as $var) {
                if (isset($var['id'])) {
                    // Atualizar variação existente
                    $variacao = ProdutoVariacao::where('id_produto', $produto->id)->findOrFail($var['id']);
                    $variacao->update([
                        'nome'          => $var['nome'],
                        'preco'         => $var['preco'],
                        'custo'         => $var['custo'],
                        'sku'           => $var['sku'],
                        'codigo_barras' => $var['codigo_barras'] ?? null,
                    ]);
                } else {
                    // Criar nova variação
                    $variacao = ProdutoVariacao::create([
                        'id_produto'    => $produto->id,
                        'nome'          => $var['nome'],
                        'preco'         => $var['preco'],
                        'custo'         => $var['custo'],
                        'sku'           => $var['sku'],
                        'codigo_barras' => $var['codigo_barras'] ?? null,
                    ]);
                }

                $variacaoIdsRecebidas[] = $variacao->id;

                $atributoIdsRecebidos = [];

                if (!empty($var['atributos'])) {
                    foreach ($var['atributos'] as $attr) {
                        if (isset($attr['id'])) {
                            // Atualiza atributo existente
                            $atributo = ProdutoVariacaoAtributo::where('id_variacao', $variacao->id)->findOrFail($attr['id']);
                            $atributo->update([
                                'atributo' => $attr['atributo'],
                                'valor'    => $attr['valor'],
                            ]);
                        } else {
                            // Cria novo atributo
                            $atributo = ProdutoVariacaoAtributo::create([
                                'id_variacao' => $variacao->id,
                                'atributo'    => $attr['atributo'],
                                'valor'       => $attr['valor'],
                            ]);
                        }
                        $atributoIdsRecebidos[] = $atributo->id;
                    }

                    // Remove atributos não enviados
                    ProdutoVariacaoAtributo::where('id_variacao', $variacao->id)
                        ->whereNotIn('id', $atributoIdsRecebidos)
                        ->delete();
                } else {
                    // Remove todos os atributos da variação se não vierem
                    ProdutoVariacaoAtributo::where('id_variacao', $variacao->id)->delete();
                }
            }

            // Remove variações não enviadas
            ProdutoVariacao::where('id_produto', $produto->id)
                ->whereNotIn('id', $variacaoIdsRecebidas)
                ->each(function ($v) {
                    // Remove os atributos vinculados primeiro
                    $v->atributos()->delete();
                    $v->delete();
                });

            DB::commit();

            return response()->json([
                'message' => 'Produto atualizado com sucesso.',
                'produto' => $produto->load('variacoes.atributos')
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erro ao atualizar produto.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Produto $produto)
    {
        $produto->delete();
        return response()->json(null, 204);
    }
}
