<?php

namespace App\Http\Requests;

use App\Helpers\AuthHelper;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePedidoObservacaoInternaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AuthHelper::hasPermissao('pedidos.editar');
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('observacao_interna') || ! is_string($this->input('observacao_interna'))) {
            return;
        }

        $observacaoInterna = trim($this->input('observacao_interna'));
        $this->merge(['observacao_interna' => $observacaoInterna === '' ? null : $observacaoInterna]);
    }

    public function rules(): array
    {
        return [
            'observacao_interna' => ['present', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'observacao_interna.present' => 'Informe o campo observação interna, mesmo quando desejar removê-lo.',
            'observacao_interna.string' => 'A observação interna deve ser um texto válido.',
            'observacao_interna.max' => 'A observação interna deve possuir no máximo 1.000 caracteres.',
        ];
    }
}
