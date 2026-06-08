<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('google_calendar_tokens')) {
            return;
        }

        DB::table('google_calendar_tokens')
            ->orderBy('id')
            ->chunkById(100, function ($tokens): void {
                foreach ($tokens as $token) {
                    $updates = [];

                    foreach (['access_token', 'refresh_token'] as $field) {
                        $value = $token->{$field} ?? null;
                        if (!is_string($value) || $value === '' || $this->isEncrypted($value)) {
                            continue;
                        }

                        $updates[$field] = Crypt::encryptString($value);
                    }

                    if ($updates !== []) {
                        DB::table('google_calendar_tokens')->where('id', $token->id)->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        //
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
