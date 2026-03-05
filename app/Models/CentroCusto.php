<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CentroCusto extends Model
{
    protected $table = 'centros_custo';

    protected $fillable = [
        'nome','slug','centro_custo_pai_id','ativo','padrao','meta_json'
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'padrao' => 'boolean',
        'meta_json' => 'array',
    ];

    public function pai(): BelongsTo
    {
        return $this->belongsTo(self::class, 'centro_custo_pai_id');
    }

    public function filhas(): HasMany
    {
        return $this->hasMany(self::class, 'centro_custo_pai_id')
            ->orderBy('nome');
    }

    public function scopeAtivos($q)
    {
        return $q->where('ativo', true);
    }
}
