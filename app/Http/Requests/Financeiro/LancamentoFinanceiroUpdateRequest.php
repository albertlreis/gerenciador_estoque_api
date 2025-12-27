<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class LancamentoFinanceiroUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'descricao' => $this->input('descricao') !== null ? trim((string)$this->input('descricao')) : null,
            'tipo'      => $this->input('tipo') ? strtolower((string)$this->input('tipo')) : null,
            'status'    => $this->input('status') ? strtolower((string)$this->input('status')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'descricao'      => ['sometimes', 'required', 'string', 'max:255'],
            'tipo'           => ['sometimes', 'required', 'in:receita,despesa'],
            'status'         => ['sometimes', 'required', 'in:confirmado,cancelado'],

            'categoria_id'   => ['sometimes', 'nullable', 'integer', 'min:1'],
            'centro_custo_id'=> ['sometimes', 'nullable', 'integer', 'min:1'],
            'conta_id'       => ['sometimes', 'nullable', 'integer', 'min:1'],

            'valor'          => ['sometimes', 'required', 'numeric', 'min:0.01'],

            'data_movimento' => ['sometimes', 'required', 'date'],
            'competencia'    => ['sometimes', 'nullable', 'date'],

            'observacoes'    => ['sometimes', 'nullable', 'string'],

            'referencia_type'=> ['sometimes', 'nullable', 'string', 'max:120'],
            'referencia_id'  => ['sometimes', 'nullable', 'integer', 'min:1'],

            'pagamento_type' => ['sometimes', 'nullable', 'string', 'max:120'],
            'pagamento_id'   => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
