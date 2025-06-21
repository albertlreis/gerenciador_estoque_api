<?php

namespace App\Helpers;

use App\Models\Configuracao;

class ConfiguracaoHelper
{
    public static function prazos(): array
    {
        $chaves = [
            'prazo_envio_fabrica',
            'prazo_entrega_estoque',
            'prazo_envio_cliente',
            'prazo_consignacao',
            'dias_previsao_embarque_fabrica',
        ];

        return Configuracao::whereIn('chave', $chaves)
            ->pluck('valor', 'chave')
            ->mapWithKeys(fn($valor, $chave) => [$chave => (int)$valor])
            ->toArray();
    }
}
