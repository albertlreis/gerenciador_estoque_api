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
            'imagem' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:5120',
        ];
    }
}
