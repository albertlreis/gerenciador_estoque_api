<?php

namespace App\Http\Requests\Financeiro;

use Illuminate\Foundation\Http\FormRequest;

class ConciliacaoBancariaOfxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'conta_financeira_id' => ['required', 'integer', 'exists:contas_financeiras,id'],
            'arquivo' => ['required', 'file', 'max:4096'],
        ];
    }
}
