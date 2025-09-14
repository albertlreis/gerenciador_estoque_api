<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Representa um "tipo" de dimensão opcional para localizações (ex.: "Corredor", "Prateleira").
 *
 * @property int $id
 * @property string $nome
 * @property string|null $placeholder
 * @property int $ordem
 * @property bool $ativo
 */
class LocalizacaoDimensao extends Model
{
    protected $table = 'localizacao_dimensoes';

    protected $fillable = ['nome', 'placeholder', 'ordem', 'ativo'];

    protected $casts = [
        'ativo' => 'boolean',
        'ordem' => 'integer',
    ];
}
