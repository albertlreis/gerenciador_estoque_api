<?php

namespace Tests\Feature\Integrations;

use App\Integrations\ContaAzul\ContaAzulEntityType;
use App\Integrations\ContaAzul\Models\ContaAzulImportBatch;
use App\Integrations\ContaAzul\Models\ContaAzulSyncLog;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContaAzulOperationalEndpointsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $usuario = Usuario::create([
            'nome' => 'Usuário Conta Azul',
            'email' => 'conta-azul-operacional+' . uniqid() . '@example.com',
            'senha' => 'senha',
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, [
            'conta_azul.visualizar',
            'conta_azul.configurar',
            'conta_azul.importar',
            'conta_azul.conciliar',
            'conta_azul.auditar',
        ], now()->addHour());
    }

    public function test_lista_pendencias_com_paginacao_e_filtro_de_bucket(): void
    {
        DB::table('stg_conta_azul_pessoas')->insert([
            [
                'loja_id' => null,
                'identificador_externo' => 'pessoa-sugestao',
                'payload_json' => json_encode(['nome' => 'Cliente Sugestão']),
                'hash_payload' => hash('sha256', 'pessoa-sugestao'),
                'status_conciliacao' => 'pendente',
                'observacao_conciliacao' => 'Com candidato',
                'candidato_id_local' => 10,
                'candidato_score' => 88,
                'candidato_motivo' => 'Nome similar',
                'candidato_json' => json_encode(['id_local' => 10, 'label' => 'Cliente Local']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'loja_id' => null,
                'identificador_externo' => 'pessoa-sem-candidato',
                'payload_json' => json_encode(['nome' => 'Cliente Pendente']),
                'hash_payload' => hash('sha256', 'pessoa-sem-candidato'),
                'status_conciliacao' => 'pendente',
                'observacao_conciliacao' => 'Sem candidato',
                'candidato_id_local' => null,
                'candidato_score' => null,
                'candidato_motivo' => null,
                'candidato_json' => null,
                'created_at' => now(),
                'updated_at' => now()->subMinute(),
            ],
        ]);

        $response = $this->getJson('/api/v1/integrations/conta-azul/pendencias/detalhes?bucket=sugestao&per_page=1&page=1');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.identificador_externo', 'pessoa-sugestao')
            ->assertJsonPath('data.0.candidato_id_local', 10);
    }

    public function test_lista_batches_com_duracao_e_detalhe_do_batch(): void
    {
        $batch = ContaAzulImportBatch::create([
            'tipo_entidade' => ContaAzulEntityType::PESSOA,
            'status' => 'concluido',
            'total_lidos' => 2,
            'total_pendentes' => 1,
            'total_falhas' => 0,
            'iniciado_em' => now()->subSeconds(75),
            'finalizado_em' => now(),
        ]);

        DB::table('stg_conta_azul_pessoas')->insert([
            'loja_id' => null,
            'identificador_externo' => 'pessoa-batch',
            'payload_json' => json_encode(['nome' => 'Cliente Batch']),
            'hash_payload' => hash('sha256', 'pessoa-batch'),
            'status_conciliacao' => 'pendente',
            'observacao_conciliacao' => 'Sem candidato',
            'batch_id' => $batch->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/integrations/conta-azul/batches?per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.duracao_segundos', 75)
            ->assertJsonPath('data.0.duracao_label', '1min 15s');

        $this->getJson("/api/v1/integrations/conta-azul/batches/{$batch->id}")
            ->assertOk()
            ->assertJsonPath('data.batch.id', $batch->id)
            ->assertJsonPath('data.registros.0.identificador_externo', 'pessoa-batch');
    }

    public function test_lista_logs_com_paginacao_e_filtros(): void
    {
        ContaAzulSyncLog::create([
            'tipo_entidade' => ContaAzulEntityType::PESSOA,
            'id_externo' => 'pessoa-log',
            'direcao' => 'import',
            'status' => 'ignorado',
            'tentativa' => 1,
            'erro_mensagem' => 'Sem candidato',
            'executado_em' => now(),
        ]);
        ContaAzulSyncLog::create([
            'tipo_entidade' => ContaAzulEntityType::PRODUTO,
            'id_externo' => 'produto-log',
            'direcao' => 'export',
            'status' => 'sucesso',
            'tentativa' => 1,
            'executado_em' => now(),
        ]);

        $response = $this->getJson('/api/v1/integrations/conta-azul/sync-logs?status=ignorado&tipo_entidade=pessoa&direcao=import&per_page=10');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'ignorado')
            ->assertJsonPath('data.0.tipo_entidade', 'pessoa');
    }

    public function test_lookup_local_retorna_pessoas_produtos_e_financeiro(): void
    {
        DB::table('clientes')->insert([
            'nome' => 'Ana Sierra',
            'documento' => '12345678901',
            'email' => 'ana@example.com',
            'tipo' => 'pf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoriaId = DB::table('categorias')->insertGetId([
            'nome' => 'Acessórios',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('produtos')->insert([
            'nome' => 'Bracelete Azul',
            'codigo_produto' => 'BR-AZUL',
            'id_categoria' => $categoriaId,
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('contas_receber')->insert([
            'descricao' => 'Parcela Conta Azul',
            'numero_documento' => 'REC-123',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDay()->toDateString(),
            'valor_bruto' => 300,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'ABERTA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/integrations/conta-azul/local-lookup?entidade=pessoa&q=Ana')
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Ana Sierra')
            ->assertJsonPath('data.0.type', 'cliente');

        $this->getJson('/api/v1/integrations/conta-azul/local-lookup?entidade=produto&q=Bracelete')
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Bracelete Azul')
            ->assertJsonPath('data.0.type', 'produto');

        $this->getJson('/api/v1/integrations/conta-azul/local-lookup?entidade=titulo&q=Parcela')
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Parcela Conta Azul')
            ->assertJsonPath('data.0.type', 'conta_receber');
    }

    public function test_lookup_local_respeita_limite_busca_curta_e_entidade_invalida(): void
    {
        DB::table('clientes')->insert([
            [
                'nome' => 'Maria Um',
                'tipo' => 'pf',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nome' => 'Maria Dois',
                'tipo' => 'pf',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->getJson('/api/v1/integrations/conta-azul/local-lookup?entidade=pessoa&q=M')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/integrations/conta-azul/local-lookup?entidade=pessoa&q=Maria&limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/integrations/conta-azul/local-lookup?entidade=nota&q=NF')
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }
}
