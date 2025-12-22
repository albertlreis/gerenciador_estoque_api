<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClienteEndereco extends Model
{
    protected $table = 'cliente_enderecos';

    protected $fillable = [
        'cliente_id','cep','endereco','numero','complemento',
        'bairro','cidade','estado','principal','fingerprint',
    ];

    protected $casts = [
        'principal' => 'bool',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }
}
