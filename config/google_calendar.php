<?php

return [
    'base_url' => rtrim((string) env('GOOGLE_CALENDAR_BASE_URL', 'https://www.googleapis.com/calendar/v3'), '/'),
    'auth_url' => rtrim((string) env('GOOGLE_CALENDAR_AUTH_URL', 'https://accounts.google.com'), '/'),
    'token_url' => env('GOOGLE_CALENDAR_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
    'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_CALENDAR_REDIRECT_URI'),
    'oauth_front_redirect' => env(
        'GOOGLE_CALENDAR_OAUTH_FRONT_REDIRECT',
        env('FRONT_URL', 'http://localhost:5173') . '/integracoes/google-agenda'
    ),
    'scope' => env(
        'GOOGLE_CALENDAR_SCOPE',
        'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.calendarlist.readonly'
    ),
    'timezone' => env('GOOGLE_CALENDAR_TIMEZONE', 'America/Sao_Paulo'),
    'cache_ttl_seconds' => (int) env('GOOGLE_CALENDAR_CACHE_TTL_SECONDS', 90),
    'timeout' => (int) env('GOOGLE_CALENDAR_TIMEOUT', 30),
    'connect_timeout' => (int) env('GOOGLE_CALENDAR_CONNECT_TIMEOUT', 10),
    'retry' => [
        'times' => (int) env('GOOGLE_CALENDAR_RETRY_TIMES', 2),
        'sleep_ms' => (int) env('GOOGLE_CALENDAR_RETRY_SLEEP_MS', 300),
    ],
    'paths' => [
        'calendar_list' => '/users/me/calendarList',
        'events' => '/calendars/{calendar_id}/events',
        'event' => '/calendars/{calendar_id}/events/{event_id}',
    ],
];
