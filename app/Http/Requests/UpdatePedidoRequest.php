<?php

namespace App\Http\Requests;

use App\Helpers\AuthHelper;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AuthHelper::hasPermissao('pedidos.editar');
    }

    public function rules(): array
    {
        $pedidoId = $this->route('pedido')?->id ?? null;

        $rules = [
            'tipo' => ['nullable', 'in:venda,reposicao'],
            'numero_externo' => ['nullable', 'string', 'max:50', 'unique:pedidos,numero_externo,' . $pedidoId],

            'id_cliente' => ['nullable', 'integer', 'exists:clientes,id'],
            'id_parceiro' => ['nullable', 'integer', 'exists:parceiros,id'],

            'data_pedido' => ['nullable', 'date'],
            'observacoes' => ['nullable', 'string', 'max:1000'],
            'prazo_dias_uteis' => ['nullable', 'integer', 'min:1', 'max:365'],
            'data_limite_entrega' => ['nullable', 'date'],

            'itens' => ['sometimes', 'array', 'min:1'],
            'itens.*.id' => ['nullable', 'integer', 'exists:pedido_itens,id'],
            'itens.*.id_variacao' => ['nullable', 'integer', 'exists:produto_variacoes,id'],
            'itens.*.id_produto' => ['nullable', 'integer', 'exists:produtos,id'],
            'itens.*.quantidade' => ['required_with:itens', 'integer', 'min:1'],
            'itens.*.preco_unitario' => ['required_with:itens', 'numeric', 'min:0'],
            'itens.*.id_deposito' => ['nullable', 'integer', 'exists:depositos,id'],
            'itens.*.observacoes' => ['nullable', 'string', 'max:1000'],
        ];

        if (AuthHelper::hasPermissao('pedidos.selecionar_vendedor')) {
            $rules['id_usuario'] = ['nullable', 'integer', 'exists:acesso_usuarios,id'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();

        foreach (['numero_externo', 'observacoes', 'tipo'] as $k) {
            if (array_key_exists($k, $input) && is_string($input[$k])) {
                $input[$k] = trim($input[$k]);
                if ($input[$k] === '') {
                    $input[$k] = null;
                }
            }
        }

        if (isset($input['itens']) && is_array($input['itens'])) {
            $input['itens'] = array_map(function ($item) {
                if (!is_array($item)) return $item;

                foreach (['observacoes'] as $k) {
                    if (array_key_exists($k, $item) && is_string($item[$k])) {
                        $item[$k] = trim($item[$k]);
                        if ($item[$k] === '') {
                            $item[$k] = null;
                        }
                    }
                }

                return $item;
            }, $input['itens']);
        }

        $this->replace($input);
    }
}
