<?php

namespace App\Http\Requests;

use App\Helpers\AuthHelper;
use Illuminate\Foundation\Http\FormRequest;

class StorePedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'id_carrinho'         => 'required|exists:carrinhos,id',
            'id_cliente'          => 'required|exists:clientes,id',
            'id_parceiro'         => 'nullable|exists:parceiros,id',
            'observacoes'         => 'nullable|string|max:1000',

            'modo_consignacao'    => 'sometimes|boolean',
            'prazo_consignacao'   => 'required_if:modo_consignacao,true|integer|min:1|max:60',

            'depositos_por_item'                  => 'sometimes|array',
            'depositos_por_item.*.id_carrinho_item' => 'required_with:depositos_por_item|exists:carrinho_itens,id',
            'depositos_por_item.*.id_deposito'      => 'nullable|exists:depositos,id',

            'registrar_movimentacao' => 'sometimes|boolean',
        ];

        if (AuthHelper::hasPermissao('carrinhos.selecionar.vendedor')) {
            $rules['id_usuario'] = 'nullable|exists:acesso_usuarios,id';
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->boolean('modo_consignacao')) {
            return;
        }

        $prazo = $this->input('prazo_consignacao');
        $prazoValido = is_numeric($prazo) ? (int) $prazo : null;

        if ($prazo === null || $prazo === '' || ($prazoValido !== null && $prazoValido <= 0)) {
            $this->merge([
                'prazo_consignacao' => (int) config('orders.prazo_padrao_dias_uteis', 60),
            ]);
        }
    }

    public function messages(): array
    {
        return [
            'id_carrinho.required' => 'O carrinho é obrigatório.',
            'id_cliente.required'  => 'O cliente é obrigatório para finalizar o pedido.',
            'prazo_consignacao.required_if' => 'Informe o prazo de consignação.',
        ];
    }
}
