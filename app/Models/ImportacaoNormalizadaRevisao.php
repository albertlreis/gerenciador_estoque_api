<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportacaoNormalizadaRevisao extends Model
{
    protected $table = 'importacoes_normalizadas_revisoes';

    protected $fillable = [
        'importacao_id',
        'linha_id',
        'conflito_id',
        'produto_id',
        'variacao_id',
        'status_anterior',
        'status_novo',
        'decisao',
        'motivo',
        'detalhes',
        'usuario_id',
    ];

    protected $casts = [
        'detalhes' => 'array',
    ];

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(ImportacaoNormalizada::class, 'importacao_id');
    }

    public function linha(): BelongsTo
    {
        return $this->belongsTo(ImportacaoNormalizadaLinha::class, 'linha_id');
    }

    public function conflito(): BelongsTo
    {
        return $this->belongsTo(ImportacaoNormalizadaConflito::class, 'conflito_id');
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'variacao_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
