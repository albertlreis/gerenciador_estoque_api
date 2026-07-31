<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Tests\TestCase;

class RouteControllerContractTest extends TestCase
{
    public function test_todas_as_rotas_de_controller_apontam_para_metodos_existentes(): void
    {
        $ausentes = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn (Route $route): string => $route->getActionName())
            ->filter(fn (string $action): bool => str_contains($action, '@'))
            ->filter(function (string $action): bool {
                [$controller, $method] = explode('@', $action, 2);

                return class_exists($controller) && ! method_exists($controller, $method);
            })
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            [],
            $ausentes,
            'Há rotas apontando para métodos inexistentes: '.implode(', ', $ausentes)
        );
    }
}
