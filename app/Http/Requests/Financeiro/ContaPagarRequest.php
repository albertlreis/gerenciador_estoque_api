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
            'forma_pagamento' => ['nullable','string','max:50'],
            'categoria_id' => ['nullable','integer','exists:categorias_financeiras,id'],
            'centro_custo_id' => ['nullable','integer','exists:centros_custo,id'],
            'observacoes' => ['nullable','string'],
            'parcelamento' => ['nullable','array'],
            'parcelamento.quantidade_parcelas' => ['nullable','integer','min:1','max:120'],
            'parcelamento.valor_entrada' => ['nullable','numeric','min:0'],
            'parcelamento.intervalo_meses' => ['nullable','integer','min:1','max:24'],
            'parcelamento.primeiro_vencimento' => ['nullable','date'],
            'parcelamento.data_entrada' => ['nullable','date'],
            'pagamento_inicial' => ['nullable','array'],
            'pagamento_inicial.valor' => ['required_with:pagamento_inicial','numeric','gt:0'],
            'pagamento_inicial.data_pagamento' => ['required_with:pagamento_inicial','date'],
            'pagamento_inicial.forma_pagamento' => ['required_with:pagamento_inicial','string','max:50'],
            'pagamento_inicial.conta_financeira_id' => ['required_with:pagamento_inicial','integer','exists:contas_financeiras,id'],
            'pagamento_inicial.observacoes' => ['nullable','string'],
        ];
    }
}
