<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\LocalizacaoEstoque;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocalizacoesDepositoFluxoTest extends TestCase
{
    use RefreshDatabase;

    private function autenticar(): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Localizacoes',
            'email' => 'usuario_localizacoes_' . uniqid('', true) . '@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        Sanctum::actingAs($usuario);

        return $usuario;
    }

    private function criarEstoque(Deposito $deposito): Estoque
    {
        $categoria = Categoria::create(['nome' => 'Categoria Localizacao ' . uniqid()]);
        $produto = Produto::create([
            'nome' => 'Produto Localizacao',
            'descricao' => 'Teste',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'LOC-' . uniqid(),
            'nome' => 'Variacao Localizacao',
            'preco' => 100,
            'custo' => 50,
        ]);

        return Estoque::updateOrCreate(
            [
                'id_variacao' => $variacao->id,
                'id_deposito' => $deposito->id,
            ],
            ['quantidade' => 3]
        );
    }

    public function test_cria_rejeita_vazia_e_impede_codigo_duplicado_no_deposito(): void
    {
        $this->autenticar();
        $deposito = Deposito::create(['nome' => 'Deposito Mapa']);

        $response = $this->postJson("/api/v1/depositos/{$deposito->id}/localizacoes", [
            'area' => 'A',
            'corredor' => '1',
            'setor' => 'B',
            'coluna' => '2',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.codigo_composto', 'A-1-B-2');

        $this->postJson("/api/v1/depositos/{$deposito->id}/localizacoes", [])
            ->assertStatus(422);

        $this->postJson("/api/v1/depositos/{$deposito->id}/localizacoes", [
            'area' => 'A',
            'corredor' => '1',
            'setor' => 'B',
            'coluna' => '2',
        ])->assertStatus(422);
    }

    public function test_estoque_so_pode_vincular_localizacao_do_mesmo_deposito(): void
    {
        $this->autenticar();
        $deposito = Deposito::create(['nome' => 'Deposito Origem']);
        $outroDeposito = Deposito::create(['nome' => 'Deposito Outro']);
        $estoque = $this->criarEstoque($deposito);

        $localizacao = LocalizacaoEstoque::create([
            'deposito_id' => $deposito->id,
            'area' => 'A',
            'corredor' => '1',
            'setor' => 'S',
            'coluna' => 'C',
            'codigo_composto' => 'A-1-S-C',
            'ativo' => true,
        ]);
        $localizacaoOutroDeposito = LocalizacaoEstoque::create([
            'deposito_id' => $outroDeposito->id,
            'area' => 'B',
            'corredor' => '2',
            'setor' => 'S',
            'coluna' => 'C',
            'codigo_composto' => 'B-2-S-C',
            'ativo' => true,
        ]);

        $this->patchJson("/api/v1/estoque/{$estoque->id}/localizacao", [
            'localizacao_id' => $localizacao->id,
        ])->assertOk()
            ->assertJsonPath('data.localizacao.id', $localizacao->id);

        $this->assertDatabaseHas('estoque', [
            'id' => $estoque->id,
            'localizacao_id' => $localizacao->id,
        ]);

        $this->patchJson("/api/v1/estoque/{$estoque->id}/localizacao", [
            'localizacao_id' => $localizacaoOutroDeposito->id,
        ])->assertStatus(422);
    }

    public function test_delete_desativa_quando_ocupada_e_remove_quando_livre(): void
    {
        $this->autenticar();
        $deposito = Deposito::create(['nome' => 'Deposito Delete']);
        $estoque = $this->criarEstoque($deposito);

        $ocupada = LocalizacaoEstoque::create([
            'deposito_id' => $deposito->id,
            'area' => 'A',
            'corredor' => '1',
            'setor' => 'S',
            'coluna' => '1',
            'codigo_composto' => 'A-1-S-1',
            'ativo' => true,
        ]);
        $livre = LocalizacaoEstoque::create([
            'deposito_id' => $deposito->id,
            'area' => 'A',
            'corredor' => '1',
            'setor' => 'S',
            'coluna' => '2',
            'codigo_composto' => 'A-1-S-2',
            'ativo' => true,
        ]);
        $estoque->update(['localizacao_id' => $ocupada->id]);

        $this->deleteJson("/api/v1/depositos/{$deposito->id}/localizacoes/{$ocupada->id}")
            ->assertOk()
            ->assertJsonPath('data.ativo', false);

        $this->assertDatabaseHas('localizacoes_estoque', [
            'id' => $ocupada->id,
            'ativo' => false,
        ]);

        $this->deleteJson("/api/v1/depositos/{$deposito->id}/localizacoes/{$livre->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('localizacoes_estoque', [
            'id' => $livre->id,
        ]);
    }
}
