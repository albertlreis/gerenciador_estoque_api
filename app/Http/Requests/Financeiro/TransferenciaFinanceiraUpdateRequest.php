<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class TransferenciaFinanceiraUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->input('status') ? strtolower((string) $this->input('status')) : null,
            'observacoes' => $this->input('observacoes') !== null ? trim((string) $this->input('observacoes')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'conta_origem_id'  => ['required', 'integer', 'min:1', 'different:conta_destino_id'],
            'conta_destino_id' => ['required', 'integer', 'min:1'],
            'data_movimento'   => ['required', 'date'],
            'valor'            => ['required', 'numeric', 'min:0.01'],
            'observacoes'      => ['nullable', 'string', 'max:1000'],
            'status'           => ['nullable', 'in:confirmado,cancelado'],
        ];
    }
}
