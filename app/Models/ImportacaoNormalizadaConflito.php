<?php

namespace App\Models;

use App\Enums\ImportacaoNormalizadaConflitoSeveridade;
use App\Enums\StatusRevisaoCadastro;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportacaoNormalizadaConflito extends Model
{
    protected $table = 'importacoes_normalizadas_conflitos';

    protected $fillable = [
        'importacao_id',
        'linha_id',
        'tipo',
        'campo',
        'severidade',
        'descricao',
        'valor_informado',
        'valor_calculado',
        'detalhes',
        'status_revisao',
        'decisao_manual',
        'motivo_decisao_manual',
        'resolvido_por',
        'resolvido_em',
    ];

    protected $casts = [
        'severidade' => ImportacaoNormalizadaConflitoSeveridade::class,
        'detalhes' => 'array',
        'status_revisao' => StatusRevisaoCadastro::class,
        'resolvido_em' => 'datetime',
    ];

    public function importacao(): BelongsTo
    {
        return $this->belongsTo(ImportacaoNormalizada::class, 'importacao_id');
    }

    public function linha(): BelongsTo
    {
        return $this->belongsTo(ImportacaoNormalizadaLinha::class, 'linha_id');
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'resolvido_por');
    }

    public function revisoes(): HasMany
    {
        return $this->hasMany(ImportacaoNormalizadaRevisao::class, 'conflito_id');
    }
}
