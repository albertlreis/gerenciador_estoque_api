<?php

namespace App\Integrations\ContaAzul\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContaAzulToken extends Model
{
    protected $table = 'conta_azul_tokens';

    protected $fillable = [
        'conexao_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'scope',
        'ultimo_refresh_em',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'ultimo_refresh_em' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function conexao(): BelongsTo
    {
        return $this->belongsTo(ContaAzulConexao::class, 'conexao_id');
    }

    public function isAccessTokenExpired(?int $skewSeconds = 120): bool
    {
        if ($this->expires_at === null) {
            return true;
        }

        return $this->expires_at->lte(now()->addSeconds($skewSeconds));
    }
}
