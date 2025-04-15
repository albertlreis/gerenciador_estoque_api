<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Estoque extends Model
{
    protected $table = 'estoque';
    protected $fillable = [
        'id_produto',
        'id_deposito',
        'quantidade'
    ];

    public function produto()
    {
        return $this->belongsTo(Produto::class, 'id_produto');
    }

    // E a um depósito
    public function deposito()
    {
        return $this->belongsTo(Deposito::class, 'id_deposito');
    }
}
