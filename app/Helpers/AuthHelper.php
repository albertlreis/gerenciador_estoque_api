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

        $permissoes = self::getPermissoes();

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
     * Regra central para permitir seleção de vendedor no fluxo de pedidos/carrinhos.
     * Mantem compatibilidade via permissao e libera por perfil para vendedor/admin.
     */
    public static function podeSelecionarVendedorPedido(): bool
    {
        if (self::hasPermissao('pedidos.selecionar_vendedor')) {
            return true;
        }

        if (self::hasPermissao('pedidos.visualizar') && self::hasPermissao('carrinhos.finalizar')) {
            return true;
        }

        return self::isPerfilAdministradorOuVendedor();
    }

    /**
     * Regra central para listar pedidos de todos os vendedores.
     */
    public static function podeVisualizarPedidosDeTodos(): bool
    {
        if (self::hasPermissao('pedidos.visualizar.todos')) {
            return true;
        }

        if (self::hasPermissao('pedidos.visualizar') && self::hasPermissao('carrinhos.finalizar')) {
            return true;
        }

        return self::isPerfilAdministradorOuVendedor();
    }

    /**
     * Regra central para listar carrinhos de todos os vendedores.
     */
    public static function podeVisualizarCarrinhosDeTodos(): bool
    {
        if (self::hasPermissao('carrinhos.visualizar.todos')) {
            return true;
        }

        if (self::hasPermissao('pedidos.visualizar') && self::hasPermissao('carrinhos.finalizar')) {
            return true;
        }

        return self::isPerfilAdministradorOuVendedor();
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

        if (!Schema::hasTable('acesso_usuario_perfil')
            || !Schema::hasTable('acesso_perfil_permissao')
            || !Schema::hasTable('acesso_permissoes')) {
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

        if (!Schema::hasTable('acesso_usuario_perfil') || !Schema::hasTable('acesso_perfis')) {
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

    /**
     * Identifica perfil administrador/vendedor sem depender de outro servico.
     * Usa cache e protege cenarios de teste onde tabelas de acesso nao existem.
     */
    private static function isPerfilAdministradorOuVendedor(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        if (!Schema::hasTable('acesso_usuario_perfil') || !Schema::hasTable('acesso_perfis')) {
            return false;
        }

        $usuarioId = (int) auth()->id();
        $cacheKey = "usuario_{$usuarioId}_perfil_admin_ou_vendedor";

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($usuarioId) {
            $perfis = DB::table('acesso_usuario_perfil as up')
                ->join('acesso_perfis as p', 'p.id', '=', 'up.id_perfil')
                ->where('up.id_usuario', $usuarioId)
                ->pluck('p.nome')
                ->filter(fn ($nome) => is_string($nome))
                ->map(fn ($nome) => Str::lower(trim($nome)))
                ->values();

            if ($perfis->isEmpty()) {
                return false;
            }

            return $perfis->contains(Str::lower('Administrador'))
                || $perfis->contains(Str::lower('Vendedor'));
        });
    }
}
