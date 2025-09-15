<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FornecedorStoreRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nome'        => ['required','string','max:255'],
            'cnpj'        => ['nullable','string','max:20'], // já normalizamos para dígitos
            'email'       => ['nullable','email','max:150'],
            'telefone'    => ['nullable','string','max:30'],
            'endereco'    => ['nullable','string','max:255'],
            'status'      => ['nullable','in:0,1'],
            'observacoes' => ['nullable','string'],
        ];
    }
}
