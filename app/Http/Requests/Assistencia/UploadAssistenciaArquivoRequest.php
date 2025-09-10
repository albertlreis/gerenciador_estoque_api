<?php

namespace App\Http\Requests\Assistencia;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do upload de arquivos/fotos de assistência.
 */
class UploadAssistenciaArquivoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Regras:
     * - Somente imagens (fotos) por padrão.
     * - Múltiplos arquivos: use o campo 'arquivos[]'.
     */
    public function rules(): array
    {
        return [
            'tipo'               => ['nullable', 'string', 'max:50'],
            'arquivos'           => ['required', 'array', 'min:1'],
            'arquivos.*'         => ['file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:8192'], // 8MB cada
        ];
    }

    public function messages(): array
    {
        return [
            'arquivos.required'  => 'Envie ao menos um arquivo.',
            'arquivos.*.mimetypes' => 'Apenas imagens JPEG, PNG ou WEBP são permitidas.',
            'arquivos.*.max'     => 'Cada arquivo pode ter no máximo 8MB.',
        ];
    }
}
