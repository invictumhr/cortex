<?php

namespace App\Console\Commands;

use App\Services\Billing\TopupCodeService;
use Illuminate\Console\Command;

/**
 * Generate a batch of top-up codes from the CLI. Prints the plaintext PINs
 * to stdout (once — they're not recoverable from the DB) so they can be
 * piped to a CSV, an SMS dispatcher, or a label printer.
 *
 *   php artisan cortex:topup-codes 50 5 --batch="SMS Q2 2026"
 *   php artisan cortex:topup-codes 1 25 --batch="manual one-off" --json
 */
class GenerateTopupCodes extends Command
{
    protected $signature = 'cortex:topup-codes
        {count : How many codes to generate}
        {amount : EUR value of each code}
        {--batch= : Optional batch label}
        {--json : Output as JSON instead of a table}';

    protected $description = 'Generate a batch of top-up PIN codes and print the plaintext once.';

    public function handle(TopupCodeService $service): int
    {
        $count = (int) $this->argument('count');
        $amount = (float) $this->argument('amount');
        $batch = $this->option('batch') ?: null;

        try {
            $issued = $service->generateBatch($count, $amount, $batch);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'count' => count($issued),
                'amount_each_eur' => $amount,
                'batch' => $batch,
                'codes' => array_map(fn ($row) => [
                    'id' => $row['model']->id,
                    'plaintext' => $row['plaintext'],
                    'formatted' => TopupCodeService::formatForDisplay($row['plaintext']),
                ], $issued),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("Issued ".count($issued)." code(s), €$amount each".($batch ? " · batch \"$batch\"" : '').'.');
        $this->newLine();
        $this->table(
            ['ID', 'Code (one-time view)'],
            array_map(fn ($row) => [
                $row['model']->id,
                TopupCodeService::formatForDisplay($row['plaintext']),
            ], $issued),
        );
        $this->newLine();
        $this->warn('Save these now — the plaintext is not recoverable from the DB.');

        return self::SUCCESS;
    }
}
