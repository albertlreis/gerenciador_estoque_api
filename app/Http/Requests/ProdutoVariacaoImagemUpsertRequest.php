<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProdutoVariacaoImagemUpsertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'imagem' => 'required|file|image|mimes:jpg,jpeg,png,webp,gif|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'imagem.required' => 'Selecione uma imagem para upload.',
            'imagem.image' => 'O arquivo enviado deve ser uma imagem.',
            'imagem.mimes' => 'Formatos permitidos: jpg, jpeg, png, webp, gif.',
            'imagem.max' => 'A imagem deve ter no máximo 5MB.',
        ];
    }
}
