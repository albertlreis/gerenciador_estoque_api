<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiUnauthenticatedResponseTest extends TestCase
{
    public function test_api_protegida_sem_accept_json_retorna_401_json(): void
    {
        $this->get('/api/v1/financeiro/relatorios/dre')
            ->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }
}
