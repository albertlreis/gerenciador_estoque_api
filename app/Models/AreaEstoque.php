<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $nome
 * @property string|null $descricao
 */
class AreaEstoque extends Model
{
    protected $table = 'areas_estoque';

    protected $fillable = ['nome', 'descricao'];
}
