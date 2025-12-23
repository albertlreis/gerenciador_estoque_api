<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class ContaFinanceiraIndexRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ativo' => $this->toBoolOrNull($this->input('ativo')),
        ]);
    }

    private function toBoolOrNull(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Aceita: true, false, "true", "false", 1, 0, "1", "0", "on", "off"
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public function rules(): array
    {
        return [
            'q'     => ['nullable', 'string', 'max:255'],
            'tipo'  => ['nullable', 'string', 'max:30'],
            'ativo' => ['nullable', 'boolean'],
        ];
    }
}
