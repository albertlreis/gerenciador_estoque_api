<?php

namespace App\Services;

use App\Models\Produto;
use App\Models\ProdutoVariacao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProdutoVariacaoService
{
    /**
     * Retorna uma variação completa, com relações necessárias para exibição detalhada.
     */
    public function obterVariacaoCompleta(int $produtoId, int $variacaoId): Builder|array|Collection|Model
    {
        $variacao = ProdutoVariacao::with([
            'produto',
            'atributos',
            'imagem',
            'estoque',
            'outlets',
            'outlets.motivo',
            'outlets.formasPagamento.formaPagamento',
        ])->findOrFail($variacaoId);

        if ($variacao->produto_id !== $produtoId) {
            abort(404, 'Variação não pertence a este produto.');
        }

        return $variacao;
    }

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
     * Atualiza uma variaÃ§Ã£o individual sem quebrar o contrato do update em lote.
     *
     * @param ProdutoVariacao $variacao
     * @param array $data
     * @return ProdutoVariacao
     * @throws ValidationException
     */
    public function atualizarIndividual(ProdutoVariacao $variacao, array $data): ProdutoVariacao
    {
        Validator::make($data, [
            'preco' => 'sometimes|numeric|min:0',
            'custo' => 'sometimes|nullable|numeric|min:0',
            'referencia' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('produto_variacoes', 'referencia')->ignore($variacao->id),
            ],
            'codigo_barras' => 'sometimes|nullable|string|max:255',
            'atributos' => 'sometimes|array',
            'atributos.*.atributo' => 'required_with:atributos.*.valor|string|max:255',
            'atributos.*.valor' => 'required_with:atributos.*.atributo|string|max:255',
        ])->validate();

        $updates = [];
        if (array_key_exists('preco', $data)) {
            $updates['preco'] = $data['preco'];
        }
        if (array_key_exists('custo', $data)) {
            $updates['custo'] = $data['custo'];
        }
        if (array_key_exists('referencia', $data)) {
            $updates['referencia'] = $data['referencia'];
        }
        if (array_key_exists('codigo_barras', $data)) {
            $updates['codigo_barras'] = $data['codigo_barras'];
        }

        if (!empty($updates)) {
            $variacao->fill($updates);
        }

        if ($variacao->isDirty()) {
            $variacao->save();
        }

        if (array_key_exists('atributos', $data)) {
            $this->sincronizarAtributos($variacao, $data['atributos'] ?? []);
        }

        return $variacao->refresh()->load('atributos', 'imagem');
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
