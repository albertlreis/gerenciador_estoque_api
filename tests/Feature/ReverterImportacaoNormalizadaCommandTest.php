<?php

namespace Tests\Feature;

use App\Enums\EstoqueMovimentacaoTipo;
use App\Enums\ImportacaoNormalizadaStatus;
use App\Models\ImportacaoNormalizada;
use App\Models\ImportacaoNormalizadaLinha;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReverterImportacaoNormalizadaCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dry_run_lista_movimentacoes_e_execucao_reverte_por_estorno_sem_mexer_no_catalogo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-24 15:00:00'));

        $depositoId = $this->criarDeposito('Loja');
        $produtoId = $this->criarProduto([
            'nome' => 'Produto Duplicado',
            'codigo_produto' => 'COD-DUPLICADO',
        ]);
        $variacaoId = $this->criarVariacao($produtoId, [
            'nome' => 'Produto Duplicado Azul',
            'referencia' => 'REF-DUP',
            'sku_interno' => 'SKU-DUP',
        ]);

        $importacaoOriginal = $this->criarImportacao('hash-original', ImportacaoNormalizadaStatus::EFETIVADA, [
            'relatorio_final' => ['total_movimentacoes_criadas' => 1],
        ]);
        $importacaoDuplicada = $this->criarImportacao('hash-duplicado', ImportacaoNormalizadaStatus::EFETIVADA, [
            'relatorio_final' => ['total_movimentacoes_criadas' => 2],
        ]);

        $this->criarLinhaImportacao($importacaoOriginal->id, 1, 'ORIG-001');
        $linha1 = $this->criarLinhaImportacao($importacaoDuplicada->id, 1, 'DUP-001');
        $linha2 = $this->criarLinhaImportacao($importacaoDuplicada->id, 2, 'DUP-002');

        DB::table('estoque')->updateOrInsert(
            [
                'id_variacao' => $variacaoId,
                'id_deposito' => $depositoId,
            ],
            [
                'quantidade' => 9,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('estoque_movimentacoes')->insert([
            [
                'id_variacao' => $variacaoId,
                'id_deposito_origem' => null,
                'id_deposito_destino' => $depositoId,
                'id_usuario' => null,
                'lote_id' => null,
                'ref_type' => 'importacao_normalizada_linha',
                'ref_id' => $linha1->id,
                'pedido_id' => null,
                'pedido_item_id' => null,
                'reserva_id' => null,
                'tipo' => EstoqueMovimentacaoTipo::ENTRADA_DEPOSITO->value,
                'quantidade' => 2,
                'observacao' => 'Importação normalizada #2',
                'data_movimentacao' => now()->subMinute(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id_variacao' => $variacaoId,
                'id_deposito_origem' => null,
                'id_deposito_destino' => $depositoId,
                'id_usuario' => null,
                'lote_id' => null,
                'ref_type' => 'importacao_normalizada_linha',
                'ref_id' => $linha2->id,
                'pedido_id' => null,
                'pedido_item_id' => null,
                'reserva_id' => null,
                'tipo' => EstoqueMovimentacaoTipo::ENTRADA_DEPOSITO->value,
                'quantidade' => 3,
                'observacao' => 'Importação normalizada #2',
                'data_movimentacao' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $movimentacaoIds = DB::table('estoque_movimentacoes')
            ->where('ref_type', 'importacao_normalizada_linha')
            ->whereIn('ref_id', [$linha1->id, $linha2->id])
            ->pluck('id');

        $this->artisan('importacoes:reverter-normalizada', [
            'importacao_id' => $importacaoDuplicada->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Movimentações alvo: 2')
            ->expectsOutputToContain('A estornar agora: 2')
            ->expectsOutputToContain('Chaves com saldo insuficiente: 0')
            ->assertExitCode(0);

        $this->artisan('importacoes:reverter-normalizada', [
            'importacao_id' => $importacaoDuplicada->id,
        ])->assertExitCode(0);

        $this->assertSame(4, (int) DB::table('estoque')->where([
            'id_variacao' => $variacaoId,
            'id_deposito' => $depositoId,
        ])->value('quantidade'));

        $this->assertSame(2, DB::table('estoque_movimentacoes')
            ->where('ref_type', 'estorno')
            ->whereIn('ref_id', $movimentacaoIds)
            ->count());
        $this->assertDatabaseHas('importacoes_normalizadas', [
            'id' => $importacaoDuplicada->id,
            'status' => ImportacaoNormalizadaStatus::CANCELADA->value,
        ]);
        $this->assertDatabaseHas('importacoes_normalizadas', [
            'id' => $importacaoOriginal->id,
            'status' => ImportacaoNormalizadaStatus::EFETIVADA->value,
        ]);
        $this->assertSame(1, DB::table('produtos')->where('id', $produtoId)->count());
        $this->assertSame(1, DB::table('produto_variacoes')->where('id', $variacaoId)->count());

        $this->artisan('importacoes:reverter-normalizada', [
            'importacao_id' => $importacaoDuplicada->id,
        ])
            ->expectsOutputToContain('Nenhuma nova movimentação precisou ser estornada.')
            ->assertExitCode(0);

        $this->assertSame(2, DB::table('estoque_movimentacoes')
            ->where('ref_type', 'estorno')
            ->whereIn('ref_id', $movimentacaoIds)
            ->count());
    }

    public function test_execucao_aborta_sem_alterar_dados_quando_houver_saldo_insuficiente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-24 15:10:00'));

        $depositoId = $this->criarDeposito('Depósito JB');
        $produtoId = $this->criarProduto([
            'nome' => 'Produto Sem Saldo',
            'codigo_produto' => 'COD-SEM-SALDO',
        ]);
        $variacaoId = $this->criarVariacao($produtoId, [
            'nome' => 'Produto Sem Saldo Vermelho',
            'referencia' => 'REF-SEM-SALDO',
        ]);

        $importacao = $this->criarImportacao('hash-sem-saldo', ImportacaoNormalizadaStatus::EFETIVADA);
        $linha = $this->criarLinhaImportacao($importacao->id, 1, 'SEM-001');

        DB::table('estoque')->updateOrInsert(
            [
                'id_variacao' => $variacaoId,
                'id_deposito' => $depositoId,
            ],
            [
                'quantidade' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $movimentacaoId = DB::table('estoque_movimentacoes')->insertGetId([
            'id_variacao' => $variacaoId,
            'id_deposito_origem' => null,
            'id_deposito_destino' => $depositoId,
            'id_usuario' => null,
            'lote_id' => null,
            'ref_type' => 'importacao_normalizada_linha',
            'ref_id' => $linha->id,
            'pedido_id' => null,
            'pedido_item_id' => null,
            'reserva_id' => null,
            'tipo' => EstoqueMovimentacaoTipo::ENTRADA_DEPOSITO->value,
            'quantidade' => 3,
            'observacao' => 'Importação normalizada com saldo insuficiente',
            'data_movimentacao' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('importacoes:reverter-normalizada', [
            'importacao_id' => $importacao->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Chaves com saldo insuficiente: 1')
            ->assertExitCode(1);

        $this->artisan('importacoes:reverter-normalizada', [
            'importacao_id' => $importacao->id,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('estoque')->where([
            'id_variacao' => $variacaoId,
            'id_deposito' => $depositoId,
        ])->value('quantidade'));
        $this->assertSame(0, DB::table('estoque_movimentacoes')
            ->where('ref_type', 'estorno')
            ->where('ref_id', $movimentacaoId)
            ->count());
        $this->assertDatabaseHas('importacoes_normalizadas', [
            'id' => $importacao->id,
            'status' => ImportacaoNormalizadaStatus::EFETIVADA->value,
        ]);
    }

    private function criarImportacao(
        string $arquivoHash,
        ImportacaoNormalizadaStatus $status,
        array $overrides = []
    ): ImportacaoNormalizada {
        return ImportacaoNormalizada::create(array_merge([
            'tipo' => 'planilha_sierra_carga_inicial',
            'arquivo_nome' => 'teste.xlsx',
            'arquivo_hash' => $arquivoHash,
            'usuario_id' => null,
            'status' => $status,
            'preview_resumo' => ['totais' => ['linhas_validas_para_efetivacao' => 1]],
            'confirmado_em' => now(),
            'efetivado_em' => $status === ImportacaoNormalizadaStatus::EFETIVADA ? now() : null,
        ], $overrides));
    }

    private function criarLinhaImportacao(int $importacaoId, int $linhaPlanilha, string $hashLinha): ImportacaoNormalizadaLinha
    {
        return ImportacaoNormalizadaLinha::create([
            'importacao_id' => $importacaoId,
            'aba_origem' => 'Sierra Loja',
            'linha_planilha' => $linhaPlanilha,
            'hash_linha' => $hashLinha,
            'status_processamento' => 'efetivada',
        ]);
    }

    private function criarDeposito(string $nome): int
    {
        return (int) DB::table('depositos')->insertGetId([
            'nome' => $nome,
            'endereco' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function criarProduto(array $overrides = []): int
    {
        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Categoria Rollback ' . uniqid(),
            'descricao' => null,
            'categoria_pai_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fornecedorId = DB::table('fornecedores')->insertGetId([
            'nome' => 'Fornecedor Rollback ' . uniqid(),
            'cnpj' => null,
            'email' => null,
            'telefone' => null,
            'endereco' => null,
            'status' => 1,
            'observacoes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('produtos')->insertGetId(array_merge([
            'nome' => 'Produto Rollback ' . uniqid(),
            'codigo_produto' => null,
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
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function criarVariacao(int $produtoId, array $overrides = []): int
    {
        return (int) DB::table('produto_variacoes')->insertGetId(array_merge([
            'produto_id' => $produtoId,
            'referencia' => 'REF-' . uniqid(),
            'nome' => 'Variação Rollback',
            'preco' => 100,
            'custo' => 60,
            'codigo_barras' => null,
            'sku_interno' => null,
            'chave_variacao' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
