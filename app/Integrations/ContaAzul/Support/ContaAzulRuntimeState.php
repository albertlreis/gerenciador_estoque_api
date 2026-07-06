<?php

namespace App\Integrations\ContaAzul\Support;

final class ContaAzulRuntimeState
{
    public const AUTO_FINANCE_IMPORT_LOCK_KEY = 'conta_azul:financeiro_auto_import:lock';
    public const EXPORTS_PAUSED_CACHE_KEY = 'conta_azul:exports_paused';
}
