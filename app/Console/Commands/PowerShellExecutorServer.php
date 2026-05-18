<?php

namespace App\Console\Commands;

use App\Services\PowerShell\PowerShellExecutor;
use Illuminate\Console\Command;
use Throwable;

/**
 * A single-endpoint HTTP sidecar that executes PowerShell commands.
 * It binds to loopback only and is never meant to be internet-exposed.
 */
class PowerShellExecutorServer extends Command
{
    protected $signature = 'cortex:ps-executor {--host=127.0.0.1} {--port=}';

    protected $description = 'Pokreni lokalni (samo localhost) PowerShell executor sidecar';

    public function handle(PowerShellExecutor $executor): int
    {
        $host = (string) $this->option('host');

        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->error('Sidecar se smije vezati isključivo na localhost.');

            return self::FAILURE;
        }

        $configuredPort = parse_url((string) config('cortex.powershell.url'), PHP_URL_PORT);
        $port = (int) ($this->option('port') ?: $configuredPort ?: 7878);
        $token = (string) config('cortex.powershell.token');

        if ($token === '') {
            $this->error('POWERSHELL_EXECUTOR_TOKEN nije postavljen u .env.');

            return self::FAILURE;
        }

        $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);

        if ($server === false) {
            $this->error("Ne mogu otvoriti socket {$host}:{$port} — {$errstr}");

            return self::FAILURE;
        }

        $this->info("Cortex PowerShell executor sluša na http://{$host}:{$port}/execute");
        $this->comment('Ctrl+C za zaustavljanje.');

        while (true) {
            $conn = @stream_socket_accept($server, -1);

            if ($conn === false) {
                continue;
            }

            try {
                $this->serve($conn, $executor, $token);
            } catch (Throwable $e) {
                report($e);
            } finally {
                fclose($conn);
            }
        }
    }

    /**
     * @param  resource  $conn
     */
    private function serve($conn, PowerShellExecutor $executor, string $token): void
    {
        stream_set_timeout($conn, 15);

        $raw = '';
        while (! str_contains($raw, "\r\n\r\n")) {
            $chunk = fread($conn, 4096);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $raw .= $chunk;
            if (strlen($raw) > 200_000) {
                break;
            }
        }

        [$headerPart, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $lines = explode("\r\n", $headerPart);
        $requestLine = array_shift($lines) ?? '';
        [$method, $path] = array_pad(explode(' ', $requestLine), 2, '');

        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        $contentLength = (int) ($headers['content-length'] ?? 0);
        while (strlen($body) < $contentLength) {
            $chunk = fread($conn, 4096);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $body .= $chunk;
        }

        if (strtoupper($method) !== 'POST' || rtrim($path, '/') !== '/execute') {
            $this->respond($conn, 404, ['error' => 'Not found']);

            return;
        }

        if (($headers['authorization'] ?? '') !== 'Bearer '.$token) {
            $this->respond($conn, 401, ['error' => 'Unauthorized']);

            return;
        }

        $payload = json_decode($body, true);

        if (! is_array($payload) || ! isset($payload['command'])) {
            $this->respond($conn, 422, ['error' => 'Nedostaje polje "command".']);

            return;
        }

        $log = $executor->execute(
            (string) $payload['command'],
            isset($payload['chat_id']) ? (int) $payload['chat_id'] : null,
            isset($payload['persona_id']) ? (int) $payload['persona_id'] : null,
            (bool) ($payload['approved'] ?? false),
        );

        $this->line('  > '.$log->command.($log->was_blocked ? ' [BLOCKED]' : ' [exit '.$log->exit_code.']'));

        $this->respond($conn, 200, [
            'id' => $log->id,
            'command' => $log->command,
            'output' => $log->output,
            'exit_code' => $log->exit_code,
            'was_blocked' => $log->was_blocked,
            'was_approved' => $log->was_approved,
            'block_reason' => $log->block_reason,
            'execution_time_ms' => $log->execution_time_ms,
        ]);
    }

    /**
     * @param  resource  $conn
     * @param  array<string, mixed>  $data
     */
    private function respond($conn, int $status, array $data): void
    {
        $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $statusText = [200 => 'OK', 401 => 'Unauthorized', 404 => 'Not Found', 422 => 'Unprocessable Entity'][$status] ?? 'OK';

        fwrite($conn, "HTTP/1.1 {$status} {$statusText}\r\n"
            ."Content-Type: application/json\r\n"
            ."Content-Length: ".strlen($body)."\r\n"
            ."Connection: close\r\n\r\n"
            .$body);
    }
}
