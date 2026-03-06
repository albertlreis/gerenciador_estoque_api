<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventoStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', 'max:50'],
            'titulo' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'local' => ['nullable', 'string', 'max:255'],
            'inicio_em' => ['required', 'date'],
            'fim_em' => ['required', 'date', 'after:inicio_em'],
            'participantes' => ['nullable', 'array'],
            'participantes.*.user_id' => ['required_with:participantes', 'integer', 'exists:acesso_usuarios,id'],
            'participantes.*.obrigatorio' => ['nullable', 'boolean'],
        ];
    }
}
