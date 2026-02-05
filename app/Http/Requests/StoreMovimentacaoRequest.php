<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Enums\EstoqueMovimentacaoTipo;

/**
 * Validação para criação de movimentações de estoque.
 */
class StoreMovimentacaoRequest extends FormRequest
{
    /**
     * Verifica se o usuário está autorizado.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Regras de validação.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tipos = array_map(fn($t) => $t->value, EstoqueMovimentacaoTipo::cases());
        return [
            'id_variacao'         => 'required|integer|exists:produto_variacoes,id',
            'id_deposito_origem'  => 'nullable|exists:depositos,id',
            'id_deposito_destino' => 'nullable|exists:depositos,id',
            'tipo'                => ['required', 'string', 'max:50', Rule::in($tipos)],
            'quantidade'          => 'required|integer|min:1',
            'observacao'          => 'nullable|string',
            'data_movimentacao'   => 'nullable|date',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $origem = $this->input('id_deposito_origem');
            $destino = $this->input('id_deposito_destino');

            if (!$origem && !$destino) {
                $v->errors()->add('id_deposito_origem', 'Informe depósito de origem ou destino.');
            }

            if ($origem && $destino && (int)$origem === (int)$destino) {
                $v->errors()->add('id_deposito_destino', 'Depósitos de origem e destino não podem ser iguais.');
            }
        });
    }
}
