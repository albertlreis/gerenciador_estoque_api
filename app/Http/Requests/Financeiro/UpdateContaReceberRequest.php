<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContaReceberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => ['sometimes', 'string', 'max:255'],
            'numero_documento' => ['sometimes', 'string', 'max:255'],
            'data_vencimento' => ['sometimes', 'date'],
            'valor_bruto' => ['sometimes', 'numeric', 'min:0'],
            'valor_liquido' => ['sometimes', 'numeric', 'min:0'],
            'forma_recebimento' => ['sometimes', 'string', 'max:100'],
            'centro_custo' => ['sometimes', 'string', 'max:100'],
            'categoria' => ['sometimes', 'string', 'max:100'],
            'observacoes' => ['nullable', 'string'],
        ];
    }
}
