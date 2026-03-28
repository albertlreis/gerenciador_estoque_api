<?php

namespace App\Models;

use App\Enums\ImportacaoNormalizadaStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportacaoNormalizada extends Model
{
    protected $table = 'importacoes_normalizadas';

    protected $fillable = [
        'tipo',
        'arquivo_nome',
        'arquivo_hash',
        'usuario_id',
        'status',
        'abas_processadas',
        'linhas_total',
        'linhas_staged',
        'linhas_com_conflito',
        'linhas_pendentes_revisao',
        'linhas_com_erro',
        'metricas',
        'preview_resumo',
        'relatorio_final',
        'confirmado_em',
        'confirmado_por',
        'efetivado_em',
        'efetivado_por',
        'chave_execucao',
        'observacoes',
    ];

    protected $casts = [
        'status' => ImportacaoNormalizadaStatus::class,
        'abas_processadas' => 'array',
        'metricas' => 'array',
        'preview_resumo' => 'array',
        'relatorio_final' => 'array',
        'confirmado_em' => 'datetime',
        'efetivado_em' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'confirmado_por');
    }

    public function efetivadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'efetivado_por');
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(ImportacaoNormalizadaLinha::class, 'importacao_id');
    }

    public function conflitos(): HasMany
    {
        return $this->hasMany(ImportacaoNormalizadaConflito::class, 'importacao_id');
    }

    public function revisoes(): HasMany
    {
        return $this->hasMany(ImportacaoNormalizadaRevisao::class, 'importacao_id');
    }
}
