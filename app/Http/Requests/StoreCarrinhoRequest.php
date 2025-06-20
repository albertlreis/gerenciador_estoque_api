<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCarrinhoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_cliente' => 'required|exists:clientes,id',
        ];
    }

    public function messages(): array
    {
        return [
            'id_cliente.required' => 'O cliente é obrigatório.',
            'id_cliente.exists' => 'O cliente selecionado não é válido.',
        ];
    }
}
