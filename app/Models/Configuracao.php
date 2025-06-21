<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Configuracao extends Model
{
    protected $table = 'configuracoes';

    protected $fillable = [
        'chave',
        'label',
        'valor',
        'descricao',
        'tipo',
    ];

    public $timestamps = true;

    public static function pegarTodosComoArray(): array
    {
        return self::pluck('valor', 'chave')->mapWithKeys(function ($valor, $chave) {
            return [$chave => is_numeric($valor) ? (int) $valor : $valor];
        })->toArray();
    }
}
