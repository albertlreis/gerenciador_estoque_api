<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProdutoConjuntoHeroUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Selecione uma imagem para upload.',
            'file.image' => 'O arquivo enviado deve ser uma imagem.',
            'file.mimes' => 'Formatos permitidos: jpg, jpeg, png e webp.',
            'file.max' => 'A imagem deve ter no máximo 5MB.',
        ];
    }
}
