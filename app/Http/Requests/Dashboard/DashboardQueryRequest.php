<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class DashboardQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'compare' => $this->boolean('compare'),
            'fresh' => $this->boolean('fresh'),
            'deposito_id' => $this->input('deposito_id') !== null
                ? (int) $this->input('deposito_id')
                : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'period' => 'nullable|in:today,7d,month,6m,custom',
            'inicio' => 'nullable|date_format:Y-m-d|required_if:period,custom',
            'fim' => 'nullable|date_format:Y-m-d|required_if:period,custom|after_or_equal:inicio',
            'compare' => 'nullable|boolean',
            'deposito_id' => 'nullable|integer|min:1',
            'fresh' => 'nullable|boolean',
        ];
    }

    public function filters(): array
    {
        return [
            'period' => (string) ($this->validated('period') ?? config('dashboard.periods.default', 'month')),
            'inicio' => $this->validated('inicio'),
            'fim' => $this->validated('fim'),
            'compare' => (bool) ($this->validated('compare') ?? false),
            'deposito_id' => $this->validated('deposito_id'),
            'fresh' => (bool) ($this->validated('fresh') ?? false),
        ];
    }
}
