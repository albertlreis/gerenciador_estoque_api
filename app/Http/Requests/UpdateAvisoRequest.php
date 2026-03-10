<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAvisoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titulo' => ['sometimes', 'string', 'max:255'],
            'conteudo' => ['sometimes', 'string'],
            'status' => ['sometimes', 'in:rascunho,publicado,arquivado'],
            'prioridade' => ['sometimes', 'in:normal,importante'],
            'pinned' => ['sometimes', 'boolean'],
            'publicar_em' => ['nullable', 'date'],
            'expirar_em' => ['nullable', 'date', 'after:publicar_em'],
        ];
    }
}
