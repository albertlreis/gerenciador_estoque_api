<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\DatabaseSeeder;

class SeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_essenciais_p0_estao_disponiveis(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('acesso_usuarios', ['id' => 1, 'email' => 'admin@seed.local']);
        $this->assertDatabaseHas('configuracoes', ['chave' => 'dias_previsao_envio_fabrica']);
        $this->assertDatabaseHas('categorias', ['id' => 1, 'nome' => 'Sofás']);
        $this->assertDatabaseHas('depositos', ['nome' => 'Showroom Umarizal']);
        $this->assertDatabaseHas('formas_pagamento', ['slug' => 'pix', 'ativo' => 1]);
    }
}
