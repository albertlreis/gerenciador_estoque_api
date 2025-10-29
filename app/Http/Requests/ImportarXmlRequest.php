<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportarXmlRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'arquivo' => ['required', 'file', 'mimes:xml'],
        ];
    }
}
