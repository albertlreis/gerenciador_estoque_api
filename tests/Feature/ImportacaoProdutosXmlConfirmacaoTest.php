<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImportacaoProdutosXmlConfirmacaoTest extends TestCase
{
    private function autenticarComPermissao(): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Importacao XML',
            'email' => 'importacao.produtos.xml.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, ['produtos.importar'], now()->addHour());

        return $usuario;
    }

    private function criarCategoria(): int
    {
        return DB::table('categorias')->insertGetId([
            'nome' => 'Categoria XML',
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function criarDeposito(): int
    {
        return DB::table('depositos')->insertGetId([
            'nome' => 'Deposito XML',
            'endereco' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payload(array $itemOverrides = []): array
    {
        $categoriaId = $this->criarCategoria();
        $depositoId = $this->criarDeposito();
        $tokenXml = 'xml-confirmacao-teste.xml';
        $sufixo = strtoupper(substr(uniqid('', true), -6));

        Storage::disk('local')->put("importacoes/tmp/{$tokenXml}", '<nfeProc></nfeProc>');

        $item = array_merge([
            'descricao_xml' => 'Produto XML - COR AZUL',
            'referencia' => "REF-XML-{$sufixo}",
            'unidade' => 'UN',
            'quantidade' => 2,
            'custo_unitario' => 50.25,
            'valor_total' => 100.50,
            'preco' => 90,
            'descricao_final' => "Produto XML {$sufixo}",
            'id_categoria' => $categoriaId,
            'variacao_id' => null,
            'variacao_id_manual' => null,
            'atributos' => [
                ['atributo' => 'Cor', 'valor' => 'Azul'],
                ['atributo' => 'Material', 'valor' => 'inox'],
            ],
        ], $itemOverrides);

        return [
            'nota' => [
                'numero' => '123',
                'data_emissao' => '2026-06-25T10:00:00-03:00',
                'fornecedor_cnpj' => '12345678000199',
                'fornecedor_nome' => 'Fornecedor XML',
            ],
            'deposito_id' => $depositoId,
            'token_xml' => $tokenXml,
            'data_entrada' => '2026-06-25',
            'produtos' => [$item],
        ];
    }

    public function test_confirmacao_cria_produto_variacao_atributos_estoque_e_movimentacao(): void
    {
        Storage::fake('local');
        $this->autenticarComPermissao();
        $payload = $this->payload();
        $item = $payload['produtos'][0];

        $response = $this->postJson('/api/v1/produtos/importacoes/xml/confirmar', $payload);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $produtoId = DB::table('produtos')->where('nome', $item['descricao_final'])->value('id');
        $this->assertNotNull($produtoId);

        $variacaoId = DB::table('produto_variacoes')->where('referencia', $item['referencia'])->value('id');
        $this->assertNotNull($variacaoId);

        $this->assertDatabaseHas('produto_variacao_atributos', [
            'id_variacao' => $variacaoId,
            'atributo' => 'cor',
            'valor' => 'Azul',
        ]);

        $this->assertDatabaseHas('produto_variacao_atributos', [
            'id_variacao' => $variacaoId,
            'atributo' => 'material',
            'valor' => 'Inox',
        ]);

        $this->assertDatabaseHas('estoque', [
            'id_variacao' => $variacaoId,
            'quantidade' => 2,
        ]);

        $this->assertDatabaseHas('estoque_movimentacoes', [
            'id_variacao' => $variacaoId,
            'tipo' => 'entrada',
            'quantidade' => 2,
        ]);

        Storage::disk('local')->assertMissing('importacoes/tmp/xml-confirmacao-teste.xml');
    }

    public function test_produto_novo_sem_categoria_retorna_422(): void
    {
        Storage::fake('local');
        $this->autenticarComPermissao();
        $payload = $this->payload([
            'id_categoria' => null,
        ]);
        $referencia = $payload['produtos'][0]['referencia'];

        $response = $this->postJson('/api/v1/produtos/importacoes/xml/confirmar', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['produtos.0.id_categoria']);

        $this->assertDatabaseMissing('produto_variacoes', ['referencia' => $referencia]);
    }

    public function test_produto_novo_sem_referencia_retorna_422(): void
    {
        Storage::fake('local');
        $this->autenticarComPermissao();
        $payload = $this->payload([
            'referencia' => null,
        ]);
        $nome = $payload['produtos'][0]['descricao_final'];

        $response = $this->postJson('/api/v1/produtos/importacoes/xml/confirmar', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['produtos.0.referencia']);

        $this->assertDatabaseMissing('produtos', ['nome' => $nome]);
    }

    public function test_quantidade_decimal_retorna_422_sem_truncar_estoque(): void
    {
        Storage::fake('local');
        $this->autenticarComPermissao();
        $payload = $this->payload([
            'quantidade' => 1.5,
        ]);
        $referencia = $payload['produtos'][0]['referencia'];

        $response = $this->postJson('/api/v1/produtos/importacoes/xml/confirmar', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['produtos.0.quantidade']);

        $this->assertDatabaseMissing('produto_variacoes', ['referencia' => $referencia]);
        $estoqueTruncado = DB::table('estoque')
            ->join('produto_variacoes', 'produto_variacoes.id', '=', 'estoque.id_variacao')
            ->where('produto_variacoes.referencia', $referencia)
            ->where('estoque.quantidade', 1)
            ->exists();
        $this->assertFalse($estoqueTruncado);
    }

    public function test_atributos_duplicados_retorna_422_sem_erro_sql(): void
    {
        Storage::fake('local');
        $this->autenticarComPermissao();
        $payload = $this->payload([
            'atributos' => [
                ['atributo' => 'Cor', 'valor' => 'Azul'],
                ['atributo' => ' cor ', 'valor' => 'Verde'],
            ],
        ]);
        $referencia = $payload['produtos'][0]['referencia'];

        $response = $this->postJson('/api/v1/produtos/importacoes/xml/confirmar', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['produtos.0.atributos.1.atributo']);

        $this->assertDatabaseMissing('produto_variacoes', ['referencia' => $referencia]);
    }
}
