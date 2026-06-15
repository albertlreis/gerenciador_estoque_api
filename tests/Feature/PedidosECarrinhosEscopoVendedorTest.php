<?php

namespace Tests\Feature;

use App\Models\Carrinho;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Consignacao;
use App\Models\Deposito;
use App\Models\Parceiro;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PedidosECarrinhosEscopoVendedorTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarComPermissoes(array $permissoes): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Escopo',
            'email' => uniqid('escopo-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, $permissoes);

        return $usuario;
    }

    public function test_vendedor_visualiza_pedidos_de_todos(): void
    {
        $this->autenticarComPermissoes(['pedidos.visualizar', 'carrinhos.finalizar']);

        $vendedorA = Usuario::create([
            'nome' => 'Vendedor A',
            'email' => uniqid('vend-a-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $vendedorB = Usuario::create([
            'nome' => 'Vendedor B',
            'email' => uniqid('vend-b-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $clienteA = Cliente::create([
            'nome' => 'Cliente A',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $clienteB = Cliente::create([
            'nome' => 'Cliente B',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $pedidoA = Pedido::create([
            'id_cliente' => $clienteA->id,
            'id_usuario' => $vendedorA->id,
            'tipo' => 'venda',
            'numero_externo' => 'PED-A-' . strtoupper(substr(uniqid(), -5)),
            'data_pedido' => now(),
            'valor_total' => 100,
            'prazo_dias_uteis' => 10,
        ]);

        $pedidoB = Pedido::create([
            'id_cliente' => $clienteB->id,
            'id_usuario' => $vendedorB->id,
            'tipo' => 'venda',
            'numero_externo' => 'PED-B-' . strtoupper(substr(uniqid(), -5)),
            'data_pedido' => now(),
            'valor_total' => 200,
            'prazo_dias_uteis' => 10,
        ]);

        $response = $this->getJson('/api/v1/pedidos?per_page=50');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $pedidoA->id, $ids);
        $this->assertContains((int) $pedidoB->id, $ids);
    }

    public function test_vendedor_visualiza_carrinhos_ativos_de_todos_com_vendedor_nome(): void
    {
        $this->autenticarComPermissoes(['pedidos.visualizar', 'carrinhos.finalizar']);

        $vendedorA = Usuario::create([
            'nome' => 'Vendedor Carrinho A',
            'email' => uniqid('cart-a-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $vendedorB = Usuario::create([
            'nome' => 'Vendedor Carrinho B',
            'email' => uniqid('cart-b-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $cliente = Cliente::create([
            'nome' => 'Cliente Carrinho',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        Carrinho::create([
            'id_usuario' => $vendedorA->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        Carrinho::create([
            'id_usuario' => $vendedorB->id,
            'id_cliente' => $cliente->id,
            'status' => 'rascunho',
        ]);

        $response = $this->getJson('/api/v1/carrinhos');
        $response->assertOk();

        $itens = collect($response->json('data'));
        $this->assertCount(2, $itens);
        $this->assertTrue($itens->every(fn ($item) => !empty($item['vendedor_nome'])));
    }

    public function test_vendedor_visualiza_consignacoes_de_todos(): void
    {
        $this->autenticarComPermissoes(['pedidos.visualizar', 'carrinhos.finalizar']);

        $vendedorA = Usuario::create([
            'nome' => 'Vendedor Consignacao A',
            'email' => uniqid('consig-a-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $vendedorB = Usuario::create([
            'nome' => 'Vendedor Consignacao B',
            'email' => uniqid('consig-b-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $consignacaoA = $this->criarConsignacaoParaVendedor($vendedorA, 'Cliente Consignacao A');
        $consignacaoB = $this->criarConsignacaoParaVendedor($vendedorB, 'Cliente Consignacao B');

        $response = $this->getJson('/api/v1/consignacoes?per_page=50');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $consignacaoA->id, $ids);
        $this->assertContains((int) $consignacaoB->id, $ids);
    }

    public function test_vendedor_filtra_consignacoes_por_vendedor(): void
    {
        $this->autenticarComPermissoes(['pedidos.visualizar', 'carrinhos.finalizar']);

        $vendedorA = Usuario::create([
            'nome' => 'Vendedor Filtro A',
            'email' => uniqid('consig-filtro-a-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $vendedorB = Usuario::create([
            'nome' => 'Vendedor Filtro B',
            'email' => uniqid('consig-filtro-b-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $consignacaoA = $this->criarConsignacaoParaVendedor($vendedorA, 'Cliente Filtro A');
        $consignacaoB = $this->criarConsignacaoParaVendedor($vendedorB, 'Cliente Filtro B');

        $response = $this->getJson("/api/v1/consignacoes?per_page=50&vendedor_id={$vendedorB->id}");
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertNotContains((int) $consignacaoA->id, $ids);
        $this->assertContains((int) $consignacaoB->id, $ids);
    }

    public function test_vendedor_filtra_consignacoes_por_parceiro(): void
    {
        $this->autenticarComPermissoes(['pedidos.visualizar', 'carrinhos.finalizar']);

        $vendedor = Usuario::create([
            'nome' => 'Vendedor Parceiro',
            'email' => uniqid('consig-parceiro-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $parceiroA = Parceiro::create([
            'nome' => 'Parceiro Consignacao A',
            'tipo' => 'cliente',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);
        $parceiroB = Parceiro::create([
            'nome' => 'Parceiro Consignacao B',
            'tipo' => 'cliente',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $consignacaoA = $this->criarConsignacaoParaVendedor($vendedor, 'Cliente Parceiro A', $parceiroA);
        $consignacaoB = $this->criarConsignacaoParaVendedor($vendedor, 'Cliente Parceiro B', $parceiroB);

        $response = $this->getJson("/api/v1/consignacoes?per_page=50&parceiro_id={$parceiroB->id}");
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertNotContains((int) $consignacaoA->id, $ids);
        $this->assertContains((int) $consignacaoB->id, $ids);
    }

    public function test_lista_parceiros_retorna_apenas_parceiros_com_consignacoes(): void
    {
        $this->autenticarComPermissoes(['pedidos.visualizar', 'carrinhos.finalizar']);

        $vendedor = Usuario::create([
            'nome' => 'Vendedor Opcoes Parceiro',
            'email' => uniqid('consig-opcoes-parceiro-', true) . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $parceiroComConsignacao = Parceiro::create([
            'nome' => 'Parceiro Com Consignacao',
            'tipo' => 'cliente',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);
        $parceiroSemConsignacao = Parceiro::create([
            'nome' => 'Parceiro Sem Consignacao',
            'tipo' => 'cliente',
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $this->criarConsignacaoParaVendedor($vendedor, 'Cliente Opcoes Parceiro', $parceiroComConsignacao);

        $response = $this->getJson('/api/v1/consignacoes/parceiros');
        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $parceiroComConsignacao->id, $ids);
        $this->assertNotContains((int) $parceiroSemConsignacao->id, $ids);
    }

    private function criarConsignacaoParaVendedor(Usuario $vendedor, string $clienteNome, ?Parceiro $parceiro = null): Consignacao
    {
        $cliente = Cliente::create([
            'nome' => $clienteNome,
            'documento' => (string) random_int(10000000000, 99999999999),
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Escopo ' . uniqid()]);
        $produto = Produto::create([
            'nome' => 'Produto Escopo ' . uniqid(),
            'descricao' => 'Produto para teste de escopo',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'ESCOPO-' . uniqid(),
            'nome' => 'Variacao Escopo',
            'preco' => 150,
            'custo' => 90,
        ]);

        $deposito = Deposito::create(['nome' => 'Deposito Escopo ' . uniqid()]);

        $pedido = Pedido::create([
            'id_cliente' => $cliente->id,
            'id_usuario' => $vendedor->id,
            'id_parceiro' => $parceiro?->id,
            'tipo' => 'venda',
            'numero_externo' => 'CONSIG-' . strtoupper(substr(uniqid(), -5)),
            'data_pedido' => now(),
            'valor_total' => 150,
            'prazo_dias_uteis' => 10,
        ]);

        return Consignacao::create([
            'pedido_id' => $pedido->id,
            'produto_variacao_id' => $variacao->id,
            'deposito_id' => $deposito->id,
            'quantidade' => 1,
            'data_envio' => now()->toDateString(),
            'prazo_resposta' => now()->addDays(15),
            'status' => 'pendente',
        ]);
    }
}
