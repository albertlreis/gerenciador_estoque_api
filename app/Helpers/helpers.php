<?php

use Illuminate\Support\Facades\DB;

function getConfig(string $chave, $default = null)
{
    return DB::table('configuracoes')->where('chave', $chave)->value('valor') ?? $default;
}
