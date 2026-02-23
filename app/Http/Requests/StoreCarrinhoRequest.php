<?php

namespace App\Http\Requests;

use App\Helpers\AuthHelper;
use Illuminate\Foundation\Http\FormRequest;

class StoreCarrinhoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'id_cliente' => 'required|exists:clientes,id',
        ];

        if (AuthHelper::podeSelecionarVendedorPedido()) {
            $rules['id_usuario'] = 'nullable|exists:acesso_usuarios,id';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'id_cliente.required' => 'O cliente é obrigatório.',
            'id_cliente.exists' => 'O cliente selecionado não é válido.',
        ];
    }
}
