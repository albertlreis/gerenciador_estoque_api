<?php

namespace App\Integrations\ContaAzul\Models;

use Illuminate\Database\Eloquent\Model;

class ContaAzulMapeamento extends Model
{
    protected $table = 'conta_azul_mapeamentos';

    /**
     * Id externo Conta Azul já vinculado a um registro local (Sierra é fonte do id_local).
     */
    public static function idExternoPorLocal(string $tipoEntidade, int $idLocal, ?int $lojaId = null): ?string
    {
        $q = static::query()
            ->where('tipo_entidade', $tipoEntidade)
            ->where('id_local', $idLocal);
        if ($lojaId !== null) {
            $q->where('loja_id', $lojaId);
        }

        return $q->value('id_externo');
    }

    protected $fillable = [
        'loja_id',
        'tipo_entidade',
        'id_local',
        'id_externo',
        'codigo_externo',
        'origem_inicial',
        'hash_payload_local',
        'hash_payload_externo',
        'sincronizado_em',
        'metadata_json',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'sincronizado_em' => 'datetime',
    ];
}
