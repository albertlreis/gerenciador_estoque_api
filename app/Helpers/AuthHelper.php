<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

        $permissoes = Cache::get('permissoes_usuario_' . auth()->id(), []);

        return in_array($slug, $permissoes);
    }

    /**
     * Regra central para exibir preco de custo em pedidos.
     * Admin/Estoque devem ver custo; vendedor nao deve.
     * Mantem compatibilidade com permissoes legadas.
     */
    public static function podeVerCustoPedido(): bool
    {
        $slugs = [
            'pedidos.ver_custo',
            'produtos.gerenciar',
            'estoque.movimentacao',
            'estoque.movimentar',
        ];

        foreach ($slugs as $slug) {
            if (self::hasPermissao($slug)) {
                return true;
            }
        }

        return false;
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
        return auth()->check() ? auth()->user()->perfil : null;
    }

    /**
     * Retorna o usuário logado como array, se necessário.
     */
    public static function getUsuario(): array
    {
        return auth()->check() ? auth()->user()->toArray() : [];
    }

    /**
     * Regra central para acesso DEV na importacao de estoque por planilha.
     * Permite via permissao explicita ou via perfil Desenvolvedor.
     */
    public static function podeImportarEstoquePlanilhaDev(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        if (self::hasPermissao('estoque.importar_planilha_dev')) {
            return true;
        }

        $usuarioId = (int) auth()->id();
        $cacheKey = "usuario_{$usuarioId}_is_dev";

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($usuarioId) {
            if (!Schema::hasTable('acesso_usuario_perfil') || !Schema::hasTable('acesso_perfis')) {
                return false;
            }

            $perfil = DB::table('acesso_usuario_perfil as up')
                ->join('acesso_perfis as p', 'p.id', '=', 'up.id_perfil')
                ->where('up.id_usuario', $usuarioId)
                ->value('p.nome');

            return is_string($perfil) && Str::lower($perfil) === Str::lower('Desenvolvedor');
        });
    }
}
