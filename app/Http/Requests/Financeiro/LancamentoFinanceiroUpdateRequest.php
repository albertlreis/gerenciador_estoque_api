<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class LancamentoFinanceiroUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $input = $this->all();
        $data = [];

        if (array_key_exists('descricao', $input)) {
            $data['descricao'] = $this->input('descricao') !== null ? trim((string)$this->input('descricao')) : null;
        }

        if (array_key_exists('tipo', $input)) {
            $data['tipo'] = $this->input('tipo') ? strtolower((string)$this->input('tipo')) : null;
        }

        if (array_key_exists('status', $input)) {
            $data['status'] = $this->input('status') ? strtolower((string)$this->input('status')) : null;
        }

        foreach (['recibo_pessoa_nome', 'recibo_pessoa_documento'] as $campo) {
            if (array_key_exists($campo, $input)) {
                $valor = trim((string)($this->input($campo) ?? ''));
                $data[$campo] = $valor !== '' ? $valor : null;
            }
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            'descricao'      => ['sometimes', 'required', 'string', 'max:255'],
            'tipo'           => ['sometimes', 'required', 'in:receita,despesa,transferencia,ajuste'],
            'status'         => ['sometimes', 'required', 'in:confirmado,cancelado'],

            'categoria_id'   => ['sometimes', 'nullable', 'integer', 'min:1'],
            'centro_custo_id'=> ['sometimes', 'nullable', 'integer', 'min:1'],
            'conta_id'       => ['sometimes', 'nullable', 'integer', 'min:1'],

            'valor'          => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'data_pagamento' => ['sometimes', 'nullable', 'date'],
            'data_movimento' => ['sometimes', 'required', 'date'],
            'competencia'    => ['sometimes', 'nullable', 'date'],

            'observacoes'    => ['sometimes', 'nullable', 'string'],
            'recibo_pessoa_nome' => ['sometimes', 'nullable', 'string', 'max:255'],
            'recibo_pessoa_documento' => ['sometimes', 'nullable', 'string', 'max:60'],

            'referencia_type'=> ['sometimes', 'nullable', 'string', 'max:120'],
            'referencia_id'  => ['sometimes', 'nullable', 'integer', 'min:1'],

            'pagamento_type' => ['sometimes', 'nullable', 'string', 'max:120'],
            'pagamento_id'   => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
