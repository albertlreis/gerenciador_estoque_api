<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class ContaPagarRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'fornecedor_id' => ['nullable','integer','exists:fornecedores,id'],
            'descricao' => ['required','string','max:180'],
            'numero_documento' => ['nullable','string','max:80'],
            'data_emissao' => ['nullable','date'],
            'data_vencimento' => ['required','date'],
            'valor_bruto' => ['required','numeric','min:0'],
            'desconto' => ['nullable','numeric','min:0'],
            'juros' => ['nullable','numeric','min:0'],
            'multa' => ['nullable','numeric','min:0'],
            'status' => ['nullable','in:ABERTA,PARCIAL,PAGA,CANCELADA'],
            'categoria_id' => ['nullable','integer','exists:categorias_financeiras,id'],
            'centro_custo_id' => ['nullable','integer','exists:centros_custo,id'],
            'observacoes' => ['nullable','string'],
        ];
    }
}
