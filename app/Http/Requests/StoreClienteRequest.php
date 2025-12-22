<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', 'in:pf,pj'],
            'nome' => ['required', 'string', 'max:255'],
            'nome_fantasia' => ['nullable', 'string', 'max:255'],
            'documento' => ['nullable', 'string', 'max:30'],
            'inscricao_estadual' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],

            'enderecos' => ['nullable', 'array', 'min:1'],
            'enderecos.*.cep' => ['nullable', 'string', 'max:10'],
            'enderecos.*.endereco' => ['nullable', 'string', 'max:255'],
            'enderecos.*.numero' => ['nullable', 'string', 'max:50'],
            'enderecos.*.complemento' => ['nullable', 'string', 'max:255'],
            'enderecos.*.bairro' => ['nullable', 'string', 'max:120'],
            'enderecos.*.cidade' => ['nullable', 'string', 'max:120'],
            'enderecos.*.estado' => ['nullable', 'string', 'size:2'],
            'enderecos.*.principal' => ['nullable', 'boolean'],
        ];
    }
}
