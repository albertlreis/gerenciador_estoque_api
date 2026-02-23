<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvisoLeitura extends Model
{
    protected $table = 'aviso_leituras';

    protected $fillable = [
        'aviso_id',
        'usuario_id',
        'lido_em',
    ];

    protected $casts = [
        'lido_em' => 'datetime',
    ];

    public function aviso(): BelongsTo
    {
        return $this->belongsTo(Aviso::class, 'aviso_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}

