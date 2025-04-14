<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class AcessoUsuario extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'acesso_usuarios';

    protected $fillable = [
        'nome', 'email', 'senha', 'ativo'
    ];

    protected $hidden = [
        'senha',
    ];

    public function perfis()
    {
        return $this->belongsToMany(AcessoPerfil::class, 'acesso_usuario_perfil', 'id_usuario', 'id_perfil')
            ->withTimestamps();
    }
}
