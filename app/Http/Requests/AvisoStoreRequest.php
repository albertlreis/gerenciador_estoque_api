<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AvisoStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:255'],
            'conteudo' => ['required', 'string'],
            'status' => ['nullable', 'in:rascunho,publicado,arquivado'],
            'prioridade' => ['nullable', 'in:normal,importante'],
            'pinned' => ['nullable', 'boolean'],
            'publicar_em' => ['nullable', 'date'],
            'expirar_em' => ['nullable', 'date', 'after:publicar_em'],
        ];
    }
}

