<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Cache;

final class DashboardCacheVersion
{
    private const KEY = 'dashboard:cache-version';

    public static function current(): int
    {
        return (int) Cache::get(self::KEY, 1);
    }

    public static function invalidate(): void
    {
        $next = self::current() + 1;
        Cache::forever(self::KEY, $next);
    }
}
