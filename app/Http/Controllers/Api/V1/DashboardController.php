<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\AuthHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\DashboardQueryRequest;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $service,
    ) {}

    public function admin(DashboardQueryRequest $request): JsonResponse
    {
        if (!$this->canAccessAdmin()) {
            return response()->json(['message' => 'Sem permissão para acessar o dashboard administrativo.'], 403);
        }

        return response()->json(
            $this->service->admin($request->filters(), (int) auth()->id())
        );
    }

    public function financeiro(DashboardQueryRequest $request): JsonResponse
    {
        if (!$this->canAccessFinanceiro()) {
            return response()->json(['message' => 'Sem permissão para acessar o dashboard financeiro.'], 403);
        }

        return response()->json(
            $this->service->financeiro($request->filters(), (int) auth()->id())
        );
    }

    public function estoque(DashboardQueryRequest $request): JsonResponse
    {
        if (!$this->canAccessEstoque()) {
            return response()->json(['message' => 'Sem permissão para acessar o dashboard de estoque.'], 403);
        }

        return response()->json(
            $this->service->estoque($request->filters(), (int) auth()->id())
        );
    }

    public function vendedor(DashboardQueryRequest $request): JsonResponse
    {
        if (!$this->canAccessVendedor()) {
            return response()->json(['message' => 'Sem permissão para acessar o dashboard de vendedor.'], 403);
        }

        return response()->json(
            $this->service->vendedor(
                $request->filters(),
                (int) auth()->id(),
                $this->sellerCanViewAll()
            )
        );
    }

    public function seriesComercial(DashboardQueryRequest $request): JsonResponse
    {
        if (!$this->canAccessAdmin() && !$this->canAccessVendedor()) {
            return response()->json(['message' => 'Sem permissão para acessar as séries comerciais.'], 403);
        }

        $podeVisualizarTodos = $this->canAccessAdmin() || $this->sellerCanViewAll();

        return response()->json(
            $this->service->seriesComercial(
                $request->filters(),
                (int) auth()->id(),
                $podeVisualizarTodos
            )
        );
    }

    private function canAccessAdmin(): bool
    {
        return $this->hasAnyPermissao(['dashboard.admin']) || $this->hasAnyPerfil(['administrador']);
    }

    private function canAccessFinanceiro(): bool
    {
        return $this->hasAnyPermissao([
            'financeiro.dashboard.visualizar',
            'financeiro.lancamentos.visualizar',
            'contas.receber.view',
            'contas.pagar.view',
        ]) || $this->hasAnyPerfil(['financeiro']);
    }

    private function canAccessEstoque(): bool
    {
        return $this->hasAnyPermissao([
            'estoque.movimentacao',
            'estoque.movimentar',
            'estoque.historico',
        ]) || $this->hasAnyPerfil(['estoquista']) || $this->canAccessAdmin();
    }

    private function canAccessVendedor(): bool
    {
        return $this->hasAnyPermissao([
            'dashboard.vendedor',
            'pedidos.visualizar',
            'pedidos.criar',
        ]) || $this->hasAnyPerfil(['vendedor']) || $this->canAccessAdmin();
    }

    private function sellerCanViewAll(): bool
    {
        $permission = (string) config('dashboard.permissions.seller_view_all', 'pedidos.visualizar.todos');

        return AuthHelper::hasPermissao($permission);
    }

    private function hasAnyPermissao(array $slugs): bool
    {
        foreach ($slugs as $slug) {
            if (AuthHelper::hasPermissao($slug)) {
                return true;
            }
        }

        return false;
    }

    private function hasAnyPerfil(array $perfis): bool
    {
        $atuais = collect(AuthHelper::getPerfis())
            ->map(fn ($perfil) => Str::lower((string) $perfil))
            ->values();

        foreach ($perfis as $perfil) {
            if ($atuais->contains(Str::lower($perfil))) {
                return true;
            }
        }

        return false;
    }
}
