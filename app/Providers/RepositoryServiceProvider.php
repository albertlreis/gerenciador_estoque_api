<?php

namespace App\Providers;

use App\Repositories\Contracts\ContaPagarRepository;
use App\Repositories\Eloquent\EloquentContaPagarRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContaPagarRepository::class, EloquentContaPagarRepository::class);
    }
}
