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
            'descricao' => ['sometimes','string','max:255'],
            'numero_documento' => ['sometimes','string','max:255'],
            'data_vencimento' => ['sometimes','date'],
            'valor_bruto' => ['sometimes','numeric','min:0'],
            'desconto' => ['sometimes','numeric','min:0'],
            'juros' => ['sometimes','numeric','min:0'],
            'multa' => ['sometimes','numeric','min:0'],
            'categoria_id' => ['sometimes','nullable','integer','exists:categorias_financeiras,id'],
            'centro_custo_id' => ['sometimes','nullable','integer','exists:centros_custo,id'],
            'observacoes' => ['nullable','string'],
            'status' => ['nullable','in:ABERTA,PARCIAL,PAGA,CANCELADA'],
        ];
    }
}
