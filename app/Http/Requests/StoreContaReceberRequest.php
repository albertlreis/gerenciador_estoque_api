<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContaReceberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pedido_id' => ['nullable', 'integer', 'exists:pedidos,id'],
            'descricao' => ['required', 'string', 'max:255'],
            'numero_documento' => ['nullable', 'string', 'max:255'],
            'data_emissao' => ['required', 'date'],
            'data_vencimento' => ['required', 'date', 'after_or_equal:data_emissao'],
            'valor_bruto' => ['required', 'numeric', 'min:0'],
            'desconto' => ['nullable', 'numeric', 'min:0'],
            'juros' => ['nullable', 'numeric', 'min:0'],
            'multa' => ['nullable', 'numeric', 'min:0'],
            'valor_liquido' => ['required', 'numeric', 'min:0'],
            'forma_recebimento' => ['nullable', 'string', 'max:100'],
            'centro_custo' => ['nullable', 'string', 'max:100'],
            'categoria' => ['nullable', 'string', 'max:100'],
            'observacoes' => ['nullable', 'string'],
        ];
    }
}
