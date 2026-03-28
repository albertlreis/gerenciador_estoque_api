<?php

namespace App\Integrations\ContaAzul\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContaAzulImportBatch extends Model
{
    protected $table = 'conta_azul_import_batches';

    protected $fillable = [
        'loja_id',
        'conexao_id',
        'tipo_entidade',
        'status',
        'parametros_json',
        'total_lidos',
        'total_conciliados',
        'total_pendentes',
        'total_falhas',
        'iniciado_em',
        'finalizado_em',
        'resumo_json',
    ];

    protected $casts = [
        'parametros_json' => 'array',
        'resumo_json' => 'array',
        'iniciado_em' => 'datetime',
        'finalizado_em' => 'datetime',
    ];

    public function conexao(): BelongsTo
    {
        return $this->belongsTo(ContaAzulConexao::class, 'conexao_id');
    }
}
