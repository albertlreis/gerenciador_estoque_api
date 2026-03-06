<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoParticipante extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'evento_id',
        'user_id',
        'obrigatorio',
        'status_confirmacao',
        'created_at',
    ];

    protected $casts = [
        'obrigatorio' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}
