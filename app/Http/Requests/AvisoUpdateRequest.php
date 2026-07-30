<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AvisoUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'titulo' => ['sometimes', 'required', 'string', 'max:255'],
            'conteudo' => ['sometimes', 'required', 'string'],
            'status' => ['sometimes', 'required', 'in:rascunho,publicado,arquivado'],
            'prioridade' => ['sometimes', 'required', 'in:normal,importante'],
            'pinned' => ['sometimes', 'boolean'],
            'publicar_em' => ['sometimes', 'nullable', 'date'],
            'expirar_em' => ['sometimes', 'nullable', 'date'],
        ];
    }
}

