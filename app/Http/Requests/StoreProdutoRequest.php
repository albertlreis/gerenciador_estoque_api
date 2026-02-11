<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProdutoRequest extends FormRequest
{
    /**
     * Determina se o usuário está autorizado a fazer esta requisição.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true; // Ajuste para regras de permissão se necessário
    }

    /**
     * Regras de validação para cadastro de produto.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'nome' => 'required|string|max:255',
            'descricao' => 'nullable|string|max:1000',
            'id_categoria' => 'required|integer|exists:categorias,id',
            'id_fornecedor' => 'nullable|integer|exists:fornecedores,id',
            'altura' => 'nullable|numeric|min:0',
            'largura' => 'nullable|numeric|min:0',
            'profundidade' => 'nullable|numeric|min:0',
            'peso' => 'nullable|numeric|min:0',
            'manual_conservacao' => 'nullable|file|mimes:pdf|max:5120',
            'ativo' => 'boolean',
            'motivo_desativacao' => 'nullable|string|max:1000',
            'estoque_minimo' => 'nullable|integer|min:0',
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();

        foreach (['altura', 'largura', 'profundidade', 'peso'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->normalizeDecimal($data[$field]);
            }
        }

        foreach (['id_categoria', 'id_fornecedor', 'estoque_minimo'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->normalizeNullable($data[$field]);
            }
        }

        $this->merge($data);
    }

    private function normalizeNullable(mixed $value): mixed
    {
        if (is_string($value)) {
            $trim = trim($value);
            return $trim === '' ? null : $trim;
        }

        return $value;
    }

    private function normalizeDecimal(mixed $value): mixed
    {
        $value = $this->normalizeNullable($value);
        if ($value === null || !is_string($value)) {
            return $value;
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
        }

        return str_replace(',', '.', $value);
    }

    /**
     * Mensagens de erro personalizadas.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do produto é obrigatório.',
            'id_categoria.required' => 'A categoria é obrigatória.',
            'id_categoria.exists' => 'A categoria selecionada não existe.',
            'id_fornecedor.exists' => 'O fornecedor selecionado não existe.',
            'altura.numeric' => 'A altura deve ser um número.',
            'largura.numeric' => 'A largura deve ser um número.',
            'profundidade.numeric' => 'A profundidade deve ser um número.',
            'peso.numeric' => 'O peso deve ser um número.',
            'manual_conservacao.mimes' => 'O manual deve ser um arquivo PDF.',
            'manual_conservacao.max' => 'O manual não pode exceder 5MB.',
            'estoque_minimo.integer' => 'O estoque mínimo deve ser um número inteiro.',
            'estoque_minimo.min' => 'O estoque mínimo não pode ser negativo.',
        ];
    }
}
