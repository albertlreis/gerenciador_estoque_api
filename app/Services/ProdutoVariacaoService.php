<?php

namespace App\Services;

use App\Models\Produto;
use App\Models\ProdutoVariacao;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProdutoVariacaoService
{
    /**
     * Atualiza ou cria em lote as variações de um produto,
     * sincronizando os atributos sem apagá-los indiscriminadamente.
     *
     * @param int $produtoId
     * @param array $variacoes
     * @return void
     * @throws ValidationException
     */
    public function atualizarLote(int $produtoId, array $variacoes): void
    {
        $produto = Produto::findOrFail($produtoId);
        $idsRecebidos = [];

        foreach ($variacoes as $variacaoData) {
            $this->validarVariacao($variacaoData);

            $variacao = ProdutoVariacao::updateOrCreate(
                [
                    'id' => $variacaoData['id'] ?? null,
                    'produto_id' => $produtoId,
                ],
                [
                    'preco' => $variacaoData['preco'],
                    'custo' => $variacaoData['custo'] ?? null,
                    'referencia' => $variacaoData['referencia'],
                    'codigo_barras' => $variacaoData['codigo_barras'] ?? null,
                ]
            );

            $idsRecebidos[] = $variacao->id;

            if (!empty($variacaoData['atributos'])) {
                $this->sincronizarAtributos($variacao, $variacaoData['atributos']);
            }
        }

        // Remove variações que não foram incluídas no payload
        $produto->variacoes()->whereNotIn('id', $idsRecebidos)->delete();
    }

    /**
     * Valida os dados de uma variação com base nas regras do sistema.
     *
     * @param array $data
     * @return void
     * @throws ValidationException
     */
    private function validarVariacao(array $data): void
    {
        Validator::make($data, [
            'id' => 'nullable|integer|exists:produto_variacoes,id',
            'preco' => 'required|numeric|min:0',
            'custo' => 'nullable|numeric|min:0',
            'referencia' => 'required|string|max:255',
            'codigo_barras' => 'nullable|string|max:255',
            'atributos' => 'nullable|array',
            'atributos.*.atributo' => 'required_with:atributos.*.valor|string|max:255',
            'atributos.*.valor' => 'required_with:atributos.*.atributo|string|max:255',
        ])->validate();
    }

    /**
     * Sincroniza os atributos de uma variação com base nos dados recebidos,
     * atualizando os existentes e removendo os ausentes.
     *
     * @param ProdutoVariacao $variacao
     * @param array $atributosRecebidos
     * @return void
     */
    private function sincronizarAtributos(ProdutoVariacao $variacao, array $atributosRecebidos): void
    {
        $atributos = [];

        foreach ($atributosRecebidos as $attr) {
            if (!empty($attr['atributo']) && !empty($attr['valor'])) {
                $atributos[$attr['atributo']] = ['valor' => $attr['valor']];
            }
        }

        $existentes = $variacao->atributos->keyBy('atributo');

        foreach ($atributos as $atributo => $dados) {
            $variacao->atributos()->updateOrCreate(
                ['atributo' => $atributo],
                ['valor' => $dados['valor']]
            );
        }

        $chavesRecebidas = array_keys($atributos);

        $existentes->each(function ($item) use ($chavesRecebidas) {
            if (!in_array($item->atributo, $chavesRecebidas)) {
                $item->delete();
            }
        });
    }
}
