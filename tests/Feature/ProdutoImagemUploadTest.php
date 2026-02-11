<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoImagemUploadTest extends TestCase
{
    private function criarUsuarioComPerfil(): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Teste',
            'email' => 'usuario.imagem.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('perfis_usuario_' . $usuario->id, ['Administrador'], now()->addHour());

        return $usuario;
    }

    private function criarProdutoBase(): array
    {
        $now = now();

        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Categoria Teste',
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fornecedorId = DB::table('fornecedores')->insertGetId([
            'nome' => 'Fornecedor Teste',
            'cnpj' => null,
            'email' => null,
            'telefone' => null,
            'endereco' => null,
            'status' => 1,
            'observacoes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $produtoId = DB::table('produtos')->insertGetId([
            'nome' => 'Produto Teste',
            'descricao' => null,
            'id_categoria' => $categoriaId,
            'id_fornecedor' => $fornecedorId,
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

        return [$produtoId, $now];
    }

    public function test_upload_invalido_retorna_422(): void
    {
        Storage::fake('public');
        $this->criarUsuarioComPerfil();
        [$produtoId] = $this->criarProdutoBase();

        $arquivo = UploadedFile::fake()->create('arquivo.pdf', 10, 'application/pdf');

        $response = $this->post(
            "/api/v1/produtos/{$produtoId}/imagens",
            ['image' => $arquivo],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(422);
    }

    public function test_upload_valido_retorna_201(): void
    {
        Storage::fake('public');
        $this->criarUsuarioComPerfil();
        [$produtoId] = $this->criarProdutoBase();

        $arquivo = UploadedFile::fake()->image('foto.jpg');

        $response = $this->post(
            "/api/v1/produtos/{$produtoId}/imagens",
            ['image' => $arquivo, 'principal' => true]
        );

        $response->assertCreated();
    }
}
