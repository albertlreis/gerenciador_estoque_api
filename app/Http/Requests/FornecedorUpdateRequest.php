<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FornecedorUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nome'        => ['sometimes','required','string','max:255'],
            'cnpj'        => ['sometimes','nullable','string','max:20'],
            'email'       => ['sometimes','nullable','email','max:150'],
            'telefone'    => ['sometimes','nullable','string','max:30'],
            'endereco'    => ['sometimes','nullable','string','max:255'],
            'status'      => ['sometimes','nullable','in:0,1'],
            'observacoes' => ['sometimes','nullable','string'],
        ];
    }
}
