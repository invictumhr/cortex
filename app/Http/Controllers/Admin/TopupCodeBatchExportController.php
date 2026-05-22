<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TopupCodeBatch;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a batch of top-up codes as a one-column CSV (plaintext PINs, one per
 * line). Authenticated admin only — the route binding sits behind the same
 * auth chain Filament uses, plus an inline `is_admin` check.
 *
 * Backfilled legacy batches have no encrypted_code stored, so their export
 * yields an empty file. That's intentional: those PINs were never plaintext-
 * recoverable to begin with, and we surface that fact in the download rather
 * than silently swap in hashes.
 */
class TopupCodeBatchExportController extends Controller
{
    public function __invoke(TopupCodeBatch $batch): StreamedResponse
    {
        abort_unless(auth()->check() && auth()->user()->is_admin, 403);

        $filename = 'batch-'.$batch->id.'-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $batch->label).'.csv';

        return response()->streamDownload(function () use ($batch) {
            // Stream row-by-row with cursor() so a 10k-code batch doesn't
            // materialise into PHP memory.
            foreach ($batch->codes()->orderBy('id')->cursor() as $code) {
                if ($code->encrypted_code !== null) {
                    echo $code->encrypted_code."\n";
                }
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
