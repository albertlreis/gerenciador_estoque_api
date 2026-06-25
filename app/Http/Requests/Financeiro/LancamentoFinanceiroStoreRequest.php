<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class LancamentoFinanceiroStoreRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'descricao' => $this->input('descricao') ? trim((string)$this->input('descricao')) : null,
            'tipo'      => $this->input('tipo') ? strtolower((string)$this->input('tipo')) : null,
            'status'    => $this->input('status') ? strtolower((string)$this->input('status')) : null,
            'recibo_pessoa_nome' => $this->input('recibo_pessoa_nome') !== null
                ? trim((string)$this->input('recibo_pessoa_nome')) ?: null
                : null,
            'recibo_pessoa_documento' => $this->input('recibo_pessoa_documento') !== null
                ? trim((string)$this->input('recibo_pessoa_documento')) ?: null
                : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'descricao'      => ['required', 'string', 'max:255'],
            'tipo'           => ['required', 'in:receita,despesa,transferencia,ajuste'],
            'status'         => ['nullable', 'in:confirmado,cancelado'],

            'categoria_id'   => ['nullable', 'integer', 'min:1'],
            'centro_custo_id'=> ['nullable', 'integer', 'min:1'],
            'conta_id'       => ['nullable', 'integer', 'min:1'],

            'valor'          => ['required', 'numeric', 'min:0.01'],
            'data_pagamento' => ['nullable', 'date'],
            'data_movimento' => ['required', 'date'],
            'competencia'    => ['nullable', 'date'], // ou date_format:Y-m-d se quiser travar

            'observacoes'    => ['nullable', 'string'],
            'recibo_pessoa_nome' => ['nullable', 'string', 'max:255'],
            'recibo_pessoa_documento' => ['nullable', 'string', 'max:60'],

            'referencia_type'=> ['nullable', 'string', 'max:120'],
            'referencia_id'  => ['nullable', 'integer', 'min:1'],

            'pagamento_type' => ['nullable', 'string', 'max:120'],
            'pagamento_id'   => ['nullable', 'integer', 'min:1'],
        ];
    }
}
