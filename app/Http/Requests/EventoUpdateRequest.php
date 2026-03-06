<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventoUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['sometimes', 'required', 'string', 'max:50'],
            'titulo' => ['sometimes', 'required', 'string', 'max:255'],
            'descricao' => ['sometimes', 'nullable', 'string'],
            'local' => ['sometimes', 'nullable', 'string', 'max:255'],
            'inicio_em' => ['sometimes', 'required', 'date'],
            'fim_em' => ['sometimes', 'required', 'date', 'after:inicio_em'],
            'participantes' => ['sometimes', 'array'],
            'participantes.*.user_id' => ['required_with:participantes', 'integer', 'exists:acesso_usuarios,id'],
            'participantes.*.obrigatorio' => ['nullable', 'boolean'],
        ];
    }
}
