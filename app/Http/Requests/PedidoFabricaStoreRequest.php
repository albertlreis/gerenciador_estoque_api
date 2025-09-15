<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @phpstan-type Item array{
 *   produto_variacao_id:int,
 *   quantidade:int,
 *   deposito_id?:int|null,
 *   pedido_venda_id?:int|null,
 *   observacoes?:string|null
 * }
 */
class PedidoFabricaStoreRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'data_previsao_entrega' => ['nullable', 'date'],
            'observacoes'           => ['nullable', 'string'],
            'itens'                 => ['required', 'array', 'min:1'],
            'itens.*.produto_variacao_id' => ['required', 'integer', 'exists:produto_variacoes,id'],
            'itens.*.quantidade'          => ['required', 'integer', 'min:1'],
            'itens.*.deposito_id'         => ['nullable', 'integer', 'exists:depositos,id'],
            'itens.*.pedido_venda_id'     => ['nullable', 'integer', 'exists:pedidos,id'],
            'itens.*.observacoes'         => ['nullable', 'string'],
        ];
    }
}
