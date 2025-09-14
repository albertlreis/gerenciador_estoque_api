<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Valor de uma dimensão para uma localização específica.
 *
 * @property int $id
 * @property int $localizacao_id
 * @property int $dimensao_id
 * @property string|null $valor
 */
class LocalizacaoValor extends Model
{
    protected $table = 'localizacao_valores';

    protected $fillable = ['localizacao_id', 'dimensao_id', 'valor'];

    public function dimensao(): BelongsTo
    {
        return $this->belongsTo(LocalizacaoDimensao::class, 'dimensao_id');
    }
}
