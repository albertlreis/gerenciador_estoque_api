<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @phpstan-type CategoriaStorePayload array{
 *   nome: string,
 *   descricao?: string|null,
 *   categoria_pai_id?: int|null
 * }
 *
 * @property-read CategoriaStorePayload $validated
 */
class CategoriaStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nome'            => ['required', 'string', 'max:255'],
            'descricao'       => ['nullable', 'string'],
            'categoria_pai_id'=> ['nullable', 'integer', 'exists:categorias,id'],
        ];
    }
}
