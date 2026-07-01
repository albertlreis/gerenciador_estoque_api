<?php

namespace App\Services;

use App\Support\Logging\SierraLog;

class LogService
{
    public static function debug(string $contexto, string $mensagem, array $dados = []): void
    {
        SierraLog::debug(self::eventName($contexto, $mensagem), ['module' => $contexto, 'message' => $mensagem] + $dados);
    }

    public static function info(string $contexto, string $mensagem, array $dados = []): void
    {
        SierraLog::info(self::eventName($contexto, $mensagem), ['module' => $contexto, 'message' => $mensagem] + $dados);
    }

    public static function warning(string $contexto, string $mensagem, array $dados = []): void
    {
        SierraLog::warning(self::eventName($contexto, $mensagem), ['module' => $contexto, 'message' => $mensagem] + $dados);
    }

    public static function error(string $contexto, string $mensagem, array $dados = []): void
    {
        SierraLog::error(self::eventName($contexto, $mensagem), ['module' => $contexto, 'message' => $mensagem] + $dados);
    }

    private static function eventName(string $contexto, string $mensagem): string
    {
        return SierraLog::normalizeEventName($contexto.'.'.$mensagem);
    }
}
