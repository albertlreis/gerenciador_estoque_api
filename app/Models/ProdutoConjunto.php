<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProdutoConjunto extends Model
{
    protected $table = 'produto_conjuntos';

    protected $fillable = [
        'nome',
        'descricao',
        'hero_image_path',
        'preco_modo',
        'principal_variacao_id',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function itens(): HasMany
    {
        return $this->hasMany(ProdutoConjuntoItem::class, 'produto_conjunto_id')
            ->orderBy('ordem')
            ->orderBy('id');
    }

    public function principalVariacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'principal_variacao_id');
    }
}
