<?php

namespace Tests\Feature;

use App\Enums\PedidoStatus;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoStatusHistorico;
use App\Models\Usuario;
use App\Services\PedidoStatusFluxoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PedidoStatusFluxoQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_fluxos_sao_consultados_uma_vez_por_escopo_e_invalidados_explicitamente(): void
    {
        $service = app(PedidoStatusFluxoService::class);

        DB::enableQueryLog();
        $service->fluxoDetalhadoPorTipo(PedidoStatusFluxoService::TIPO_VENDA);
        $primeiraConsulta = count(DB::getQueryLog());

        DB::flushQueryLog();
        $service->fluxoDetalhadoPorTipo(PedidoStatusFluxoService::TIPO_VENDA);
        $this->assertCount(0, DB::getQueryLog());

        $service->limparCache();
        DB::flushQueryLog();
        $service->fluxoDetalhadoPorTipo(PedidoStatusFluxoService::TIPO_VENDA);

        $this->assertGreaterThan(0, $primeiraConsulta);
        $this->assertGreaterThan(0, count(DB::getQueryLog()));
    }

    public function test_previsoes_reutilizam_historico_e_consignacoes_carregados(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Query Test',
            'email' => uniqid('query-test-', true).'@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        $cliente = Cliente::create([
            'nome' => 'Cliente Query Test',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);
        $pedido = Pedido::create([
            'tipo' => Pedido::TIPO_VENDA,
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 100,
        ]);
        PedidoStatusHistorico::create([
            'pedido_id' => $pedido->id,
            'status' => PedidoStatus::PEDIDO_CRIADO->value,
            'data_status' => now(),
            'usuario_id' => $usuario->id,
        ]);

        $pedido->load(['historicoStatus', 'consignacoes', 'statusAtual']);
        $service = app(PedidoStatusFluxoService::class);
        $service->fluxoDetalhadoPorTipo(PedidoStatusFluxoService::TIPO_VENDA, false);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->previsoes($pedido);

        $this->assertCount(0, DB::getQueryLog());
    }
}
