<?php

namespace App\Http\Requests;

use App\Helpers\AuthHelper;
use Illuminate\Foundation\Http\FormRequest;

class UpdateConsignacaoObservacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AuthHelper::hasPermissao('consignacoes.gerenciar');
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('observacoes') || ! is_string($this->input('observacoes'))) {
            return;
        }

        $observacoes = trim($this->input('observacoes'));
        $this->merge(['observacoes' => $observacoes === '' ? null : $observacoes]);
    }

    public function rules(): array
    {
        return [
            'observacoes' => ['present', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'observacoes.present' => 'Informe o campo observações, mesmo quando desejar removê-lo.',
            'observacoes.string' => 'A observação deve ser um texto válido.',
            'observacoes.max' => 'A observação deve possuir no máximo 2.000 caracteres.',
        ];
    }
}
