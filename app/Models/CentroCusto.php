<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CentroCusto extends Model
{
    protected $table = 'centros_custo';

    protected $fillable = [
        'nome','slug','centro_custo_pai_id','ordem','ativo','padrao','meta_json'
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'padrao' => 'boolean',
        'ordem' => 'integer',
        'meta_json' => 'array',
    ];

    public function pai(): BelongsTo
    {
        return $this->belongsTo(self::class, 'centro_custo_pai_id');
    }

    public function filhas(): HasMany
    {
        return $this->hasMany(self::class, 'centro_custo_pai_id')
            ->orderBy('ordem')->orderBy('nome');
    }

    public function scopeAtivos($q)
    {
        return $q->where('ativo', true);
    }
}
