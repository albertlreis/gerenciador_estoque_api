<?php

namespace App\Services\Google;

use App\Contracts\GoogleCalendarSyncServiceInterface;
use App\Models\Evento;
use Illuminate\Support\Facades\Log;

class NullGoogleCalendarSyncService implements GoogleCalendarSyncServiceInterface
{
    public function syncCreated(Evento $evento): void
    {
        $this->logStub('created', $evento);
    }

    public function syncUpdated(Evento $evento): void
    {
        $this->logStub('updated', $evento);
    }

    public function syncDeleted(Evento $evento): void
    {
        $this->logStub('deleted', $evento);
    }

    private function logStub(string $action, Evento $evento): void
    {
        Log::info('Google Agenda stub acionado.', [
            'action' => $action,
            'evento_id' => $evento->id,
        ]);
    }
}
