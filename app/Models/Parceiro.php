<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Parceiro extends Model
{
    use SoftDeletes;

    protected $table = 'parceiros';

    protected $fillable = [
        'nome',
        'tipo',
        'documento',
        'email',
        'telefone',
        'endereco',
        'status',
        'observacoes',
    ];

    protected $casts = [
        'status' => 'integer',
    ];

    /** Normaliza documento (CPF/CNPJ) para dígitos */
    public function setDocumentoAttribute(?string $value): void
    {
        $digits = $value ? preg_replace('/\D+/', '', $value) : null;
        $this->attributes['documento'] = $digits ?: null;
    }
}
