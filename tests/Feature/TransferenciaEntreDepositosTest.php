<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Estoque;
use App\Models\EstoqueMovimentacao;
use App\Models\EstoqueTransferencia;
use App\Models\Produto;
use App\Models\ProdutoVariacao;
use App\Models\Usuario;
use App\Services\EstoqueMovimentacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransferenciaEntreDepositosTest extends TestCase
{
    use RefreshDatabase;

    private function criarCenarioTransferencia(): array
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Transferencia',
            'email' => 'usuario.transferencia.' . uniqid() . '@example.test',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $categoria = Categoria::create(['nome' => 'Cat Transferencia']);
        $produto = Produto::create([
            'nome' => 'Produto Transferencia',
            'descricao' => 'Teste',
            'id_categoria' => $categoria->id,
            'ativo' => true,
        ]);
        $variacao = ProdutoVariacao::create([
            'produto_id' => $produto->id,
            'referencia' => 'TRF-1',
            'nome' => 'Variacao TRF',
            'preco' => 100,
            'custo' => 50,
        ]);

        $depOrigem = Deposito::create(['nome' => 'Deposito Origem']);
        $depDestino = Deposito::create(['nome' => 'Deposito Destino']);

        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $depOrigem->id],
            ['quantidade' => 10]
        );
        Estoque::updateOrCreate(
            ['id_variacao' => $variacao->id, 'id_deposito' => $depDestino->id],
            ['quantidade' => 0]
        );

        return [$usuario, $variacao, $depOrigem, $depDestino];
    }

    public function test_transferencia_lote_sucesso_ajusta_estoque_corretamente(): void
    {
        [$usuario, $variacao, $depOrigem, $depDestino] = $this->criarCenarioTransferencia();
        Sanctum::actingAs($usuario);

        $payload = [
            'tipo' => 'transferencia',
            'deposito_origem_id' => $depOrigem->id,
            'deposito_destino_id' => $depDestino->id,
            'observacao' => 'Teste transferencia',
            'itens' => [
                ['variacao_id' => $variacao->id, 'quantidade' => 3],
            ],
        ];

        $response = $this->postJson('/api/v1/estoque/movimentacoes/lote', $payload);
        $response->assertOk();
        $response->assertJsonPath('sucesso', true);
        $response->assertJsonPath('total_pecas', 3);
        $this->assertNotNull($response->json('transferencia_id'));
        $this->assertNotNull($response->json('transferencia_pdf'));

        $estoqueOrigem = Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $depOrigem->id)
            ->first();
        $estoqueDestino = Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $depDestino->id)
            ->first();

        $this->assertSame(7, (int) $estoqueOrigem->quantidade);
        $this->assertSame(3, (int) $estoqueDestino->quantidade);

        $movs = EstoqueMovimentacao::query()
            ->where('tipo', 'transferencia')
            ->where('id_variacao', $variacao->id)
            ->get();
        $this->assertCount(1, $movs);
        $this->assertSame((int) $depOrigem->id, (int) $movs[0]->id_deposito_origem);
        $this->assertSame((int) $depDestino->id, (int) $movs[0]->id_deposito_destino);
        $this->assertSame(3, (int) $movs[0]->quantidade);

        $transferencia = EstoqueTransferencia::query()->where('deposito_origem_id', $depOrigem->id)->first();
        $this->assertNotNull($transferencia);
        $this->assertSame(1, (int) $transferencia->total_itens);
        $this->assertSame(3, (int) $transferencia->total_pecas);
    }

    public function test_transferencia_saldo_insuficiente_retorna_422_nao_altera_estoque(): void
    {
        [$usuario, $variacao, $depOrigem, $depDestino] = $this->criarCenarioTransferencia();
        Sanctum::actingAs($usuario);

        $payload = [
            'tipo' => 'transferencia',
            'deposito_origem_id' => $depOrigem->id,
            'deposito_destino_id' => $depDestino->id,
            'itens' => [
                ['variacao_id' => $variacao->id, 'quantidade' => 999],
            ],
        ];

        $response = $this->postJson('/api/v1/estoque/movimentacoes/lote', $payload);
        $response->assertStatus(422);
        $body = $response->json();
        $this->assertTrue(
            isset($body['error']) && (str_contains((string) $body['error'], 'Saldo insuficiente') || str_contains((string) $body['error'], 'Disponível')),
            'Resposta deve indicar saldo insuficiente'
        );

        $estoqueOrigem = Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $depOrigem->id)
            ->first();
        $estoqueDestino = Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $depDestino->id)
            ->first();
        $this->assertSame(10, (int) $estoqueOrigem->quantidade);
        $this->assertSame(0, (int) $estoqueDestino->quantidade);
        $this->assertSame(0, EstoqueTransferencia::query()->count());
    }

    public function test_transferencia_origem_igual_destino_retorna_422(): void
    {
        [$usuario, $variacao, $depOrigem, $depDestino] = $this->criarCenarioTransferencia();
        Sanctum::actingAs($usuario);

        $payload = [
            'tipo' => 'transferencia',
            'deposito_origem_id' => $depOrigem->id,
            'deposito_destino_id' => $depOrigem->id,
            'itens' => [
                ['variacao_id' => $variacao->id, 'quantidade' => 1],
            ],
        ];

        $response = $this->postJson('/api/v1/estoque/movimentacoes/lote', $payload);
        $response->assertStatus(422);
        $this->assertSame(10, (int) Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $depOrigem->id)
            ->value('quantidade'));
    }

    public function test_transferencia_variacao_inexistente_retorna_422(): void
    {
        [$usuario, $variacao, $depOrigem, $depDestino] = $this->criarCenarioTransferencia();
        Sanctum::actingAs($usuario);

        $payload = [
            'tipo' => 'transferencia',
            'deposito_origem_id' => $depOrigem->id,
            'deposito_destino_id' => $depDestino->id,
            'itens' => [
                ['variacao_id' => 999999, 'quantidade' => 1],
            ],
        ];

        $response = $this->postJson('/api/v1/estoque/movimentacoes/lote', $payload);
        $response->assertStatus(422);
    }

    public function test_transferencia_saldo_inexistente_origem_retorna_422(): void
    {
        [$usuario, $variacao, $depOrigem, $depDestino] = $this->criarCenarioTransferencia();
        // Variação sem estoque no depósito origem (remover linha de estoque)
        Estoque::query()
            ->where('id_variacao', $variacao->id)
            ->where('id_deposito', $depOrigem->id)
            ->delete();

        Sanctum::actingAs($usuario);
        $payload = [
            'tipo' => 'transferencia',
            'deposito_origem_id' => $depOrigem->id,
            'deposito_destino_id' => $depDestino->id,
            'itens' => [
                ['variacao_id' => $variacao->id, 'quantidade' => 1],
            ],
        ];

        $response = $this->postJson('/api/v1/estoque/movimentacoes/lote', $payload);
        $response->assertStatus(422);
        $body = $response->json();
        $this->assertTrue(
            isset($body['error']) && (str_contains((string) $body['error'], 'inexistente') || str_contains((string) $body['error'], 'Saldo')),
            'Resposta deve indicar saldo inexistente'
        );
    }

    public function test_service_registrar_transferencia_lote_origem_destino_iguais_lanca_excecao(): void
    {
        [$usuario, $variacao, $depOrigem, $depDestino] = $this->criarCenarioTransferencia();
        $service = app(EstoqueMovimentacaoService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('não podem ser iguais');

        $service->registrarMovimentacaoLote([
            'tipo' => 'transferencia',
            'deposito_origem_id' => $depOrigem->id,
            'deposito_destino_id' => $depOrigem->id,
            'itens' => [
                ['variacao_id' => $variacao->id, 'quantidade' => 1],
            ],
        ], (int) $usuario->id);
    }
}
