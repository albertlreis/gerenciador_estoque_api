<?php
namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros do dashboard financeiro.
 * - data_inicio/data_fim: YYYY-MM-DD (opcional)
 */
class FinanceiroDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'data_inicio' => ['nullable', 'date_format:Y-m-d'],
            'data_fim'    => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data_inicio'],
        ];
    }
}
