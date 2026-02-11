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
        // Alias de 'q' → 'nome'
        $q = $this->q ?? $this->nome;

        $this->merge([
            'nome'             => $q,
            'ativo'            => $this->toBoolean($this->ativo),
            'is_outlet'        => $this->toBoolean($this->is_outlet),
            'id_categoria'     => $this->castToArray($this->id_categoria),
            'fornecedor_id'    => $this->castToArray($this->fornecedor_id),
            'com_estoque'      => $this->toBoolean($this->com_estoque),
            'incluir_estoque'  => $this->toBoolean($this->incluir_estoque),
            'campos_reduzidos' => $this->toBoolean($this->campos_reduzidos),
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
            'q'                 => ['nullable', 'string', 'max:255'],
            'nome'              => ['nullable', 'string', 'max:255'],
            'id_categoria'      => ['nullable', 'array'],
            'id_categoria.*'    => ['integer', 'exists:categorias,id'],
            'fornecedor_id'     => ['nullable', 'array'],
            'fornecedor_id.*'   => ['integer', 'exists:fornecedores,id'],
            'ativo'             => ['nullable', 'boolean'],
            'is_outlet'         => ['nullable', 'boolean'],
            'estoque_status'    => ['nullable', 'in:com_estoque,sem_estoque'],
            'per_page'          => ['nullable', 'integer', 'min:1', 'max:100'],
            'atributos'         => ['nullable', 'array'],
            'deposito_id'       => ['nullable', 'integer', 'exists:depositos,id'],
            'com_estoque'       => ['nullable', 'boolean'],
            'incluir_estoque'   => ['nullable', 'boolean'],
            'view'              => ['nullable', 'in:completa,simplificada,minima,lista'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_categoria.*.exists' => 'Alguma categoria informada é inválida.',
            'fornecedor_id.*.exists' => 'Algum fornecedor informado é inválido.',
            'ativo.boolean'         => 'O campo ativo deve ser verdadeiro ou falso.',
            'is_outlet.boolean'     => 'O campo outlet deve ser verdadeiro ou falso.',
            'estoque_status.in'     => 'O filtro de estoque deve ser com_estoque ou sem_estoque.',
        ];
    }
}
