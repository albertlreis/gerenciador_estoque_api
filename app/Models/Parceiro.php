<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'consultor_nome',
        'nivel_fidelidade',
        'data_nascimento',
        'endereco',
        'status',
        'observacoes',
    ];

    protected $casts = [
        'status' => 'integer',
        'data_nascimento' => 'date',
    ];

    /** Normaliza documento (CPF/CNPJ) para dígitos */
    public function setDocumentoAttribute(?string $value): void
    {
        $digits = $value ? preg_replace('/\D+/', '', $value) : null;
        $this->attributes['documento'] = $digits ?: null;
    }

    public function contatos(): HasMany
    {
        return $this->hasMany(ParceiroContato::class, 'parceiro_id');
    }
}
