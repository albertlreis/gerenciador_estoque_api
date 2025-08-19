<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catálogo de defeitos de assistência.
 */
class AssistenciaDefeito extends Model
{
    use SoftDeletes;

    protected $table = 'assistencia_defeitos';

    protected $fillable = ['codigo','descricao','critico','ativo'];

    protected $casts = [
        'critico' => 'boolean',
        'ativo' => 'boolean',
    ];

    public function itens()
    {
        return $this->hasMany(AssistenciaChamadoItem::class, 'defeito_id');
    }
}
