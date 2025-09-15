<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ParceiroIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Converte with_trashed para boolean antes da validação.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('with_trashed')) {
            $this->merge([
                'with_trashed' => $this->boolean('with_trashed'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'order_by' => ['nullable', 'in:nome,created_at,updated_at'],
            'order_dir' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'with_trashed' => ['nullable', 'boolean'],
        ];
    }
}
