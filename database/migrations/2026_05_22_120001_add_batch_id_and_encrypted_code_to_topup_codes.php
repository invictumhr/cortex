<?php

use App\Models\TopupCode;
use App\Models\TopupCodeBatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link topup_codes to the new topup_code_batches table and add an encrypted
 * plaintext column so admin can re-export a batch as CSV at any later time
 * (the existing code_hash is one-way and was never recoverable).
 *
 * encrypted_code uses Laravel's `encrypted` cast at the model layer (Eloquent
 * encrypts on write, decrypts on read using APP_KEY). DB-only breach still
 * doesn't leak codes; DB + APP_KEY together would. That trade-off was accepted
 * to enable the "export batch later" admin workflow.
 *
 * Backfill: any existing rows are grouped by their (now legacy) batch_label
 * column and a one batch row per distinct label is created. Existing codes
 * get NULL encrypted_code — they predate plaintext storage, so admin can no
 * longer recover their PINs (CSV export of those legacy batches will be empty).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topup_codes', function (Blueprint $table) {
            // Nullable: keeps legacy rows valid; new generations always set it.
            $table->foreignId('batch_id')->nullable()->after('id')
                ->constrained('topup_code_batches')->nullOnDelete();
            // Encrypted at rest via Laravel `encrypted` cast — ~200 chars for a
            // 14-digit payload (encrypt overhead is large), so use TEXT.
            $table->text('encrypted_code')->nullable()->after('code_hash');

            $table->index('batch_id');
        });

        $this->backfillBatchesFromLegacyLabels();
    }

    public function down(): void
    {
        Schema::table('topup_codes', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
            $table->dropIndex(['batch_id']);
            $table->dropColumn(['batch_id', 'encrypted_code']);
        });
    }

    /**
     * For every distinct batch_label currently stored on topup_codes, create
     * one topup_code_batches row and point matching codes at it. Preserves
     * created_at / created_by from the earliest code in each legacy batch so
     * the admin's history view doesn't lie.
     */
    private function backfillBatchesFromLegacyLabels(): void
    {
        $groups = DB::table('topup_codes')
            ->select('batch_label')
            ->selectRaw('MIN(created_at) as first_created_at')
            ->selectRaw('MIN(created_by_user_id) as first_created_by')
            ->selectRaw('AVG(amount) as avg_amount')
            ->selectRaw('COUNT(*) as code_count')
            ->whereNotNull('batch_label')
            ->groupBy('batch_label')
            ->get();

        foreach ($groups as $g) {
            $batchId = DB::table('topup_code_batches')->insertGetId([
                'label' => $g->batch_label,
                'amount_per_code' => $g->avg_amount,
                'code_count' => $g->code_count,
                'notes' => 'Backfilled from legacy batch_label on '.now()->toDateString().'.',
                'created_by_user_id' => $g->first_created_by,
                'created_at' => $g->first_created_at,
                'updated_at' => $g->first_created_at,
            ]);

            DB::table('topup_codes')
                ->where('batch_label', $g->batch_label)
                ->update(['batch_id' => $batchId]);
        }
    }
};
