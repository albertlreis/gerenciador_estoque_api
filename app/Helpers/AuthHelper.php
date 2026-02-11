<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AuthHelper
{
    /**
     * Verifica se o usuário tem permissão
     */
    public static function hasPermissao(string $slug): bool
    {
        if (!auth()->check()) {
            return false;
        }

        $permissoes = self::getPermissoes();

        return in_array($slug, $permissoes);
    }

    /**
     * Retorna o ID do usuário logado.
     */
    public static function getUsuarioId(): ?int
    {
        return auth()->check() ? auth()->id() : null;
    }

    /**
     * Retorna o perfil do usuário logado.
     */
    public static function getPerfil(): ?string
    {
        $perfis = self::getPerfis();
        return $perfis[0] ?? null;
    }

    /**
     * Retorna o usuário logado como array, se necessário.
     */
    public static function getUsuario(): array
    {
        return auth()->check() ? auth()->user()->toArray() : [];
    }

    /**
     * Retorna lista de permissões do usuário (cache + fallback em banco).
     */
    public static function getPermissoes(): array
    {
        if (!auth()->check()) {
            return [];
        }

        $userId = auth()->id();
        $cacheKey = 'permissoes_usuario_' . $userId;

        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey, []);
            return is_array($cached) ? $cached : [];
        }

        $permissoes = DB::table('acesso_usuario_perfil')
            ->join('acesso_perfil_permissao', 'acesso_usuario_perfil.id_perfil', '=', 'acesso_perfil_permissao.id_perfil')
            ->join('acesso_permissoes', 'acesso_perfil_permissao.id_permissao', '=', 'acesso_permissoes.id')
            ->where('acesso_usuario_perfil.id_usuario', $userId)
            ->pluck('acesso_permissoes.slug')
            ->unique()
            ->values()
            ->all();

        Cache::put($cacheKey, $permissoes, now()->addHours(6));

        return $permissoes;
    }

    /**
     * Retorna lista de perfis do usuário (cache + fallback em banco).
     */
    public static function getPerfis(): array
    {
        if (!auth()->check()) {
            return [];
        }

        $userId = auth()->id();
        $cacheKey = 'perfis_usuario_' . $userId;

        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey, []);
            return is_array($cached) ? $cached : [];
        }

        $perfis = DB::table('acesso_usuario_perfil')
            ->join('acesso_perfis', 'acesso_usuario_perfil.id_perfil', '=', 'acesso_perfis.id')
            ->where('acesso_usuario_perfil.id_usuario', $userId)
            ->pluck('acesso_perfis.nome')
            ->unique()
            ->values()
            ->all();

        Cache::put($cacheKey, $perfis, now()->addHours(6));

        return $perfis;
    }

    /**
     * Verifica se o usuário possui algum dos perfis informados.
     */
    public static function hasPerfil(string|array $perfis): bool
    {
        $lista = self::getPerfis();
        $perfis = is_array($perfis) ? $perfis : [$perfis];

        foreach ($perfis as $perfil) {
            if (in_array($perfil, $lista, true)) {
                return true;
            }
        }

        return false;
    }
}
