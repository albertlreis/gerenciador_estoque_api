<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProdutoVariacaoCodigoHistorico extends Model
{
    protected $table = 'produto_variacao_codigos_historicos';

    protected $fillable = [
        'produto_variacao_id',
        'codigo',
        'codigo_origem',
        'codigo_modelo',
        'hash_conteudo',
        'fonte',
        'aba_origem',
        'observacoes',
        'principal',
    ];

    protected $casts = [
        'principal' => 'boolean',
    ];

    public function variacao(): BelongsTo
    {
        return $this->belongsTo(ProdutoVariacao::class, 'produto_variacao_id');
    }
}
