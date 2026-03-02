<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    public function test_database_migrations_ready(): void
    {
        $this->assertTrue(Schema::hasTable('produtos'));

        // Em alguns cenários locais os migrations externos não ficam montados no container.
        if (is_dir(base_path('../autenticacao_api/database/migrations'))) {
            $this->assertTrue(Schema::hasTable('acesso_usuarios'));
        }
    }
}
