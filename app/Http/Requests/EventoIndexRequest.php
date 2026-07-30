<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventoIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'usuario_id' => ['nullable', 'integer', 'exists:acesso_usuarios,id'],
        ];
    }
}
