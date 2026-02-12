<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;

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
}
