<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProdutoRequest extends FormRequest
{
    /**
     * Determina se o usuário está autorizado a atualizar o produto.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Regras de validação para atualização de produto.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'nome' => 'required|string|max:255',
            'descricao' => 'nullable|string|max:1000',
            'id_categoria' => 'required|integer|exists:categorias,id',
            'id_fornecedor' => 'nullable|integer|exists:fornecedores,id',
            'altura' => 'nullable|numeric|min:0',
            'largura' => 'nullable|numeric|min:0',
            'profundidade' => 'nullable|numeric|min:0',
            'peso' => 'nullable|numeric|min:0',
        ];
    }

    /**
     * Mensagens de erro personalizadas.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do produto é obrigatório.',
            'id_categoria.required' => 'A categoria é obrigatória.',
            'id_categoria.exists' => 'A categoria selecionada não existe.',
            'id_fornecedor.exists' => 'O fornecedor selecionado não existe.',
        ];
    }
}
