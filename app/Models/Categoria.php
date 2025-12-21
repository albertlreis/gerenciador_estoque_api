<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $nome
 * @property string|null $descricao
 * @property int|null $categoria_pai_id
 */
class Categoria extends Model
{
    protected $table = 'categorias';

    protected $fillable = [
        'nome',
        'descricao',
        'categoria_pai_id',
    ];

    /** @return HasMany<Produto> */
    public function produtos(): HasMany
    {
        return $this->hasMany(Produto::class, 'id_categoria');
    }

    /** @return HasMany<Categoria> */
    public function subcategorias(): HasMany
    {
        return $this->hasMany(Categoria::class, 'categoria_pai_id');
    }

    /** @return BelongsTo<Categoria, Categoria> */
    public function pai(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_pai_id');
    }

    /** @return HasMany<Categoria> */
    public function subcategoriasRecursive(): HasMany
    {
        return $this->hasMany(Categoria::class, 'categoria_pai_id')
            ->with('subcategoriasRecursive');
    }

    /**
     * @param array<int> $ids
     * @return array<int>
     */
    public function allChildrenIds(array &$ids = []): array
    {
        foreach ($this->subcategoriasRecursive as $sub) {
            $ids[] = (int)$sub->id;
            $sub->allChildrenIds($ids);
        }
        return $ids;
    }

    /**
     * @param array<int> $ids
     * @return array<int>
     */
    public static function expandirIdsComFilhos(array $ids): array
    {
        $categorias = self::with('subcategoriasRecursive')
            ->whereIn('id', $ids)
            ->get();

        $todosIds = [];

        foreach ($categorias as $cat) {
            $todosIds[] = (int)$cat->id;
            $cat->allChildrenIds($todosIds);
        }

        return array_values(array_unique($todosIds));
    }
}
