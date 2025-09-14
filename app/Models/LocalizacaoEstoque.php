<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Localização essencial + extensível:
 * - Essencial: setor, coluna, nivel, area_id, codigo_composto.
 * - Extensível: localizacao_valores (chave/valor por dimensao configurável).
 *
 * @property int $id
 * @property int $estoque_id
 * @property string|null $setor
 * @property string|null $coluna
 * @property string|null $nivel
 * @property int|null $area_id
 * @property string|null $codigo_composto
 * @property string|null $observacoes
 */
class LocalizacaoEstoque extends Model
{
    protected $table = 'localizacoes_estoque';

    protected $fillable = [
        'estoque_id',
        'setor',
        'coluna',
        'nivel',
        'area_id',
        'codigo_composto',
        'observacoes',
    ];

    public function estoque(): BelongsTo
    {
        return $this->belongsTo(Estoque::class, 'estoque_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(AreaEstoque::class, 'area_id');
    }

    public function valores(): HasMany
    {
        return $this->hasMany(LocalizacaoValor::class, 'localizacao_id');
    }
}
