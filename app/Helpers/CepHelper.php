<?php

namespace App\Helpers;

use Exception;
use Illuminate\Support\Facades\Cache;

class CepHelper
{
    public static function cepEhValido(string $cep): bool
    {
        $cepLimpo = preg_replace('/\D/', '', $cep);

        if (strlen($cepLimpo) !== 8) return false;

        // Usa o cache por 12 horas
        return Cache::remember("cep_valido_{$cepLimpo}", now()->addHours(12), function () use ($cepLimpo) {
            try {
                $response = file_get_contents("https://viacep.com.br/ws/{$cepLimpo}/json/");
                $data = json_decode($response, true);
                return !isset($data['erro']);
            } catch (Exception $e) {
                return false;
            }
        });
    }

    public static function buscarCep(string $cep): ?array
    {
        $cepLimpo = preg_replace('/\D/', '', $cep);

        if (strlen($cepLimpo) !== 8) return null;

        return Cache::remember("cep_info_{$cepLimpo}", now()->addHours(12), function () use ($cepLimpo) {
            try {
                $response = file_get_contents("https://viacep.com.br/ws/{$cepLimpo}/json/");
                $data = json_decode($response, true);
                return isset($data['erro']) ? null : $data;
            } catch (\Exception $e) {
                return null;
            }
        });
    }
}
