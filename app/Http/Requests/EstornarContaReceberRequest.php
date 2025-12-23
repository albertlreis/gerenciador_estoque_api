<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @phpstan-type EstornarContaReceberPayload array{
 *   motivo?: string|null
 * }
 *
 * @property-read EstornarContaReceberPayload $validated
 */
class EstornarContaReceberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }
}
