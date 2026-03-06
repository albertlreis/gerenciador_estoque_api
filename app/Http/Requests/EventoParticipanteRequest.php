<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventoParticipanteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:acesso_usuarios,id'],
            'obrigatorio' => ['nullable', 'boolean'],
        ];
    }
}
