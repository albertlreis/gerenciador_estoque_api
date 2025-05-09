<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'acesso_usuarios';

    protected $fillable = [
        'nome', 'email', 'senha', 'ativo'
    ];

    protected $hidden = [
        'senha',
    ];
}
