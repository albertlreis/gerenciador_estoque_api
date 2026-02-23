<?php

namespace Tests\Feature;

use App\Models\PedidoImportacao;
use App\Models\Usuario;
use App\Services\ExtratorPedidoPythonService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PedidoImportacaoPdfPreviewCacheTest extends TestCase
{
    use DatabaseTransactions;

    public function test_importacao_reprocessa_quando_preview_em_cache_esta_vazio(): void
    {
        Storage::fake('local');

        $usuario = Usuario::create([
            'nome' => 'Usuario Preview',
            'email' => 'usuario_preview@example.com',
            'senha' => 'teste',
            'ativo' => 1,
        ]);

        $arquivo = UploadedFile::fake()->createWithContent('preview.pdf', '%PDF-1.4 preview');
        $tipoImportacao = 'PRODUTOS_PDF_QUAKER';
        $hashArquivo = hash_file('sha256', $arquivo->getRealPath());
        $hash = hash('sha256', $hashArquivo . '|' . $tipoImportacao);

        PedidoImportacao::create([
            'arquivo_nome' => 'preview.pdf',
            'arquivo_hash' => $hash,
            'usuario_id' => $usuario->id,
            'status' => 'extraido',
            'dados_json' => [
                'tipo_importacao' => $tipoImportacao,
                'pedido' => ['numero_externo' => ''],
                'itens' => [],
            ],
        ]);

        $mock = $this->mock(ExtratorPedidoPythonService::class);
        $mock->shouldReceive('processar')
            ->once()
            ->andReturn([
                'pedido' => [
                    'numero_pedido' => 'REPROC-001',
                    'data_pedido' => '01/01/2026',
                ],
                'itens' => [
                    [
                        'codigo' => 'REF-001',
                        'descricao' => 'Produto Reprocessado',
                        'quantidade' => '1.00',
                        'preco_unitario' => '10.00',
                        'preco' => '10.00',
                    ],
                ],
                'totais' => [
                    'total_liquido' => '10,00',
                ],
            ]);
        $this->app->instance(ExtratorPedidoPythonService::class, $mock);

        $response = $this->actingAs($usuario, 'sanctum')
            ->post('/api/v1/pedidos/import', [
                'tipo_importacao' => $tipoImportacao,
                'arquivo' => $arquivo,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('mensagem', 'Arquivo processado com sucesso.');
        $response->assertJsonPath('dados.pedido.numero_externo', 'REPROC-001');
        $response->assertJsonCount(1, 'dados.itens');
    }
}
