<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProdutoAtributoSugestoesTest extends TestCase
{
    private int $produtoId;

    private int $sequenciaVariacao = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $usuario = Usuario::create([
            'nome' => 'Usuario Sugestoes Atributos',
            'email' => 'usuario.atributos.'.uniqid().'@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);
        Sanctum::actingAs($usuario);

        $this->produtoId = $this->criarProdutoBase();
    }

    public function test_sugestoes_de_nomes_usam_normalizacao_canonica(): void
    {
        $token = strtolower(Str::random(8));

        $this->adicionarAtributo("Modelo Referência {$token}", 'A');
        $this->adicionarAtributo("modelo-referencia_{$token}", 'B');
        $this->adicionarAtributo("MODELO_REFERÊNCIA-{$token}", 'C');

        $response = $this->getJson(
            '/api/v1/atributos/sugestoes?q='.rawurlencode("REFERÊNCIA-{$token}")
        );

        $response
            ->assertOk()
            ->assertExactJson(["modelo_referencia_{$token}"]);
    }

    public function test_sugestoes_de_nomes_preservam_limite_de_vinte(): void
    {
        $token = strtolower(Str::random(8));

        foreach (range(1, 22) as $indice) {
            $this->adicionarAtributo("Atributo Limite {$token} {$indice}", (string) $indice);
        }

        $response = $this->getJson(
            '/api/v1/atributos/sugestoes?q='.rawurlencode("limite-{$token}")
        );

        $response->assertOk();
        $this->assertCount(20, $response->json());
        $this->assertContains("atributo_limite_{$token}_1", $response->json());
    }

    public function test_sugestoes_de_valores_encontram_nomes_equivalentes_e_mantem_valor_original(): void
    {
        $token = strtolower(Str::random(8));

        $this->adicionarAtributo("Tipo de Madeira {$token}", 'Azul Marinho');
        $this->adicionarAtributo("tipo-de-madeira_{$token}", 'Azul Marinho');
        $this->adicionarAtributo("TIPO_DE_MADEIRA-{$token}", 'azul-marinho');
        $this->adicionarAtributo("tipo.de.madeira {$token}", 'Verde');

        $nome = rawurlencode("TIPO-DE-MADEIRA_{$token}");

        $this->getJson("/api/v1/atributos/{$nome}/valores?q=".rawurlencode('AZÚL_MARINHO'))
            ->assertOk()
            ->assertExactJson(['Azul Marinho']);

        $this->getJson("/api/v1/atributos/{$nome}/valores")
            ->assertOk()
            ->assertExactJson(['Azul Marinho', 'Verde']);
    }

    private function criarProdutoBase(): int
    {
        $now = now();
        $sufixo = uniqid();

        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => "Categoria Atributos {$sufixo}",
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fornecedorId = DB::table('fornecedores')->insertGetId([
            'nome' => "Fornecedor Atributos {$sufixo}",
            'cnpj' => null,
            'email' => null,
            'telefone' => null,
            'endereco' => null,
            'status' => 1,
            'observacoes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('produtos')->insertGetId([
            'nome' => "Produto Atributos {$sufixo}",
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
    }

    private function adicionarAtributo(string $atributo, string $valor): void
    {
        $now = now();
        $this->sequenciaVariacao++;

        $variacaoId = DB::table('produto_variacoes')->insertGetId([
            'produto_id' => $this->produtoId,
            'referencia' => 'REF-ATR-'.uniqid().'-'.$this->sequenciaVariacao,
            'nome' => 'Variacao de teste',
            'preco' => 100,
            'custo' => 50,
            'codigo_barras' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('produto_variacao_atributos')->insert([
            'id_variacao' => $variacaoId,
            'atributo' => $atributo,
            'valor' => $valor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
