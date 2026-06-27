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
            'localizacao_id' => ['nullable', 'integer', 'min:1'],

            'produto' => ['nullable', 'string', 'max:255'],
            'localizacao' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:80'],
            'estoque_cliente' => ['nullable', 'boolean'],

            'periodo' => ['nullable', 'array', 'size:2'],
            'periodo.0' => ['nullable', 'date_format:Y-m-d'],
            'periodo.1' => ['nullable', 'date_format:Y-m-d'],

            'estoque_status' => ['nullable', 'in:com_estoque,sem_estoque'],
            'zerados' => ['nullable', 'boolean'], // boolean vem como 0/1 ou "true"/"false" no front
            'dias_sem_venda_min' => ['nullable', 'integer', 'min:1'],

            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],

            'sort_field' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],

            'export' => ['nullable', 'string', 'in:pdf,excel'],
            'colunas' => ['nullable'],
            'colunas.*' => ['nullable', 'string', 'max:64'],
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

        foreach (['produto', 'localizacao', 'area', 'tipo', 'sort_field', 'sort_order', 'export'] as $k) {
            if (array_key_exists($k, $input) && is_string($input[$k])) {
                $input[$k] = trim($input[$k]);
                if ($input[$k] === '') $input[$k] = null;
            }
        }

        if (array_key_exists('estoque_status', $input) && is_string($input['estoque_status'])) {
            $input['estoque_status'] = strtolower(trim($input['estoque_status']));
            if ($input['estoque_status'] === '' || $input['estoque_status'] === 'all' || $input['estoque_status'] === 'todos') {
                $input['estoque_status'] = null;
            }
        }

        foreach (['deposito', 'categoria', 'fornecedor', 'localizacao_id', 'per_page', 'page', 'dias_sem_venda_min'] as $k) {
            if (array_key_exists($k, $input) && $input[$k] === '') {
                $input[$k] = null;
            }
        }

        foreach (['estoque_cliente', 'zerados'] as $k) {
            if (!array_key_exists($k, $input)) {
                continue;
            }

            if ($input[$k] === '') {
                $input[$k] = null;
                continue;
            }

            $boolean = filter_var($input[$k], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($boolean !== null) {
                $input[$k] = $boolean;
            }
        }

        if (isset($input['colunas']) && is_string($input['colunas'])) {
            $input['colunas'] = array_filter(array_map('trim', explode(',', $input['colunas'])));
        }

        // Caso venha "periodo=2025-01-01,2025-01-31" por algum client, tenta normalizar
        if (isset($input['periodo']) && is_string($input['periodo']) && str_contains($input['periodo'], ',')) {
            $parts = array_map('trim', explode(',', $input['periodo']));
            if (count($parts) === 2) $input['periodo'] = [$parts[0], $parts[1]];
        }

        $this->replace($input);
    }
}
