<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\EstoqueReserva;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AjusteManualEstoqueTest extends TestCase
{
    use RefreshDatabase;

    private function criarCenario(int $quantidade = 5): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Ajuste',
            'email' => 'ajuste.' . uniqid() . '@example.test',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Ajuste']);
        $produto = Produto::create([
            'nome' => 'Produto Ajuste',
            'descricao' => 'Teste',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'AJ-1',
            'nome' => 'Variacao Ajuste',
            'preco' => 100,
            'custo' => 50,
        ]);
        $deposito = Deposito::create(['nome' => 'Deposito Ajuste']);
        $estoque = Estoque::updateOrCreate([
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
        ], [
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'quantidade' => $quantidade,
        ]);

        return [$usuario, $variacao, $deposito, $estoque];
    }

    private function actingAsComPerfis(Usuario $usuario, array $perfis): void
    {
        Sanctum::actingAs($usuario);

        Cache::put('perfis_usuario_' . $usuario->id, $perfis, now()->addHour());

        if (!Schema::hasTable('acesso_perfis') || !Schema::hasTable('acesso_usuario_perfil')) {
            return;
        }

        foreach ($perfis as $perfilNome) {
            DB::table('acesso_perfis')->updateOrInsert(['nome' => $perfilNome], [
                'descricao' => $perfilNome,
                'updated_at' => now(),
            ]);

            $perfilId = DB::table('acesso_perfis')->where('nome', $perfilNome)->value('id');

            DB::table('acesso_usuario_perfil')->updateOrInsert([
                'id_usuario' => $usuario->id,
                'id_perfil' => $perfilId,
            ], [
                'updated_at' => now(),
            ]);
        }
    }

    public function test_desenvolvedor_aumenta_saldo_registrando_entrada(): void
    {
        [$usuario, $variacao, $deposito, $estoque] = $this->criarCenario(5);
        $this->actingAsComPerfis($usuario, ['Desenvolvedor']);

        $response = $this->postJson('/api/v1/estoque/ajustes-manuais', [
            'estoque_id' => $estoque->id,
            'quantidade_final' => 8,
            'observacao' => 'Contagem fisica',
        ]);

        $response->assertCreated();

        $this->assertSame(8, (int) $estoque->fresh()->quantidade);
        $this->assertDatabaseHas('estoque_movimentacoes', [
            'id_variacao' => $variacao->id,
            'id_deposito_destino' => $deposito->id,
            'tipo' => 'entrada',
            'quantidade' => 3,
            'ref_type' => 'ajuste_manual',
            'ref_id' => $estoque->id,
        ]);
    }

    public function test_desenvolvedor_cria_saldo_zerado_por_variacao_e_deposito(): void
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Ajuste Zerado',
            'email' => 'ajuste.zerado.' . uniqid() . '@example.test',
            'senha' => 'senha',
            'ativo' => true,
        ]);
        $categoria = Categoria::create(['nome' => 'Categoria Ajuste Zerado']);
        $produto = Produto::create([
            'nome' => 'Produto Ajuste Zerado',
            'descricao' => 'Teste',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'AJ-Z',
            'nome' => 'Variacao Ajuste Zerado',
            'preco' => 100,
            'custo' => 50,
        ]);
        $deposito = Deposito::create(['nome' => 'Deposito Ajuste Zerado']);
        $this->actingAsComPerfis($usuario, ['Desenvolvedor']);

        $response = $this->postJson('/api/v1/estoque/ajustes-manuais', [
            'variacao_id' => $variacao->id,
            'deposito_id' => $deposito->id,
            'quantidade_final' => 4,
            'observacao' => 'Contagem inicial',
        ]);

        $response->assertCreated();

        $estoque = Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $deposito->id)
            ->first();

        $this->assertNotNull($estoque);
        $this->assertSame(4, (int) $estoque->quantidade);
        $this->assertDatabaseHas('estoque_movimentacoes', [
            'id_variacao' => $variacao->id,
            'id_deposito_destino' => $deposito->id,
            'tipo' => 'entrada',
            'quantidade' => 4,
            'ref_type' => 'ajuste_manual',
            'ref_id' => $estoque->id,
        ]);
    }

    public function test_desenvolvedor_reduz_saldo_registrando_saida(): void
    {
        [$usuario, $variacao, $deposito, $estoque] = $this->criarCenario(5);
        $this->actingAsComPerfis($usuario, ['Desenvolvedor']);

        $response = $this->postJson('/api/v1/estoque/ajustes-manuais', [
            'estoque_id' => $estoque->id,
            'quantidade_final' => 2,
        ]);

        $response->assertCreated();

        $this->assertSame(2, (int) $estoque->fresh()->quantidade);
        $this->assertDatabaseHas('estoque_movimentacoes', [
            'id_variacao' => $variacao->id,
            'id_deposito_origem' => $deposito->id,
            'tipo' => 'saida',
            'quantidade' => 3,
            'ref_type' => 'ajuste_manual',
            'ref_id' => $estoque->id,
        ]);
    }

    public function test_usuario_sem_perfil_desenvolvedor_nao_registra_ajuste(): void
    {
        [$usuario, , , $estoque] = $this->criarCenario(5);
        $this->actingAsComPerfis($usuario, ['Administrador']);
        $movimentacoesAntes = EstoqueMovimentacao::query()->count();

        $response = $this->postJson('/api/v1/estoque/ajustes-manuais', [
            'estoque_id' => $estoque->id,
            'quantidade_final' => 8,
        ]);

        $response->assertForbidden();
        $this->assertSame(5, (int) $estoque->fresh()->quantidade);
        $this->assertSame($movimentacoesAntes, EstoqueMovimentacao::query()->count());
    }

    public function test_saldo_final_igual_nao_cria_movimentacao(): void
    {
        [$usuario, , , $estoque] = $this->criarCenario(5);
        $this->actingAsComPerfis($usuario, ['Desenvolvedor']);
        $movimentacoesAntes = EstoqueMovimentacao::query()->count();

        $response = $this->postJson('/api/v1/estoque/ajustes-manuais', [
            'estoque_id' => $estoque->id,
            'quantidade_final' => 5,
        ]);

        $response->assertStatus(422);
        $this->assertSame($movimentacoesAntes, EstoqueMovimentacao::query()->count());
    }

    public function test_saldo_final_negativo_retorna_validacao(): void
    {
        [$usuario, , , $estoque] = $this->criarCenario(5);
        $this->actingAsComPerfis($usuario, ['Desenvolvedor']);
        $movimentacoesAntes = EstoqueMovimentacao::query()->count();

        $response = $this->postJson('/api/v1/estoque/ajustes-manuais', [
            'estoque_id' => $estoque->id,
            'quantidade_final' => -1,
        ]);

        $response->assertStatus(422);
        $this->assertSame($movimentacoesAntes, EstoqueMovimentacao::query()->count());
    }

    public function test_reducao_abaixo_do_reservado_e_bloqueada(): void
    {
        [$usuario, $variacao, $deposito, $estoque] = $this->criarCenario(5);
        $this->actingAsComPerfis($usuario, ['Desenvolvedor']);
        $movimentacoesAntes = EstoqueMovimentacao::query()->count();

        EstoqueReserva::create([
            'id_variacao' => $variacao->id,
            'id_deposito' => $deposito->id,
            'id_usuario' => $usuario->id,
            'quantidade' => 4,
            'quantidade_consumida' => 0,
            'status' => 'ativa',
            'motivo' => 'teste',
        ]);

        $response = $this->postJson('/api/v1/estoque/ajustes-manuais', [
            'estoque_id' => $estoque->id,
            'quantidade_final' => 0,
        ]);

        $response->assertStatus(422);
        $this->assertSame(5, (int) $estoque->fresh()->quantidade);
        $this->assertSame($movimentacoesAntes, EstoqueMovimentacao::query()->count());
    }
}
