<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FornecedorIndexTest extends TestCase
{
    use RefreshDatabase;

    private function autenticar(): void
    {
        $usuario = Usuario::query()->firstOrCreate(
            ['email' => 'fornecedores-test@example.com'],
            [
                'nome' => 'Usuario Fornecedores',
                'senha' => 'senha',
                'ativo' => true,
            ]
        );

        Sanctum::actingAs($usuario);
    }

    public function test_index_retorna_meta_escalar_e_permite_acessar_fornecedores_apos_primeira_pagina(): void
    {
        $this->autenticar();

        $agora = now();
        $fornecedores = [];
        for ($indice = 1; $indice <= 200; $indice += 1) {
            $fornecedores[] = [
                'nome' => sprintf('Fornecedor A %03d', $indice),
                'status' => 1,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }
        $fornecedores[] = [
            'nome' => 'Fornecedor Z depois da letra E',
            'status' => 1,
            'created_at' => $agora,
            'updated_at' => $agora,
        ];
        Fornecedor::query()->insert($fornecedores);

        $primeiraPagina = $this->getJson('/api/v1/fornecedores?page=1&per_page=200&order_by=nome&order_dir=asc');

        $primeiraPagina->assertOk()
            ->assertJsonCount(200, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 200)
            ->assertJsonPath('meta.total', 201)
            ->assertJsonPath('meta.last_page', 2);

        $this->assertIsInt($primeiraPagina->json('meta.current_page'));
        $this->assertIsInt($primeiraPagina->json('meta.per_page'));
        $this->assertIsInt($primeiraPagina->json('meta.total'));
        $this->assertIsInt($primeiraPagina->json('meta.last_page'));

        $this->getJson('/api/v1/fornecedores?page=2&per_page=200&order_by=nome&order_dir=asc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nome', 'Fornecedor Z depois da letra E');
    }

    public function test_index_ordena_nomes_iguais_por_id_para_paginacao_deterministica(): void
    {
        $this->autenticar();

        $primeiro = Fornecedor::create(['nome' => 'Fornecedor repetido', 'status' => 1]);
        $segundo = Fornecedor::create(['nome' => 'Fornecedor repetido', 'status' => 1]);

        $resposta = $this->getJson('/api/v1/fornecedores?q=Fornecedor%20repetido&per_page=200&order_by=nome&order_dir=asc');

        $resposta->assertOk();
        $this->assertSame([$primeiro->id, $segundo->id], $resposta->json('data.*.id'));
    }

    public function test_index_busca_fornecedor_por_nome(): void
    {
        $this->autenticar();

        Fornecedor::create(['nome' => 'Fornecedor Alpha', 'status' => 1]);
        Fornecedor::create(['nome' => 'Fornecedor Zeta', 'status' => 1]);

        $this->getJson('/api/v1/fornecedores?q=Zeta')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nome', 'Fornecedor Zeta');
    }
}
