<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ParceiroUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'nome'       => ['sometimes', 'required', 'string', 'max:255'],
            'tipo'       => ['sometimes', 'required', 'string', 'max:50'],
            'documento'  => ['sometimes', 'nullable', 'string', 'max:50'],
            'email'      => ['sometimes', 'nullable', 'email', 'max:100'],
            'telefone'   => ['sometimes', 'nullable', 'string', 'max:50'],
            'endereco'   => ['sometimes', 'nullable', 'string'],
            'status'     => ['sometimes', 'nullable', 'integer', 'in:0,1'],
            'observacoes'=> ['sometimes', 'nullable', 'string'],
        ];
    }
}
