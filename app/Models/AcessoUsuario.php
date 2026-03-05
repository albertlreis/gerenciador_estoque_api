<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class AcessoUsuario extends Authenticatable
{
    use HasApiTokens, Notifiable, HasFactory;

    protected $table = 'acesso_usuarios';

    protected $fillable = [
        'nome', 'email', 'senha', 'ativo'
    ];

    protected $hidden = [
        'senha',
    ];

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'id_usuario');
    }
}
