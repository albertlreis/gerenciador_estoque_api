<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class TransferenciaFinanceiraIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'q' => $this->input('q') !== null ? trim((string) $this->input('q')) : null,
            'status' => $this->input('status') ? strtolower((string) $this->input('status')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'data_inicio' => ['nullable', 'date'],
            'data_fim'    => ['nullable', 'date', 'after_or_equal:data_inicio'],
            'conta_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:confirmado,cancelado'],
        ];
    }
}
