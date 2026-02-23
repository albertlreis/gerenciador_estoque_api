<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Aviso extends Model
{
    protected $table = 'avisos';

    protected $fillable = [
        'titulo',
        'conteudo',
        'status',
        'prioridade',
        'pinned',
        'publicar_em',
        'expirar_em',
        'criado_por_usuario_id',
        'atualizado_por_usuario_id',
    ];

    protected $casts = [
        'pinned' => 'boolean',
        'publicar_em' => 'datetime',
        'expirar_em' => 'datetime',
    ];

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'criado_por_usuario_id');
    }

    public function atualizadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'atualizado_por_usuario_id');
    }

    public function leituras(): HasMany
    {
        return $this->hasMany(AvisoLeitura::class, 'aviso_id');
    }

    public function scopeAtivos(Builder $query): Builder
    {
        return $query
            ->where('status', 'publicado')
            ->where(function (Builder $sub) {
                $sub->whereNull('publicar_em')
                    ->orWhere('publicar_em', '<=', now());
            })
            ->where(function (Builder $sub) {
                $sub->whereNull('expirar_em')
                    ->orWhere('expirar_em', '>', now());
            });
    }
}

