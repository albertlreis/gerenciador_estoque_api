<?php

namespace Tests\Feature;

use App\Models\Pedido;
use App\Models\PedidoImportacao;
use App\Models\PedidoImportacaoItem;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RepararAcabamentosImportacaoPedidoCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dry_run_execucao_idempotente_e_rollback_por_manifesto(): void
    {
        Storage::fake('local');
        [$importacao, $variacao] = $this->criarCenario();

        $this->artisan('pedidos:reparar-acabamentos-importacao', [
            'pedido_importacao_id' => $importacao->id,
            '--manifest' => 'testes/dry-run.json',
        ])
            ->expectsOutputToContain('Dry-run concluido.')
            ->assertExitCode(0);
        $this->assertSame(1, $variacao->atributos()->count());
        $this->assertSame('pendente', $this->lerManifesto('testes/dry-run.json')['itens'][0]['acao']);

        $this->artisan('pedidos:reparar-acabamentos-importacao', [
            'pedido_importacao_id' => $importacao->id,
            '--manifest' => 'testes/dry-run.json',
        ])
            ->expectsOutputToContain('O manifesto de destino ja existe')
            ->assertExitCode(1);
        $this->assertSame(1, $variacao->atributos()->count());

        $this->artisan('pedidos:reparar-acabamentos-importacao', [
            'pedido_importacao_id' => $importacao->id,
            '--execute' => true,
            '--manifest' => 'testes/execucao.json',
        ])->assertExitCode(0);
        $this->assertDatabaseMissing('produto_variacao_atributos', [
            'id_variacao' => $variacao->id,
            'atributo' => 'acabamentos',
            'valor' => 'AC25#E-13233 - OFF WHITE#MESMA COR DO TECIDO#I-24604',
        ]);
        $this->assertDatabaseHas('produto_variacao_atributos', [
            'id_variacao' => $variacao->id,
            'atributo' => 'madeira',
            'valor' => 'AC25',
        ]);
        $this->assertDatabaseHas('produto_variacao_atributos', [
            'id_variacao' => $variacao->id,
            'atributo' => 'tecido_1',
            'valor' => 'E-13233 - OFF WHITE',
        ]);
        $this->assertSame(2, $variacao->atributos()->where('atributo', 'tecido_2')->count());
        $this->assertSame('convertido', $this->lerManifesto('testes/execucao.json')['itens'][0]['acao']);

        $this->artisan('pedidos:reparar-acabamentos-importacao', [
            'pedido_importacao_id' => $importacao->id,
            '--execute' => true,
            '--manifest' => 'testes/segunda-execucao.json',
        ])->assertExitCode(0);
        $this->assertSame('ja_corrigido', $this->lerManifesto('testes/segunda-execucao.json')['itens'][0]['acao']);
        $this->assertSame(4, $variacao->atributos()->count());

        $this->artisan('pedidos:reparar-acabamentos-importacao', [
            'pedido_importacao_id' => $importacao->id,
            '--rollback' => 'testes/execucao.json',
            '--execute' => true,
            '--manifest' => 'testes/rollback.json',
        ])->assertExitCode(0);
        $this->assertSame(1, $variacao->atributos()->count());
        $this->assertDatabaseHas('produto_variacao_atributos', [
            'id_variacao' => $variacao->id,
            'atributo' => 'acabamentos',
            'valor' => 'AC25#E-13233 - OFF WHITE#MESMA COR DO TECIDO#I-24604',
        ]);
        $this->assertSame('revertido', $this->lerManifesto('testes/rollback.json')['itens'][0]['acao']);
    }

    public function test_nao_sobrescreve_atributo_existente_que_indica_correcao_manual(): void
    {
        Storage::fake('local');
        [$importacao, $variacao] = $this->criarCenario();
        $variacao->atributos()->create(['atributo' => 'madeira', 'valor' => 'AC15']);

        $this->artisan('pedidos:reparar-acabamentos-importacao', [
            'pedido_importacao_id' => $importacao->id,
            '--execute' => true,
            '--manifest' => 'testes/conflito.json',
        ])->assertExitCode(0);

        $this->assertSame('conflito_atributos_existentes', $this->lerManifesto('testes/conflito.json')['itens'][0]['acao']);
        $this->assertSame(1, $variacao->atributos()->where('atributo', 'acabamentos')->count());
        $this->assertSame(2, $variacao->atributos()->count());
    }

    public function test_converte_model_raw_quando_a_variacao_nao_tem_atributo_legado(): void
    {
        Storage::fake('local');
        [$importacao, $variacao] = $this->criarCenario(false);

        $this->artisan('pedidos:reparar-acabamentos-importacao', [
            'pedido_importacao_id' => $importacao->id,
            '--execute' => true,
            '--manifest' => 'testes/sem-legado.json',
        ])->assertExitCode(0);

        $this->assertSame('criado', $this->lerManifesto('testes/sem-legado.json')['itens'][0]['acao']);
        $this->assertSame(4, $variacao->atributos()->count());
    }

    public function test_rejeita_manifesto_de_rollback_adulterado(): void
    {
        Storage::fake('local');
        [$importacao] = $this->criarCenario();
        Storage::disk('local')->put('testes/adulterado.json', json_encode([
            'versao' => 2,
            'modo' => 'execucao',
            'pedido_importacao_id' => $importacao->id,
            'itens' => [],
            'checksum' => str_repeat('0', 64),
        ], JSON_THROW_ON_ERROR));

        $this->artisan('pedidos:reparar-acabamentos-importacao', [
            'pedido_importacao_id' => $importacao->id,
            '--rollback' => 'testes/adulterado.json',
            '--execute' => true,
            '--manifest' => 'testes/nao-deve-existir.json',
        ])
            ->expectsOutputToContain('checksum divergente')
            ->assertExitCode(1);

        Storage::disk('local')->assertMissing('testes/nao-deve-existir.json');
    }

    private function criarCenario(bool $comLegado = true): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Reparo Acabamentos',
            'email' => 'reparo-acabamentos-'.uniqid().'@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);
        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Camas '.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $produto = Produto::create([
            'nome' => 'CAMA DAMIANI KING',
            'id_categoria' => $categoriaId,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => '3545K',
            'nome' => 'CAMA DAMIANI KING',
            'preco' => 13913.62,
        ]);
        $pedido = Pedido::create([
            'tipo' => Pedido::TIPO_REPOSICAO,
            'id_usuario' => $usuario->id,
            'data_pedido' => now(),
            'valor_total' => 13913.62,
        ]);
        $importacao = PedidoImportacao::create([
            'arquivo_nome' => 'pedido-acabamentos.xml',
            'arquivo_hash' => hash('sha256', uniqid('acabamentos', true)),
            'pedido_id' => $pedido->id,
            'usuario_id' => $usuario->id,
            'status' => 'confirmado',
        ]);
        PedidoImportacaoItem::create([
            'pedido_importacao_id' => $importacao->id,
            'pedido_id' => $pedido->id,
            'produto_id' => $produto->id,
            'produto_variacao_id' => $variacao->id,
            'acao' => 'criado',
            'dados_importados_json' => [
                'atributos_raw' => [[
                    'nome' => 'modelo_referencia',
                    'valor' => 'AC25#E-13233 - OFF WHITE#MESMA COR DO TECIDO#I-24604',
                ]],
            ],
            'dados_confirmados_json' => ['atributos_lista' => []],
        ]);

        if ($comLegado) {
            $variacao->atributos()->create([
                'atributo' => 'acabamentos',
                'valor' => 'AC25#E-13233 - OFF WHITE#MESMA COR DO TECIDO#I-24604',
            ]);
        }

        return [$importacao, $variacao];
    }

    private function lerManifesto(string $caminho): array
    {
        return json_decode(Storage::disk('local')->get($caminho), true, 512, JSON_THROW_ON_ERROR);
    }
}
