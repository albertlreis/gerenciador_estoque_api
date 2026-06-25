<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContaReceberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'descricao' => $this->input('descricao') ? trim((string)$this->input('descricao')) : null,
            'numero_documento' => $this->input('numero_documento') !== null ? trim((string)$this->input('numero_documento')) : null,
            'observacoes' => $this->input('observacoes') !== null ? trim((string)$this->input('observacoes')) : null,
            'forma_recebimento' => $this->input('forma_recebimento') !== null ? trim((string)$this->input('forma_recebimento')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'pedido_id' => ['nullable','integer','exists:pedidos,id'],
            'cliente_id' => ['nullable','integer','exists:clientes,id'],
            'descricao' => ['required','string','max:255'],
            'numero_documento' => ['nullable','string','max:255'],
            'data_emissao' => ['nullable','date'],
            'data_vencimento' => ['required','date'],
            'valor_bruto' => ['required','numeric','min:0'],
            'desconto' => ['nullable','numeric','min:0'],
            'juros' => ['nullable','numeric','min:0'],
            'multa' => ['nullable','numeric','min:0'],
            'valor_recebido' => ['nullable','numeric','min:0'],
            'forma_recebimento' => ['nullable','string','max:30'],
            'categoria_id' => ['nullable','integer','exists:categorias_financeiras,id'],
            'centro_custo_id' => ['nullable','integer','exists:centros_custo,id'],
            'observacoes' => ['nullable','string'],
            'status' => ['nullable','in:ABERTA,PARCIAL,PAGA,CANCELADA'],
            'recorrencia' => ['nullable','array'],
            'recorrencia.frequencia' => ['required_with:recorrencia', Rule::in(['DIARIA','SEMANAL','MENSAL','ANUAL'])],
            'recorrencia.intervalo' => ['nullable','integer','min:1','max:365'],
            'recorrencia.termino_tipo' => ['nullable', Rule::in(['OCORRENCIAS','DATA'])],
            'recorrencia.ocorrencias' => ['nullable','integer','min:1','max:366'],
            'recorrencia.data_fim' => ['nullable','date'],
            'parcelamento' => ['nullable','array'],
            'parcelamento.quantidade_parcelas' => ['nullable','integer','min:1','max:120'],
            'parcelamento.valor_entrada' => ['nullable','numeric','min:0'],
            'parcelamento.intervalo_meses' => ['nullable','integer','min:1','max:24'],
            'parcelamento.primeiro_vencimento' => ['nullable','date'],
            'parcelamento.data_entrada' => ['nullable','date'],
            'parcelamento.parcelas' => ['nullable','array','max:121'],
            'parcelamento.parcelas.*.parcela_numero' => ['required_with:parcelamento.parcelas','integer','min:0','max:120'],
            'parcelamento.parcelas.*.vencimento' => ['required_with:parcelamento.parcelas','date'],
            'parcelamento.parcelas.*.valor' => ['required_with:parcelamento.parcelas','numeric','gt:0'],
            'parcelamento.parcelas.*.is_entrada' => ['nullable','boolean'],
            'pagamento_inicial' => ['nullable','array'],
            'pagamento_inicial.valor' => ['required_with:pagamento_inicial','numeric','gt:0'],
            'pagamento_inicial.data_pagamento' => ['required_with:pagamento_inicial','date'],
            'pagamento_inicial.forma_pagamento' => ['required_with:pagamento_inicial','string','max:50'],
            'pagamento_inicial.conta_financeira_id' => ['required_with:pagamento_inicial','integer','exists:contas_financeiras,id'],
            'pagamento_inicial.observacoes' => ['nullable','string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $emissao = $this->input('data_emissao');
            $venc = $this->input('data_vencimento');
            if ($emissao && $venc) {
                try {
                    if (strtotime($venc) < strtotime($emissao)) {
                        $v->errors()->add('data_vencimento', 'Data de vencimento não pode ser anterior à data de emissão.');
                    }
                } catch (\Throwable $e) {
                    // deixa o validator padrão lidar com formato inválido
                }
            }
            if ($this->filled('recorrencia') && $this->filled('parcelamento')) {
                $v->errors()->add('recorrencia', 'Recorrência e parcelamento não podem ser usados no mesmo lançamento.');
            }
        });
    }
}
