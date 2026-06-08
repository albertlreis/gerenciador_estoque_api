<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Models\ContaPagar;
use App\Services\AuditoriaLogService;
use App\Services\FinanceiroAuditoriaService;
use App\Support\Auditoria\LaravelLogFileParser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditoriaLogsApiTest extends TestCase
{
    private function autenticar(array $permissoes = ['auditoria.logs.visualizar'], array $perfis = []): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Auditoria',
            'email' => 'auditoria.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, $permissoes, now()->addHour());
        Cache::put('perfis_usuario_' . $usuario->id, $perfis, now()->addHour());

        foreach ($perfis as $perfil) {
            $this->atribuirPerfil($usuario, $perfil);
        }

        return $usuario;
    }

    private function usuarioComPerfil(string $perfil, string $nome): Usuario
    {
        $usuario = Usuario::create([
            'nome' => $nome,
            'email' => strtolower(str_replace(' ', '.', $nome)) . '.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        $this->atribuirPerfil($usuario, $perfil);

        return $usuario;
    }

    private function atribuirPerfil(Usuario $usuario, string $perfil): void
    {
        $this->ensureProfileTables();

        DB::table('acesso_perfis')->updateOrInsert(
            ['nome' => $perfil],
            ['descricao' => $perfil, 'created_at' => now(), 'updated_at' => now()]
        );

        $perfilId = DB::table('acesso_perfis')->where('nome', $perfil)->value('id');

        DB::table('acesso_usuario_perfil')->updateOrInsert(
            ['id_usuario' => $usuario->id, 'id_perfil' => $perfilId],
            ['created_at' => now(), 'updated_at' => now()]
        );
    }

    private function ensureProfileTables(): void
    {
        if (!Schema::hasTable('acesso_perfis')) {
            Schema::create('acesso_perfis', function (Blueprint $table): void {
                $table->id();
                $table->string('nome')->unique();
                $table->string('descricao')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('acesso_usuario_perfil')) {
            Schema::create('acesso_usuario_perfil', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('id_usuario');
                $table->unsignedBigInteger('id_perfil');
                $table->timestamps();
                $table->unique(['id_usuario', 'id_perfil'], 'uq_usuario_perfil_test');
                $table->index('id_usuario');
                $table->index('id_perfil');
            });
        }
    }

    private function criarLog(?Usuario $actor, string $label, array $overrides = []): int
    {
        return (int) DB::table('auditoria_logs')->insertGetId(array_merge([
            'occurred_at' => now(),
            'tipo' => 'auditoria',
            'categoria' => 'negocio',
            'nivel' => 'info',
            'modulo' => 'usuarios',
            'acao' => 'update',
            'label' => $label,
            'message' => $label,
            'actor_type' => $actor ? Usuario::class : null,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->nome,
            'source_system' => 'estoque',
            'source_kind' => 'test',
            'retention_days' => 365,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_servico_redige_segredos_e_grava_mudancas(): void
    {
        app(AuditoriaLogService::class)->registrar([
            'occurred_at' => now(),
            'tipo' => 'auditoria',
            'categoria' => 'negocio',
            'modulo' => 'usuarios',
            'acao' => 'update',
            'label' => 'Usuario atualizado',
            'context_json' => [
                'access_token' => 'abc123',
                'nested' => ['senha' => 'segredo'],
                'ok' => 'visivel',
            ],
            'raw_excerpt' => 'authorization=Bearer abc123 token=segredo',
            'source_system' => 'estoque',
            'source_kind' => 'test',
            'source_table' => 'test_source',
            'source_id' => '1',
        ], [[
            'campo' => 'senha',
            'old' => 'antiga',
            'new' => 'nova',
        ]]);

        $log = DB::table('auditoria_logs')->where('source_table', 'test_source')->first();
        $this->assertNotNull($log);

        $context = json_decode($log->context_json, true);
        $this->assertSame('[REDACTED]', $context['access_token']);
        $this->assertSame('[REDACTED]', $context['nested']['senha']);
        $this->assertSame('visivel', $context['ok']);
        $this->assertStringNotContainsString('abc123', (string) $log->raw_excerpt);

        $this->assertDatabaseHas('auditoria_log_mudancas', [
            'auditoria_log_id' => $log->id,
            'campo' => 'senha',
            'old_value' => '[REDACTED]',
            'new_value' => '[REDACTED]',
        ]);
    }

    public function test_endpoint_lista_filtra_e_exibe_detalhe(): void
    {
        $usuario = $this->autenticar();

        app(AuditoriaLogService::class)->registrar([
            'tipo' => 'auditoria',
            'categoria' => 'negocio',
            'modulo' => 'financeiro',
            'acao' => 'created',
            'label' => 'Conta criada',
            'source_system' => 'estoque',
            'source_kind' => 'test',
            'source_table' => 'financeiro_auditorias',
            'source_id' => '10',
        ]);

        app(AuditoriaLogService::class)->registrar([
            'tipo' => 'integracao',
            'categoria' => 'integracao',
            'modulo' => 'conta_azul',
            'acao' => 'import',
            'status' => 'erro',
            'label' => 'Falha Conta Azul',
            'source_system' => 'estoque',
            'source_kind' => 'test',
            'source_table' => 'conta_azul_sync_logs',
            'source_id' => '11',
        ]);

        $response = $this->getJson('/api/v1/auditoria/logs?modulo=financeiro&per_page=10');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.modulo', 'financeiro')
            ->assertJsonPath('data.0.source.table', 'financeiro_auditorias');

        $id = $response->json('data.0.id');
        $this->getJson("/api/v1/auditoria/logs/{$id}")
            ->assertOk()
            ->assertJsonPath('data.label', 'Conta criada');
    }

    public function test_endpoint_lista_tolera_filtros_array_na_query_string(): void
    {
        $this->autenticar();

        app(AuditoriaLogService::class)->registrar([
            'tipo' => 'integracao',
            'categoria' => 'integracao',
            'modulo' => 'conta_azul',
            'acao' => 'sync',
            'label' => 'Pedido Conta Azul sincronizado',
            'source_system' => 'estoque',
            'source_kind' => 'sync',
            'source_table' => 'conta_azul_sync_logs',
            'source_id' => 'array-query-test',
        ]);

        app(AuditoriaLogService::class)->registrar([
            'tipo' => 'integracao',
            'categoria' => 'integracao',
            'modulo' => 'conta_azul',
            'acao' => 'sync',
            'label' => 'Cliente Conta Azul sincronizado',
            'source_system' => 'estoque',
            'source_kind' => 'import_run',
            'source_table' => 'conta_azul_sync_logs',
            'source_id' => 'array-query-other',
        ]);

        $response = $this->getJson('/api/v1/auditoria/logs?modulo[]=conta_azul&source_kind[]=sync&q[]=Pedido&page[]=1&per_page[]=10');

        $response->assertOk()
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.modulo', 'conta_azul')
            ->assertJsonPath('data.0.source.kind', 'sync')
            ->assertJsonPath('data.0.label', 'Pedido Conta Azul sincronizado');
    }

    public function test_desenvolvedor_visualiza_todos_os_logs_e_logs_sem_ator(): void
    {
        $this->autenticar([], ['Desenvolvedor']);
        $admin = $this->usuarioComPerfil('Administrador', 'Admin Auditavel');
        $vendedor = $this->usuarioComPerfil('Vendedor', 'Vendedor Auditavel');
        $sourceTable = 'visibility_dev_' . uniqid();

        $this->criarLog($admin, 'Log admin', ['source_table' => $sourceTable]);
        $this->criarLog($vendedor, 'Log vendedor', ['source_table' => $sourceTable]);
        $semAtorId = $this->criarLog(null, 'Log tecnico sem ator', ['categoria' => 'tecnico', 'source_table' => $sourceTable]);

        $response = $this->getJson('/api/v1/auditoria/logs?per_page=100&source_table=' . $sourceTable);

        $response->assertOk()->assertJsonPath('meta.total', 3);
        $labels = collect($response->json('data'))->pluck('label');
        $this->assertTrue($labels->contains('Log admin'));
        $this->assertTrue($labels->contains('Log vendedor'));
        $this->assertTrue($labels->contains('Log tecnico sem ator'));

        $this->getJson("/api/v1/auditoria/logs/{$semAtorId}")
            ->assertOk()
            ->assertJsonPath('data.label', 'Log tecnico sem ator');
    }

    public function test_administrador_visualiza_admin_e_perfis_abaixo_mas_nao_dev_nem_sem_ator(): void
    {
        $this->autenticar([], ['Administrador']);
        $admin = $this->usuarioComPerfil('Administrador', 'Outro Admin');
        $financeiro = $this->usuarioComPerfil('Financeiro', 'Financeiro Auditavel');
        $estoquista = $this->usuarioComPerfil('Estoquista', 'Estoque Auditavel');
        $vendedor = $this->usuarioComPerfil('Vendedor', 'Vendedor Auditavel');
        $dev = $this->usuarioComPerfil('Desenvolvedor', 'Dev Auditavel');
        $sourceTable = 'visibility_admin_' . uniqid();

        $this->criarLog($admin, 'Log outro admin', ['source_table' => $sourceTable]);
        $this->criarLog($financeiro, 'Log financeiro', ['source_table' => $sourceTable]);
        $this->criarLog($estoquista, 'Log estoque', ['source_table' => $sourceTable]);
        $this->criarLog($vendedor, 'Log vendedor', ['source_table' => $sourceTable]);
        $devLogId = $this->criarLog($dev, 'Log dev', ['source_table' => $sourceTable]);
        $semAtorId = $this->criarLog(null, 'Log sem ator', ['categoria' => 'tecnico', 'source_table' => $sourceTable]);

        $response = $this->getJson('/api/v1/auditoria/logs?per_page=100&source_table=' . $sourceTable);

        $response->assertOk()->assertJsonPath('meta.total', 4);
        $labels = collect($response->json('data'))->pluck('label');
        $this->assertTrue($labels->contains('Log outro admin'));
        $this->assertTrue($labels->contains('Log financeiro'));
        $this->assertTrue($labels->contains('Log estoque'));
        $this->assertTrue($labels->contains('Log vendedor'));
        $this->assertFalse($labels->contains('Log dev'));
        $this->assertFalse($labels->contains('Log sem ator'));

        $this->getJson('/api/v1/auditoria/logs?usuario_id=' . $dev->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson("/api/v1/auditoria/logs/{$devLogId}")->assertNotFound();
        $this->getJson("/api/v1/auditoria/logs/{$semAtorId}")->assertNotFound();

        $filters = $this->getJson('/api/v1/auditoria/logs/filtros')->assertOk()->json('data');
        $usuarios = collect($filters['usuarios'])->pluck('label')->implode(' | ');
        $this->assertStringContainsString('Outro Admin', $usuarios);
        $this->assertStringContainsString('Vendedor Auditavel', $usuarios);
        $this->assertStringNotContainsString('Dev Auditavel', $usuarios);
    }

    public function test_vendedor_visualiza_apenas_os_proprios_logs(): void
    {
        $vendedor = $this->autenticar([], ['Vendedor']);
        $outro = $this->usuarioComPerfil('Vendedor', 'Outro Vendedor');
        $sourceTable = 'visibility_vendedor_' . uniqid();

        $meuLogId = $this->criarLog($vendedor, 'Meu log vendedor', ['modulo' => 'meu_modulo', 'source_table' => $sourceTable]);
        $outroLogId = $this->criarLog($outro, 'Log outro vendedor', ['modulo' => 'outro_modulo', 'source_table' => $sourceTable]);

        $response = $this->getJson('/api/v1/auditoria/logs?per_page=100&source_table=' . $sourceTable);

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame('Meu log vendedor', $response->json('data.0.label'));

        $this->getJson('/api/v1/auditoria/logs?usuario_id=' . $outro->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson("/api/v1/auditoria/logs/{$meuLogId}")->assertOk();
        $this->getJson("/api/v1/auditoria/logs/{$outroLogId}")->assertNotFound();

        $filters = $this->getJson('/api/v1/auditoria/logs/filtros')->assertOk()->json('data');
        $this->assertSame([(string) $vendedor->id], collect($filters['usuarios'])->pluck('value')->map(fn ($id) => (string) $id)->all());

        $modulos = collect($filters['modulo'])->pluck('value');
        $this->assertTrue($modulos->contains('meu_modulo'));
        $this->assertFalse($modulos->contains('outro_modulo'));
    }

    public function test_monolog_e_espelhado_na_auditoria_unificada(): void
    {
        Log::channel('daily')->warning('auditoria warning test', ['token' => 'abc123', 'ok' => true]);

        $log = DB::table('auditoria_logs')
            ->where('source_kind', 'monolog')
            ->where('message', 'auditoria warning test')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('warning', $log->nivel);
        $context = json_decode($log->context_json, true);
        $this->assertSame('[REDACTED]', $context['token']);
        $this->assertTrue($context['ok']);
    }

    public function test_parser_de_arquivo_importa_multilinha_de_forma_idempotente(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'audit-log-');
        file_put_contents($file, "[2026-05-27 10:00:00] local.ERROR: Falha sensivel token=abc123\n#0 stack trace\n");

        $parser = app(LaravelLogFileParser::class);
        $service = app(AuditoriaLogService::class);

        foreach ($parser->parse($file, 'estoque', 'laravel') as $payload) {
            $service->registrar($payload);
            $service->registrar($payload);
        }

        $sourceTable = substr(str_replace('\\', '/', $file), 0, 120);

        $count = DB::table('auditoria_logs')
            ->where('source_kind', 'log_file')
            ->where('source_table', $sourceTable)
            ->count();

        $this->assertSame(1, $count);

        $log = DB::table('auditoria_logs')
            ->where('source_kind', 'log_file')
            ->where('source_table', $sourceTable)
            ->first();

        $this->assertStringContainsString('#0 stack trace', (string) $log->raw_excerpt);
        $this->assertStringNotContainsString('abc123', (string) $log->raw_excerpt);

        @unlink($file);
    }

    public function test_auditoria_financeira_grava_mudancas_principais(): void
    {
        $conta = ContaPagar::create([
            'descricao' => 'Conta auditada',
            'data_vencimento' => now()->addDay()->toDateString(),
            'valor_bruto' => 100,
            'desconto' => 0,
            'juros' => 0,
            'multa' => 0,
            'status' => 'ABERTA',
        ]);

        app(FinanceiroAuditoriaService::class)->log('updated', $conta, [
            'descricao' => 'Conta auditada',
            'valor_bruto' => '100.00',
            'status' => 'ABERTA',
        ], [
            'descricao' => 'Conta auditada atualizada',
            'valor_bruto' => '120.00',
            'status' => 'ABERTA',
        ]);

        $logId = DB::table('auditoria_logs')
            ->where('modulo', 'financeiro')
            ->where('acao', 'updated')
            ->where('entity_id', (string) $conta->id)
            ->latest('id')
            ->value('id');

        $this->assertNotNull($logId);
        $this->assertDatabaseHas('auditoria_log_mudancas', [
            'auditoria_log_id' => $logId,
            'campo' => 'valor_bruto',
            'old_value' => '100.00',
            'new_value' => '120.00',
        ]);
    }
}
