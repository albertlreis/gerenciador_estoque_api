<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ParceiroStoreRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nome'       => ['required', 'string', 'max:255'],
            'tipo'       => ['required', 'string', 'max:50'],
            'documento'  => ['nullable', 'string', 'max:50'], // pode ser CPF/CNPJ ou vazio
            'data_nascimento' => ['nullable', 'date'],
            'email'      => ['nullable', 'email', 'max:100'],
            'telefone'   => ['nullable', 'string', 'max:50'],
            'endereco'   => ['nullable', 'string'],
            'status'     => ['nullable', 'integer', 'in:0,1'],
            'observacoes'=> ['nullable', 'string'],
        ];
    }
}
