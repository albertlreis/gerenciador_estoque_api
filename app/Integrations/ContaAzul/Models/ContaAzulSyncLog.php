<?php

namespace App\Integrations\ContaAzul\Models;

use Illuminate\Database\Eloquent\Model;

class ContaAzulSyncLog extends Model
{
    protected $table = 'conta_azul_sync_logs';

    protected $fillable = [
        'loja_id',
        'tipo_entidade',
        'id_local',
        'id_externo',
        'direcao',
        'status',
        'tentativa',
        'payload_resumo',
        'resposta_resumo',
        'erro_codigo',
        'erro_mensagem',
        'executado_em',
    ];

    protected $casts = [
        'executado_em' => 'datetime',
    ];
}
