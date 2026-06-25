<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $deposito_id
 * @property string|null $area
 * @property string|null $corredor
 * @property string|null $setor
 * @property string|null $coluna
 * @property string $codigo_composto
 * @property string|null $observacoes
 * @property bool $ativo
 */
class LocalizacaoEstoque extends Model
{
    protected $table = 'localizacoes_estoque';

    protected $fillable = [
        'deposito_id',
        'area',
        'corredor',
        'setor',
        'coluna',
        'codigo_composto',
        'observacoes',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'deposito_id');
    }

    public function estoques(): HasMany
    {
        return $this->hasMany(Estoque::class, 'localizacao_id');
    }
}
