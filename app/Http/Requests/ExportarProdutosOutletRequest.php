<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportarProdutosOutletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['nullable', 'array', 'min:1', 'max:2000'],
            'ids.*' => ['integer', 'distinct', 'exists:produtos,id'],
            'filters' => ['nullable', 'array'],
            'filters.id_categoria' => ['nullable', 'array'],
            'filters.id_categoria.*' => ['integer', 'distinct', 'exists:categorias,id'],
            'filters.q' => ['nullable', 'string', 'max:255'],
            'filters.is_outlet' => ['nullable', 'boolean'],
            'format' => ['nullable', 'in:csv,pdf'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $ids = $this->input('ids');
            $filters = $this->input('filters');

            if ((!is_array($ids) || count($ids) === 0) && (!is_array($filters) || count(array_filter($filters, fn ($value) => $value !== null && $value !== '' && $value !== [])) === 0)) {
                $validator->errors()->add('ids', 'Informe produtos ou filtros para exportacao.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'ids.array' => 'A lista de produtos deve ser um array.',
            'ids.min' => 'Informe ao menos um produto para exportacao.',
            'ids.max' => 'Quantidade de produtos excede o limite permitido.',
            'ids.*.exists' => 'Algum produto informado nao existe.',
            'format.in' => 'Formato invalido. Use csv ou pdf.',
        ];
    }
}
