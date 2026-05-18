<?php

namespace App\Services\PowerShell;

use App\Models\PowerShellPermission;

class PowerShellGuard
{
    /**
     * Validate a command against the active PowerShell permission set.
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public function check(string $command): array
    {
        $perm = PowerShellPermission::current();

        if (! $perm->is_enabled) {
            return $this->deny('PowerShell izvršavanje je globalno isključeno.');
        }

        $command = trim($command);

        if ($command === '') {
            return $this->deny('Prazna naredba.');
        }

        $lower = mb_strtolower($command);

        foreach ((array) $perm->blocked_commands as $blocked) {
            $needle = mb_strtolower(trim((string) $blocked));
            if ($needle !== '' && str_contains($lower, $needle)) {
                return $this->deny("Blokirana naredba: {$blocked}");
            }
        }

        foreach ((array) $perm->blocked_paths as $path) {
            $needle = mb_strtolower(rtrim((string) $path, '*\\/ '));
            if ($needle !== '' && str_contains($lower, $needle)) {
                return $this->deny("Naredba dodiruje blokiranu putanju: {$path}");
            }
        }

        $allowed = array_filter(array_map('trim', (array) $perm->allowed_commands));

        if ($allowed !== []) {
            foreach ($allowed as $prefix) {
                if (str_starts_with($lower, mb_strtolower($prefix))) {
                    return ['allowed' => true, 'reason' => null];
                }
            }

            return $this->deny('Naredba nije na popisu dozvoljenih naredbi.');
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function requiresApproval(): bool
    {
        return (bool) PowerShellPermission::current()->require_approval;
    }

    /**
     * @return array{allowed: bool, reason: string}
     */
    private function deny(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }
}
