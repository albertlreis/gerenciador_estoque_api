<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    protected $fillable = [
        'nome',
        'descricao'
    ];

    public function produtos(): HasMany
    {
        return $this->hasMany(Produto::class, 'id_categoria');
    }

    public function subcategorias()
    {
        return $this->hasMany(Categoria::class, 'categoria_pai_id');
    }

    public function pai()
    {
        return $this->belongsTo(Categoria::class, 'categoria_pai_id');
    }

    public function subcategoriasRecursive()
    {
        return $this->hasMany(Categoria::class, 'categoria_pai_id')->with('subcategoriasRecursive');
    }

    public function allChildrenIds(&$ids = [])
    {
        foreach ($this->subcategoriasRecursive as $sub) {
            $ids[] = $sub->id;
            $sub->allChildrenIds($ids);
        }
        return $ids;
    }

    public static function expandirIdsComFilhos(array $ids): array
    {
        $categorias = self::with('subcategoriasRecursive')
            ->whereIn('id', $ids)
            ->get();

        $todosIds = [];

        foreach ($categorias as $cat) {
            $todosIds[] = $cat->id;
            $cat->allChildrenIds($todosIds);
        }

        return array_unique($todosIds);
    }
}
