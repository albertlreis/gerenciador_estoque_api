<?php

namespace App\Http\Requests;

use App\Support\Dates\BirthdayDateNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class StoreClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'data_nascimento' => BirthdayDateNormalizer::normalize($this->input('data_nascimento')),
        ]);
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
            'bloqueia_email' => ['sometimes', 'boolean'],
            'telefone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'data_nascimento' => ['nullable', 'date_format:Y-m-d'],

            'consentimentos' => ['sometimes', 'array', 'max:2'],
            'consentimentos.*.canal' => ['required', 'in:sms,whatsapp', 'distinct'],
            'consentimentos.*.situacao' => ['required', 'in:concedido,revogado'],
            'consentimentos.*.origem' => ['required', 'string', 'max:80'],
            'consentimentos.*.decidido_em' => ['required', 'date'],
            'consentimentos.*.referencia_evidencia' => ['nullable', 'string', 'max:190'],

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
