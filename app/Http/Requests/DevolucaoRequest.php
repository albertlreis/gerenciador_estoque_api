<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DevolucaoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Regras de validação para criar uma devolução.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pedido_id'                         => ['required','exists:pedidos,id'],
            'tipo'                              => ['required', Rule::in(['troca','credito'])],
            'motivo'                            => ['required','string','max:500'],
            'itens'                             => ['required','array','min:1'],
            'itens.*.pedido_item_id'            => ['required','exists:pedido_itens,id'],
            'itens.*.quantidade'                => ['required','integer','min:1'],
            'itens.*.trocas'                    => ['required_if:tipo,troca','array','min:1'],
            'itens.*.trocas.*.nova_variacao_id' => ['required_with:itens.*.trocas','exists:produto_variacoes,id'],
            'itens.*.trocas.*.quantidade'       => ['required_with:itens.*.trocas','integer','min:1'],
            'itens.*.trocas.*.preco_unitario'   => ['required_with:itens.*.trocas','numeric'],
        ];
    }
}
