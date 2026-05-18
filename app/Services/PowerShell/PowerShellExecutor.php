<?php

namespace App\Services\PowerShell;

use App\Models\PowerShellLog;
use App\Models\PowerShellPermission;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class PowerShellExecutor
{
    public function __construct(private PowerShellGuard $guard) {}

    /**
     * Validate, run (if permitted) and log a PowerShell command.
     */
    public function execute(string $command, ?int $chatId = null, ?int $personaId = null, bool $approved = false): PowerShellLog
    {
        $perm = PowerShellPermission::current();
        $check = $this->guard->check($command);

        if (! $check['allowed']) {
            return $this->log($command, $chatId, $personaId, [
                'was_blocked' => true,
                'was_approved' => false,
                'block_reason' => $check['reason'],
            ]);
        }

        if ($perm->require_approval && ! $approved) {
            return $this->log($command, $chatId, $personaId, [
                'was_blocked' => true,
                'was_approved' => false,
                'block_reason' => 'Naredba čeka odobrenje korisnika.',
            ]);
        }

        $timeout = max(1, (int) $perm->max_execution_time);
        $startedAt = microtime(true);
        $output = '';
        $exitCode = null;

        try {
            $process = new Process(
                ['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $command],
                base_path(),
            );
            $process->setTimeout($timeout);
            $process->run();

            $output = $process->getOutput().$process->getErrorOutput();
            $exitCode = $process->getExitCode();
        } catch (ProcessTimedOutException) {
            $output = "Naredba je prekoračila vremensko ograničenje ({$timeout}s).";
            $exitCode = 124;
        } catch (Throwable $e) {
            $output = 'Greška pri izvršavanju: '.$e->getMessage();
            $exitCode = 1;
        }

        return $this->log($command, $chatId, $personaId, [
            'was_blocked' => false,
            'was_approved' => true,
            'output' => trim(mb_substr($output, 0, 60000)),
            'exit_code' => $exitCode,
            'execution_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function log(string $command, ?int $chatId, ?int $personaId, array $attributes): PowerShellLog
    {
        return PowerShellLog::create(array_merge([
            'chat_id' => $chatId,
            'persona_id' => $personaId,
            'command' => $command,
        ], $attributes));
    }
}
