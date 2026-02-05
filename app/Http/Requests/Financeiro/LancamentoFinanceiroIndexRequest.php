<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class LancamentoFinanceiroIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'q'      => $this->input('q') ? trim((string)$this->input('q')) : null,
            'tipo'   => $this->input('tipo') ? strtolower((string)$this->input('tipo')) : null,
            'status' => $this->input('status') ? strtolower((string)$this->input('status')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            // período por data_movimento
            'data_inicio' => ['nullable', 'date'],
            'data_fim'    => ['nullable', 'date', 'after_or_equal:data_inicio'],

            'status'      => ['nullable', 'in:confirmado,cancelado'],
            'tipo'        => ['nullable', 'in:receita,despesa,transferencia,ajuste'],

            'categoria_id'=> ['nullable', 'integer', 'min:1'],
            'conta_id'    => ['nullable', 'integer', 'min:1'],
            'centro_custo_id' => ['nullable', 'integer', 'min:1'],

            'q'           => ['nullable', 'string', 'max:255'],

            'order_by'    => ['nullable', 'in:data_movimento,competencia,valor,created_at,id'],
            'order_dir'   => ['nullable', 'in:asc,desc'],

            'page'        => ['nullable', 'integer', 'min:1'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
