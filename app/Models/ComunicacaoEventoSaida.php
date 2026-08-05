<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComunicacaoEventoSaida extends Model
{
    protected $table = 'comunicacao_eventos_saida';

    protected $fillable = [
        'jornada_id', 'cliente_id', 'origem_tipo', 'origem_id', 'evento_codigo', 'canal',
        'template_codigo', 'destinatario', 'variaveis', 'idempotency_key',
        'correlation_id', 'status', 'tentativas', 'disponivel_em',
        'processado_em', 'erro_codigo', 'erro_mensagem',
    ];

    protected $casts = [
        'variaveis' => 'array',
        'tentativas' => 'integer',
        'disponivel_em' => 'datetime',
        'processado_em' => 'datetime',
    ];

    public function jornada(): BelongsTo
    {
        return $this->belongsTo(ComunicacaoJornada::class, 'jornada_id');
    }
}
