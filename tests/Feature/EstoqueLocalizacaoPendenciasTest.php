<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueReserva;
use App\Models\LocalizacaoEstoque;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EstoqueLocalizacaoPendenciasTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Localizacao',
            'email' => 'localizacao.' . uniqid() . '@test.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        return $usuario;
    }

    /**
     * @return array{Categoria,Deposito,Deposito,Usuario}
     */
    private function base(): array
    {
        $usuario = $this->actingUser();
        $categoria = Categoria::create(['nome' => 'Categoria Localizacao']);
        $deposito = Deposito::create(['nome' => 'Deposito JB']);
        $outroDeposito = Deposito::create(['nome' => 'Loja']);

        return [$categoria, $deposito, $outroDeposito, $usuario];
    }

    private function variacao(Categoria $categoria, string $nome, string $sku): ProdutoVariacao
    {
        $produto = Produto::create([
            'nome' => $nome,
            'descricao' => 'Produto para localizacao',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);

        return ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => $sku,
            'sku_interno' => $sku,
            'nome' => $nome . ' Variacao',
            'preco' => 100,
            'custo' => 50,
        ]);
    }

    public function test_pendencias_incluem_saldo_ou_reserva_e_excluem_localizados_e_zerados_sem_reserva(): void
    {
        [$categoria, $deposito,, $usuario] = $this->base();

        $comSaldo = $this->variacao($categoria, 'Produto Com Saldo', 'SKU-SALDO');
        $comReserva = $this->variacao($categoria, 'Produto Com Reserva', 'SKU-RESERVA');
        $zerado = $this->variacao($categoria, 'Produto Zerado', 'SKU-ZERADO');
        $localizado = $this->variacao($categoria, 'Produto Localizado', 'SKU-LOCALIZADO');

        $localizacao = LocalizacaoEstoque::create([
            'deposito_id' => $deposito->id,
            'setor' => '1',
            'codigo_composto' => '1',
            'ativo' => true,
        ]);

        Estoque::updateOrCreate(
            ['id_variacao' => $comSaldo->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 5, 'localizacao_id' => null]
        );
        Estoque::updateOrCreate(
            ['id_variacao' => $comReserva->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 0, 'localizacao_id' => null]
        );
        Estoque::updateOrCreate(
            ['id_variacao' => $zerado->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 0, 'localizacao_id' => null]
        );
        Estoque::updateOrCreate(
            ['id_variacao' => $localizado->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 3, 'localizacao_id' => $localizacao->id]
        );

        $cliente = Cliente::create(['nome' => 'Cliente Reserva']);
        $pedido = Pedido::create([
            'tipo' => Pedido::TIPO_VENDA,
            'id_cliente' => $cliente->id,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 100,
        ]);
        EstoqueReserva::create([
            'id_variacao' => $comReserva->id,
            'id_deposito' => $deposito->id,
            'pedido_id' => $pedido->id,
            'quantidade' => 2,
            'quantidade_consumida' => 0,
            'status' => 'ativa',
        ]);

        $response = $this->getJson('/api/v1/estoque/localizacoes/pendencias?deposito=' . $deposito->id);
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('variacao_id')->all();
        $this->assertContains($comSaldo->id, $ids);
        $this->assertContains($comReserva->id, $ids);
        $this->assertNotContains($zerado->id, $ids);
        $this->assertNotContains($localizado->id, $ids);

        $reserva = collect($response->json('data'))->firstWhere('variacao_id', $comReserva->id);
        $this->assertSame(2, (int) ($reserva['quantidade_reservada_cliente'] ?? 0));

        $filtro = $this->getJson('/api/v1/estoque/localizacoes/pendencias?deposito=' . $deposito->id . '&produto=SKU-RESERVA');
        $filtro->assertOk();
        $this->assertSame([$comReserva->id], collect($filtro->json('data'))->pluck('variacao_id')->all());
    }

    public function test_atribuicao_em_massa_atualiza_estoques_do_mesmo_deposito(): void
    {
        [$categoria, $deposito] = $this->base();

        $variacaoA = $this->variacao($categoria, 'Produto A', 'SKU-A');
        $variacaoB = $this->variacao($categoria, 'Produto B', 'SKU-B');
        $estoqueA = Estoque::updateOrCreate(
            ['id_variacao' => $variacaoA->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 2, 'localizacao_id' => null]
        );
        $estoqueB = Estoque::updateOrCreate(
            ['id_variacao' => $variacaoB->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 3, 'localizacao_id' => null]
        );
        $localizacao = LocalizacaoEstoque::create([
            'deposito_id' => $deposito->id,
            'setor' => '8',
            'coluna' => 'D',
            'codigo_composto' => '8-D',
            'ativo' => true,
        ]);

        $response = $this->patchJson('/api/v1/estoque/localizacoes/vinculos-em-massa', [
            'estoque_ids' => [$estoqueA->id, $estoqueB->id],
            'localizacao_id' => $localizacao->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.atualizados', 2)
            ->assertJsonPath('data.localizacao.id', $localizacao->id);

        $this->assertSame($localizacao->id, (int) $estoqueA->fresh()->localizacao_id);
        $this->assertSame($localizacao->id, (int) $estoqueB->fresh()->localizacao_id);
    }

    public function test_atribuicao_em_massa_rejeita_localizacao_de_outro_deposito(): void
    {
        [$categoria, $deposito, $outroDeposito] = $this->base();

        $variacao = $this->variacao($categoria, 'Produto A', 'SKU-A');
        $estoque = Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $deposito->id],
            ['quantidade' => 2, 'localizacao_id' => null]
        );
        $localizacao = LocalizacaoEstoque::create([
            'deposito_id' => $outroDeposito->id,
            'setor' => '9',
            'codigo_composto' => '9',
            'ativo' => true,
        ]);

        $response = $this->patchJson('/api/v1/estoque/localizacoes/vinculos-em-massa', [
            'estoque_ids' => [$estoque->id],
            'localizacao_id' => $localizacao->id,
        ]);

        $response->assertStatus(422);
        $this->assertNull($estoque->fresh()->localizacao_id);
    }
}
