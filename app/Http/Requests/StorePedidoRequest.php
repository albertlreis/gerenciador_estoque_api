<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida os dados enviados para finalizar um pedido.
 */
class StorePedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'id_cliente'  => 'required|exists:clientes,id',
            'id_parceiro' => 'nullable|exists:parceiros,id',
            'observacoes' => 'nullable|string|max:1000',
            'modo_consignacao' => 'sometimes|boolean',
            'prazo_consignacao' => 'required_if:modo_consignacao,true|integer|min:1|max:30',
        ];

        if (auth()->user()?->perfil === 'Administrador') {
            $rules['id_usuario'] = 'nullable|exists:acesso_usuarios,id';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'id_cliente.required' => 'O cliente é obrigatório para finalizar o pedido.',
        ];
    }
}
