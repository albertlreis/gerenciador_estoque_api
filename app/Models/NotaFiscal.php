<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotaFiscal extends Model
{
    protected $table = 'notas_fiscais';

    protected $fillable = [
        'loja_id',
        'chave_acesso',
        'numero_nota',
        'status',
        'data_emissao',
        'nome_destinatario',
        'documento_local_type',
        'documento_local_id',
        'origem',
        'payload_json',
    ];

    protected $casts = [
        'data_emissao' => 'datetime',
        'payload_json' => 'array',
    ];
}
