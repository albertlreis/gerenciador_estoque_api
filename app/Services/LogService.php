<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class LogService
{
    public static function debug(string $contexto, string $mensagem, array $dados = []): void
    {
        Log::debug("[$contexto] $mensagem", $dados);
    }

    public static function info(string $contexto, string $mensagem, array $dados = []): void
    {
        Log::info("[$contexto] $mensagem", $dados);
    }

    public static function warning(string $contexto, string $mensagem, array $dados = []): void
    {
        Log::warning("[$contexto] $mensagem", $dados);
    }

    public static function error(string $contexto, string $mensagem, array $dados = []): void
    {
        Log::error("[$contexto] $mensagem", $dados);
    }
}
