<?php

namespace App\Integrations\ContaAzul\Models;

use Illuminate\Database\Eloquent\Model;

class ContaAzulReconciliationState extends Model
{
    protected $table = 'conta_azul_reconciliation_states';

    protected $fillable = [
        'loja_id',
        'recurso',
        'ultimo_cursor',
        'ultima_data_consulta',
        'ultima_execucao_em',
        'status',
    ];

    protected $casts = [
        'ultima_data_consulta' => 'datetime',
        'ultima_execucao_em' => 'datetime',
    ];
}
