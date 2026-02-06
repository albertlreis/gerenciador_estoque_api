<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FiltroEstoqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Regras para filtros de estoque/movimentações.
     *
     * Observação:
      * - periodo deve ser array com 2 datas (YYYY-MM-DD).
     * - em /estoque/atual, periodo considera data_movimentacao (não estoque.updated_at).
     * - sort_order só aceita asc|desc.
     */
    public function rules(): array
    {
        return [
            'tipo' => ['nullable', 'string', 'in:entrada,saida'],

            'deposito' => ['nullable', 'integer', 'min:1'],
            'categoria' => ['nullable', 'integer', 'min:1'],
            'fornecedor' => ['nullable', 'integer', 'min:1'],

            'produto' => ['nullable', 'string', 'max:255'],

            'periodo' => ['nullable', 'array', 'size:2'],
            'periodo.0' => ['nullable', 'date_format:Y-m-d'],
            'periodo.1' => ['nullable', 'date_format:Y-m-d'],

            'zerados' => ['nullable'], // boolean vem como 0/1 ou "true"/"false" no front

            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],

            'sort_field' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],

            'export' => ['nullable', 'string', 'in:pdf'],
        ];
    }

    /**
     * Normalizações antes da validação (opcional mas ajuda muito):
     * - transforma '' em null
     * - garante que periodo venha como array
     */
    protected function prepareForValidation(): void
    {
        $input = $this->all();

        foreach (['produto', 'tipo', 'sort_field', 'sort_order', 'export'] as $k) {
            if (array_key_exists($k, $input) && is_string($input[$k])) {
                $input[$k] = trim($input[$k]);
                if ($input[$k] === '') $input[$k] = null;
            }
        }

        foreach (['deposito', 'categoria', 'fornecedor', 'per_page', 'page'] as $k) {
            if (array_key_exists($k, $input) && $input[$k] === '') {
                $input[$k] = null;
            }
        }

        // Caso venha "periodo=2025-01-01,2025-01-31" por algum client, tenta normalizar
        if (isset($input['periodo']) && is_string($input['periodo']) && str_contains($input['periodo'], ',')) {
            $parts = array_map('trim', explode(',', $input['periodo']));
            if (count($parts) === 2) $input['periodo'] = [$parts[0], $parts[1]];
        }

        $this->replace($input);
    }
}
