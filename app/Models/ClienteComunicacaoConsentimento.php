<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClienteComunicacaoConsentimento extends Model
{
    protected $table = 'cliente_comunicacao_consentimentos';

    protected $fillable = [
        'cliente_id', 'canal', 'situacao', 'origem', 'decidido_em',
        'responsavel_id', 'referencia_evidencia',
    ];

    protected $casts = ['decidido_em' => 'datetime'];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }
}
