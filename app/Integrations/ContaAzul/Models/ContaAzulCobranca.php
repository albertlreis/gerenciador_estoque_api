<?php

namespace App\Integrations\ContaAzul\Models;

use App\Models\ContaReceber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContaAzulCobranca extends Model
{
    protected $table = 'conta_azul_cobrancas';

    protected $fillable = [
        'conta_receber_id',
        'loja_id',
        'tipo',
        'status',
        'id_externo',
        'url',
        'linha_digitavel',
        'codigo_barras',
        'payload_json',
        'response_json',
        'payload_resumo',
        'resposta_resumo',
        'erro_codigo',
        'erro_mensagem',
        'emitida_em',
        'ultima_tentativa_em',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'response_json' => 'array',
        'emitida_em' => 'datetime',
        'ultima_tentativa_em' => 'datetime',
    ];

    public function contaReceber(): BelongsTo
    {
        return $this->belongsTo(ContaReceber::class, 'conta_receber_id');
    }
}
