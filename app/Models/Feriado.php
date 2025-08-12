<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Feriado extends Model
{
    protected $table = 'feriados';
    protected $fillable = ['data','nome','escopo','uf','fonte','ano'];

    protected $casts = [
        'data' => 'date',
    ];

    public function scopeAno(Builder $q, int $ano): Builder
    {
        return $q->where('ano', $ano);
    }

    public function scopeUf(Builder $q, ?string $uf): Builder
    {
        return $uf ? $q->where('uf', $uf) : $q;
    }

    public function scopeNacionais(Builder $q): Builder
    {
        return $q->where('escopo', 'nacional');
    }

    public function scopeEstaduais(Builder $q): Builder
    {
        return $q->where('escopo', 'estadual');
    }
}
