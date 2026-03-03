<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\TransferenciaFinanceira;
use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransferenciaFinanceiraIndexTest extends TestCase
{
    private function autenticar(): Usuario
    {
        $usuario = Usuario::create([
            'nome' => 'Usuario Transferencia',
            'email' => 'usuario.transferencia.' . uniqid() . '@example.test',
            'senha' => Hash::make('SenhaForte123'),
            'ativo' => true,
        ]);

        Sanctum::actingAs($usuario);

        return $usuario;
    }

    public function test_get_transferencias_retorna_lista_com_campos_esperados(): void
    {
        $usuario = $this->autenticar();

        $origem = ContaFinanceira::create([
            'nome' => 'Conta Origem',
            'tipo' => 'banco',
            'moeda' => 'BRL',
            'ativo' => true,
            'saldo_inicial' => 1000,
        ]);

        $destino = ContaFinanceira::create([
            'nome' => 'Conta Destino',
            'tipo' => 'banco',
            'moeda' => 'BRL',
            'ativo' => true,
            'saldo_inicial' => 0,
        ]);

        TransferenciaFinanceira::create([
            'conta_origem_id' => $origem->id,
            'conta_destino_id' => $destino->id,
            'valor' => 150.25,
            'data_movimento' => now()->setTime(10, 0),
            'observacoes' => 'Transferencia teste',
            'status' => 'confirmado',
            'created_by' => $usuario->id,
        ]);

        $response = $this->getJson('/api/v1/financeiro/transferencias');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'status',
                    'valor',
                    'data',
                    'observacoes',
                    'descricao',
                    'conta_origem_id',
                    'conta_destino_id',
                    'conta_origem' => ['id', 'nome', 'moeda', 'tipo'],
                    'conta_destino' => ['id', 'nome', 'moeda', 'tipo'],
                ]],
            ]);

        $this->assertNotEmpty($response->json('data'));
        $this->assertSame('Transferencia teste', $response->json('data.0.descricao'));
    }
}
