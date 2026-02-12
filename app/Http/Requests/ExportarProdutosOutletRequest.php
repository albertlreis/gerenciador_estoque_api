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
            'ids' => ['required', 'array', 'min:1', 'max:2000'],
            'ids.*' => ['integer', 'distinct', 'exists:produtos,id'],
            'format' => ['nullable', 'in:csv,pdf'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Informe ao menos um produto para exportacao.',
            'ids.array' => 'A lista de produtos deve ser um array.',
            'ids.min' => 'Informe ao menos um produto para exportacao.',
            'ids.max' => 'Quantidade de produtos excede o limite permitido.',
            'ids.*.exists' => 'Algum produto informado nao existe.',
            'format.in' => 'Formato invalido. Use csv ou pdf.',
        ];
    }
}
