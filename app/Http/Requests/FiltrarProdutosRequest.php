<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FiltrarProdutosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ativo' => $this->toBoolean($this->ativo),
            'is_outlet' => $this->toBoolean($this->is_outlet),
            'id_categoria' => $this->castToArray($this->id_categoria),
            'fornecedor_id' => $this->castToArray($this->fornecedor_id),
        ]);
    }

    private function toBoolean($value): ?bool
    {
        if (is_null($value) || $value === '') return null;
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function castToArray($value): ?array
    {
        if (is_null($value) || $value === '') return null;
        return is_array($value) ? $value : [$value];
    }

    public function rules(): array
    {
        return [
            'nome'             => ['nullable', 'string', 'max:255'],
            'id_categoria'     => ['nullable', 'array'],
            'id_categoria.*'   => ['integer', 'exists:categorias,id'],
            'fornecedor_id'    => ['nullable', 'array'],
            'fornecedor_id.*'  => ['integer', 'exists:fornecedores,id'],
            'ativo'            => ['nullable', 'boolean'],
            'is_outlet'        => ['nullable', 'boolean'],
            'estoque_status'   => ['nullable', 'in:com_estoque,sem_estoque'],
            'per_page'         => ['nullable', 'integer', 'min:1', 'max:100'],
            'atributos'        => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_categoria.*.exists' => 'Alguma categoria informada é inválida.',
            'ativo.boolean'         => 'O campo ativo deve ser verdadeiro ou falso.',
            'is_outlet.boolean'     => 'O campo outlet deve ser verdadeiro ou falso.',
            'estoque_status.in'     => 'O filtro de estoque deve ser com_estoque ou sem_estoque.',
        ];
    }
}
