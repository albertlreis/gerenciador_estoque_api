<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @phpstan-type CategoriaUpdatePayload array{
 *   nome?: string,
 *   descricao?: string|null,
 *   categoria_pai_id?: int|null
 * }
 *
 * @property-read CategoriaUpdatePayload $validated
 */
class CategoriaUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nome'            => ['sometimes', 'required', 'string', 'max:255'],
            'descricao'       => ['nullable', 'string'],
            'categoria_pai_id'=> ['nullable', 'integer', 'exists:categorias,id'],
        ];
    }
}
