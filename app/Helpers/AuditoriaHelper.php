<?php

use Spatie\Activitylog\Models\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

if (!function_exists('logAuditoria')) {
    /**
     * Registra um log de auditoria padronizado.
     *
     * @param string $logName Nome do canal de log (ex: 'pedido', 'pedido_status').
     * @param string $descricao Texto descritivo (ex: "Pedido criado com sucesso").
     * @param array $properties Propriedades adicionais (ex: ['acao' => 'criação', 'nivel' => 'info']).
     * @param Model|null $model (Opcional) Modelo relacionado à ação.
     * @return Activity|null
     */
    function logAuditoria(string $logName, string $descricao, array $properties = [], ?Model $model = null): ?Activity
    {
        $usuario = Auth::user();

        $activity = activity($logName)
            ->causedBy($usuario)
            ->withProperties(array_merge([
                'usuario' => $usuario?->email,
            ], $properties));

        if ($model) {
            $activity->performedOn($model);
        }

        return $activity->log($descricao);
    }
}
