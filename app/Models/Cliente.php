<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Cliente extends Model
{
    protected $fillable = [
        'nome',
        'nome_fantasia',
        'documento',
        'inscricao_estadual',
        'email',
        'telefone',
        'tipo',
        'whatsapp',
        'data_nascimento',
    ];

    protected $casts = [
        'data_nascimento' => 'date',
    ];

    public function enderecos(): HasMany
    {
        return $this->hasMany(ClienteEndereco::class, 'cliente_id');
    }

    public function enderecoPrincipal(): HasOne
    {
        return $this->hasOne(ClienteEndereco::class, 'cliente_id')->where('principal', true);
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'id_cliente');
    }
}
