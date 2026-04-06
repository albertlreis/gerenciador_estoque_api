<?php

namespace Tests\Feature;

use App\Models\ProdutoConjunto;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoConjuntoCrudTest extends TestCase
{
    use DatabaseTransactions;

    private int $categoriaId;
    private int $fornecedorId;

    private function fakeImagemPng(string $nome = 'hero.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+yF9kAAAAASUVORK5CYII='
        ));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $this->categoriaId = (int) DB::table('categorias')->insertGetId([
            'nome' => 'Categoria Conjunto ' . uniqid(),
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->fornecedorId = (int) DB::table('fornecedores')->insertGetId([
            'nome' => 'Fornecedor Conjunto ' . uniqid(),
            'cnpj' => null,
            'email' => null,
            'telefone' => null,
            'endereco' => null,
            'status' => 1,
            'observacoes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_cria_e_atualiza_conjunto_com_auditoria(): void
    {
        $this->autenticar(['produtos.gerenciar']);

        $variacaoA = $this->criarVariacao('Sofa', 1000);
        $variacaoB = $this->criarVariacao('Chaise', 700);

        $create = $this->postJson('/api/v1/produto-conjuntos', [
            'nome' => 'Conjunto Sala',
            'descricao' => 'Sala completa',
            'ativo' => true,
            'preco_modo' => 'apartir',
            'principal_variacao_id' => $variacaoA,
            'itens' => [
                ['produto_variacao_id' => $variacaoA, 'label' => 'Sofa', 'ordem' => 1],
                ['produto_variacao_id' => $variacaoB, 'label' => 'Chaise', 'ordem' => 2],
            ],
        ]);

        $create->assertCreated()
            ->assertJsonPath('nome', 'Conjunto Sala')
            ->assertJsonPath('preco_modo', 'apartir')
            ->assertJsonCount(2, 'itens');

        $conjuntoId = (int) $create->json('id');

        $this->assertDatabaseHas('produto_conjuntos', [
            'id' => $conjuntoId,
            'nome' => 'Conjunto Sala',
            'preco_modo' => 'apartir',
            'principal_variacao_id' => $variacaoA,
        ]);

        $this->assertDatabaseHas('auditoria_eventos', [
            'module' => 'produto_conjuntos',
            'action' => 'create',
            'auditable_id' => $conjuntoId,
        ]);

        $variacaoC = $this->criarVariacao('Mesa', 350);

        $update = $this->patchJson("/api/v1/produto-conjuntos/{$conjuntoId}", [
            'nome' => 'Conjunto Sala Atualizado',
            'preco_modo' => 'soma',
            'principal_variacao_id' => null,
            'itens' => [
                ['produto_variacao_id' => $variacaoA, 'label' => 'Sofa 3 Lugares', 'ordem' => 1],
                ['produto_variacao_id' => $variacaoC, 'label' => 'Mesa Lateral', 'ordem' => 2],
            ],
        ]);

        $update->assertOk()
            ->assertJsonPath('nome', 'Conjunto Sala Atualizado')
            ->assertJsonPath('preco_modo', 'soma')
            ->assertJsonCount(2, 'itens');

        $this->assertDatabaseHas('auditoria_eventos', [
            'module' => 'produto_conjuntos',
            'action' => 'update',
            'auditable_id' => $conjuntoId,
        ]);
    }

    public function test_upload_hero_e_delete_registram_auditoria(): void
    {
        Storage::fake('public');
        $this->autenticar(['produtos.gerenciar']);

        $variacaoA = $this->criarVariacao('Cama', 1200);

        $conjunto = ProdutoConjunto::create([
            'nome' => 'Conjunto Quarto',
            'descricao' => 'Teste',
            'hero_image_path' => null,
            'preco_modo' => 'soma',
            'principal_variacao_id' => null,
            'ativo' => true,
        ]);

        $conjunto->itens()->create([
            'produto_variacao_id' => $variacaoA,
            'label' => 'Cama',
            'ordem' => 1,
        ]);

        $upload = $this->post("/api/v1/produto-conjuntos/{$conjunto->id}/hero", [
            'file' => $this->fakeImagemPng(),
        ]);

        $upload->assertOk()
            ->assertJsonPath('data.id', $conjunto->id);

        $heroPath = (string) $upload->json('data.hero_image_path');
        $this->assertNotSame('', $heroPath);
        Storage::disk('public')->assertExists($heroPath);

        $this->assertDatabaseHas('auditoria_eventos', [
            'module' => 'produto_conjuntos',
            'action' => 'upload_hero',
            'auditable_id' => $conjunto->id,
        ]);

        $this->deleteJson("/api/v1/produto-conjuntos/{$conjunto->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('produto_conjuntos', [
            'id' => $conjunto->id,
        ]);

        $this->assertDatabaseHas('auditoria_eventos', [
            'module' => 'produto_conjuntos',
            'action' => 'delete',
            'auditable_id' => $conjunto->id,
        ]);
    }

    private function autenticar(array $permissoes): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Conjunto',
            'email' => 'usuario.conjunto.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, $permissoes, now()->addHour());

        return $usuario;
    }

    private function criarVariacao(string $nomeProduto, float $preco): int
    {
        $now = now();

        $produtoId = (int) DB::table('produtos')->insertGetId([
            'nome' => $nomeProduto . ' ' . uniqid(),
            'descricao' => null,
            'id_categoria' => $this->categoriaId,
            'id_fornecedor' => $this->fornecedorId,
            'altura' => null,
            'largura' => null,
            'profundidade' => null,
            'peso' => null,
            'manual_conservacao' => null,
            'estoque_minimo' => null,
            'ativo' => true,
            'motivo_desativacao' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $produtoId,
            'referencia' => strtoupper(substr($nomeProduto, 0, 3)) . '-' . uniqid(),
            'nome' => $nomeProduto,
            'preco' => $preco,
            'custo' => $preco / 2,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
