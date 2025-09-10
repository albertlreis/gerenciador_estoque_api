<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assistencia extends Model
{
    use SoftDeletes;

    protected $table = 'assistencias';

    protected $fillable = [
        'nome',
        'cnpj',
        'telefone',
        'email',
        'contato',
        'endereco_json',
        'ativo',
        'observacoes',
    ];

    protected $casts = [
        'endereco_json' => 'array',
        'ativo' => 'boolean',
    ];

    /** Relacionamentos */
    public function chamados()
    {
        return $this->hasMany(AssistenciaChamado::class, 'assistencia_id');
    }
}
