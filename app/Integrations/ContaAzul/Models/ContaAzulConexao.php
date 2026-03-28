<?php

namespace App\Integrations\ContaAzul\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContaAzulConexao extends Model
{
    protected $table = 'conta_azul_conexoes';

    protected $fillable = [
        'loja_id',
        'status',
        'ambiente',
        'nome_externo',
        'observacoes',
        'ultimo_healthcheck_em',
        'ultimo_erro',
    ];

    protected $casts = [
        'ultimo_healthcheck_em' => 'datetime',
    ];

    public function token(): HasOne
    {
        return $this->hasOne(ContaAzulToken::class, 'conexao_id');
    }
}
