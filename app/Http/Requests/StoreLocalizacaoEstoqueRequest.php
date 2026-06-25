<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLocalizacaoEstoqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'area' => ['nullable', 'string', 'max:80'],
            'corredor' => ['nullable', 'string', 'max:80'],
            'setor' => ['nullable', 'string', 'max:80'],
            'coluna' => ['nullable', 'string', 'max:80'],
            'observacoes' => ['nullable', 'string'],
            'ativo' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $temLocalizacao = collect(['area', 'corredor', 'setor', 'coluna'])
                ->contains(fn ($field) => trim((string) ($this->input($field) ?? '')) !== '');

            if (!$temLocalizacao) {
                $v->errors()->add('localizacao', 'Informe area, corredor, setor ou coluna.');
            }
        });
    }
}
