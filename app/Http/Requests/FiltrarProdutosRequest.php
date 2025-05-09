<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FiltrarProdutosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ou aplicar lógica de permissão
    }

    public function rules(): array
    {
        return [
            'nome'           => ['nullable', 'string', 'max:255'],
            'id_categoria'   => ['nullable', 'array'],
            'id_categoria.*' => ['integer', 'exists:categorias,id'],
            'ativo'          => ['nullable', 'boolean'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:100'],
            'atributos'      => ['nullable', 'array'],
            'atributos.*'    => ['array'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_categoria.*.exists' => 'Alguma categoria informada é inválida.',
        ];
    }
}
