<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fornecedor ou fabricante de produtos.
 */
class Fornecedor extends Model
{
    protected $fillable = ['nome', 'cnpj', 'email', 'telefone', 'endereco'];

    public function produtos(): HasMany
    {
        return $this->hasMany(Produto::class, 'id_fornecedor');
    }
}
