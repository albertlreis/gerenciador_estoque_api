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
        ]);
    }

    public function rules(): array
    {
        return [
            'descricao'      => ['required', 'string', 'max:255'],
            'tipo'           => ['required', 'in:receita,despesa'],
            'status'         => ['nullable', 'in:confirmado,cancelado'],

            'categoria_id'   => ['nullable', 'integer', 'min:1'],
            'centro_custo_id'=> ['nullable', 'integer', 'min:1'],
            'conta_id'       => ['nullable', 'integer', 'min:1'],

            'valor'          => ['required', 'numeric', 'min:0.01'],

            'data_movimento' => ['required', 'date'],
            'competencia'    => ['nullable', 'date'], // ou date_format:Y-m-d se quiser travar

            'observacoes'    => ['nullable', 'string'],

            'referencia_type'=> ['nullable', 'string', 'max:120'],
            'referencia_id'  => ['nullable', 'integer', 'min:1'],

            'pagamento_type' => ['nullable', 'string', 'max:120'],
            'pagamento_id'   => ['nullable', 'integer', 'min:1'],
        ];
    }
}
