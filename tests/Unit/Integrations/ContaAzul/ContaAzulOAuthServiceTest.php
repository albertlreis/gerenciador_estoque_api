<?php

namespace Tests\Unit\Integrations\ContaAzul;

use App\Integrations\ContaAzul\Auth\ContaAzulOAuthService;
use PHPUnit\Framework\TestCase;

class ContaAzulOAuthServiceTest extends TestCase
{
    public function test_build_authorization_url_contains_state_and_client_id(): void
    {
        $svc = new ContaAzulOAuthService([
            'auth_url' => 'https://auth.example.com',
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'redirect_uri' => 'https://app/cb',
            'scope' => 'openid',
            'timeout' => 5,
            'connect_timeout' => 5,
        ]);

        $url = $svc->buildAuthorizationUrl('state-xyz');

        $this->assertStringContainsString('state=state-xyz', $url);
        $this->assertStringContainsString('client_id=cid', $url);
        $this->assertStringStartsWith('https://auth.example.com/oauth2/authorize?', $url);
    }
}
