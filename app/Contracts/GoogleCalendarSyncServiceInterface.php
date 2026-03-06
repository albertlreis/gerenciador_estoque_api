<?php

namespace App\Contracts;

use App\Models\Evento;

interface GoogleCalendarSyncServiceInterface
{
    public function syncCreated(Evento $evento): void;

    public function syncUpdated(Evento $evento): void;

    public function syncDeleted(Evento $evento): void;
}
