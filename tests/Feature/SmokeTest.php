<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    public function test_database_migrations_ready(): void
    {
        $this->assertTrue(Schema::hasTable('acesso_usuarios'));
        $this->assertTrue(Schema::hasTable('produtos'));
    }
}
