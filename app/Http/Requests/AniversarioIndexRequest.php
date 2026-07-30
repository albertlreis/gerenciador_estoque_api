<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AniversarioIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'escopo' => ['nullable', 'in:dia,semana,mes'],
            'mes' => ['nullable', 'integer', 'between:1,12'],
        ];
    }
}
