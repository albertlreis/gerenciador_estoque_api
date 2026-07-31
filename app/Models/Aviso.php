<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Aviso extends Model
{
    protected $fillable = [
        'titulo',
        'conteudo',
        'ativo',
        'data_inicio',
        'data_fim',
        'criado_por',
        'status',
        'prioridade',
        'pinned',
        'publicar_em',
        'expirar_em',
        'criado_por_usuario_id',
        'atualizado_por_usuario_id',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'data_inicio' => 'datetime',
        'data_fim' => 'datetime',
        'pinned' => 'boolean',
        'publicar_em' => 'datetime',
        'expirar_em' => 'datetime',
    ];

    public function scopeAtivos(Builder $query): Builder
    {
        return $query
            ->where('status', 'publicado')
            ->where(function (Builder $builder): void {
                $builder->whereNull('publicar_em')->orWhere('publicar_em', '<=', now());
            })
            ->where(function (Builder $builder): void {
                $builder->whereNull('expirar_em')->orWhere('expirar_em', '>', now());
            });
    }

    public function leituras(): HasMany
    {
        return $this->hasMany(AvisoLeitura::class, 'aviso_id');
    }
}
