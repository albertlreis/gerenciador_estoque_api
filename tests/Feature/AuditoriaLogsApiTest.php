<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\AuditoriaLogService;
use App\Support\Auditoria\LaravelLogFileParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditoriaLogsApiTest extends TestCase
{
    private function autenticar(array $permissoes = ['auditoria.logs.visualizar']): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Auditoria',
            'email' => 'auditoria.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);
        Cache::put('permissoes_usuario_' . $usuario->id, $permissoes, now()->addHour());

        return $usuario;
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
        $this->autenticar();

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
}
