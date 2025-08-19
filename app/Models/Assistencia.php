<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $nome
 * @property string|null $cnpj
 * @property bool $ativo
 */
class Assistencia extends Model
{
    use SoftDeletes;

    protected $table = 'assistencias';

    protected $fillable = [
        'nome','cnpj','telefone','email','contato','endereco_json',
        'prazo_padrao_dias','ativo','observacoes'
    ];

    protected $casts = [
        'endereco_json' => 'array',
        'ativo' => 'boolean',
        'prazo_padrao_dias' => 'integer',
    ];

    /** Relacionamentos */
    public function chamados()
    {
        return $this->hasMany(AssistenciaChamado::class, 'assistencia_id');
    }
}
