<?php

namespace App\Http\Requests;

use App\Helpers\AuthHelper;
use Illuminate\Foundation\Http\FormRequest;

class ImportEstoqueUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AuthHelper::podeImportarEstoquePlanilhaDev();
    }

    public function rules(): array
    {
        return [
            'arquivo' => ['required','file','mimes:xlsx,xls'],
        ];
    }
}
