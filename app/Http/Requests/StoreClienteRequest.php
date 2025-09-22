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
            'nome' => 'required|string|max:255',
            'nome_fantasia' => 'nullable|string|max:255',
            'documento' => 'nullable|string|max:50',
            'inscricao_estadual' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'telefone' => 'nullable|string|max:50',
            'whatsapp' => 'nullable|string|max:20',
            'cep' => 'nullable|string|max:20',
            'endereco' => 'nullable|string|max:255',
            'numero' => 'nullable|string|max:20',
            'bairro' => 'nullable|string|max:100',
            'cidade' => 'nullable|string|max:100',
            'estado' => 'nullable|string|max:2',
            'complemento' => 'nullable|string|max:255',
            'tipo' => 'required|in:pf,pj'
        ];
    }
}
