<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FormRequest responsável pela validação da criação de devoluções ou trocas.
 */
class DevolucaoRequest extends FormRequest
{
    /**
     * Determina se o usuário está autorizado a fazer esta requisição.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Regras de validação para criar uma devolução ou troca.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pedido_id' => ['required', 'integer', 'exists:pedidos,id'],
            'tipo'      => ['required', 'in:troca,credito'],
            'motivo'    => ['required', 'string'],
            'itens'     => ['required', 'array', 'min:1'],

            'itens.*.pedido_item_id' => ['required', 'integer', 'exists:pedido_itens,id'],
            'itens.*.quantidade'     => ['required', 'integer', 'min:1'],

            'itens.*.trocas' => [
                'nullable',
                'array',
                function ($attribute, $value, $fail) {
                    if ($this->input('tipo') === 'troca' && (!is_array($value) || count($value) < 1)) {
                        $fail("O campo {$attribute} deve conter pelo menos um item de troca.");
                    }
                },
            ],

            'itens.*.trocas.*.nova_variacao_id' => [
                'required_if:tipo,troca',
                'integer',
                'exists:produto_variacoes,id'
            ],
            'itens.*.trocas.*.quantidade' => [
                'required_if:tipo,troca',
                'integer',
                'min:1'
            ],
            'itens.*.trocas.*.preco_unitario' => [
                'required_if:tipo,troca',
                'numeric',
                'min:0'
            ],
        ];
    }

    /**
     * Mensagens customizadas para erros de validação.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pedido_id.required' => 'O pedido é obrigatório.',
            'tipo.required' => 'O tipo de devolução é obrigatório.',
            'motivo.required' => 'O motivo da devolução é obrigatório.',
            'itens.required' => 'Pelo menos um item deve ser informado.',
            'itens.min' => 'Você deve selecionar ao menos um item para devolução.',
            'itens.*.pedido_item_id.required' => 'O item do pedido é obrigatório.',
            'itens.*.quantidade.min' => 'A quantidade deve ser maior que zero.',
            'itens.*.trocas.*.nova_variacao_id.required_if' => 'A nova variação é obrigatória em trocas.',
            'itens.*.trocas.*.quantidade.required_if' => 'A quantidade de troca é obrigatória.',
            'itens.*.trocas.*.preco_unitario.required_if' => 'O preço unitário é obrigatório.',
        ];
    }
}
