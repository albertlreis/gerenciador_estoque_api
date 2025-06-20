<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCarrinhoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_cliente' => 'nullable|exists:clientes,id',
            'id_parceiro' => 'nullable|exists:parceiros,id',
        ];
    }

    public function messages(): array
    {
        return [
            'id_cliente.exists' => 'O cliente informado não é válido.',
            'id_parceiro.exists' => 'O parceiro informado não é válido.',
        ];
    }
}
