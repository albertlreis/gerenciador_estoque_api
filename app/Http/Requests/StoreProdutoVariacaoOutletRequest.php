<?php

namespace App\Http\Requests;

use App\Helpers\AuthHelper;
use Illuminate\Foundation\Http\FormRequest;

class StoreProdutoVariacaoOutletRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $outlet = is_array($this->input('outlet')) ? $this->input('outlet') : [];
        $variacao = is_array($this->input('variacao')) ? $this->input('variacao') : [];

        $globais = [];
        foreach (['preco' => 'preco_original', 'custo' => 'custo_original', 'motivo_alteracao_preco' => 'motivo_alteracao_preco'] as $origem => $destino) {
            if (array_key_exists($origem, $variacao)) {
                $globais[$destino] = $variacao[$origem];
            }
        }
        $this->merge(array_merge($outlet, $globais));
    }

    public function authorize(): bool
    {
        $podeCriar = AuthHelper::hasPermissao('produtos.outlet.cadastrar')
            || AuthHelper::hasPermissao('produtos.gerenciar');
        $enviouVariacao = is_array($this->input('variacao'))
            || $this->filled('preco_original')
            || $this->filled('custo_original');

        return $podeCriar
            && (!$enviouVariacao || AuthHelper::hasPermissao('produtos.precos_custos'));
    }

    public function rules(): array
    {
        return [
            'motivo_id' => 'nullable|exists:outlet_motivos,id',
            'quantidade' => 'required|integer|min:1',
            'produto_variacao_imagem_id' => 'nullable|integer|exists:produto_variacao_imagens,id',
            'preco_original' => 'sometimes|numeric|min:0',
            'custo_original' => 'sometimes|numeric|min:0',
            'motivo_alteracao_preco' => 'nullable|string|max:500',
            'formas_pagamento' => 'required|array|min:1',
            'formas_pagamento.*.forma_pagamento_id' => 'nullable|exists:outlet_formas_pagamento,id',
            'formas_pagamento.*.percentual_desconto'=> 'required|numeric|min:0|max:100',
            'formas_pagamento.*.max_parcelas'       => 'nullable|integer|min:1|max:36',
        ];
    }
}
