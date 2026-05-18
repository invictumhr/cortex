<?php

namespace App\Services\PowerShell;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to the localhost-only PowerShell executor sidecar over HTTP.
 */
class PowerShellClient
{
    /**
     * @return array<string, mixed>
     */
    public function execute(string $command, ?int $chatId = null, ?int $personaId = null, bool $approved = false): array
    {
        $url = rtrim((string) config('cortex.powershell.url'), '/').'/execute';
        $token = (string) config('cortex.powershell.token');

        try {
            $response = Http::timeout(150)
                ->withToken($token)
                ->acceptJson()
                ->post($url, [
                    'command' => $command,
                    'chat_id' => $chatId,
                    'persona_id' => $personaId,
                    'approved' => $approved,
                ]);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'PowerShell executor nije dostupan. Pokreni: php artisan cortex:ps-executor'
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'PowerShell executor je vratio grešku ('.$response->status().').'
            );
        }

        return (array) $response->json();
    }
}
