<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProdutoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
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
            'variacoes.*.sku'           => [
                'required', 'string', 'max:100',
                Rule::unique('produto_variacoes', 'sku')->ignore($this->input('variacoes.*.id'))
            ],
            'variacoes.*.codigo_barras' => [
                'nullable', 'string', 'max:100',
                Rule::unique('produto_variacoes', 'codigo_barras')->ignore($this->input('variacoes.*.id'))
            ],
            'variacoes.*.atributos'     => 'nullable|array',
            'variacoes.*.atributos.*.id'       => 'nullable|exists:produto_variacao_atributos,id',
            'variacoes.*.atributos.*.atributo' => 'required_with:variacoes.*.atributos|string|max:100',
            'variacoes.*.atributos.*.valor'    => 'required_with:variacoes.*.atributos|string|max:100',
        ];
    }
}
