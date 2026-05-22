<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS-specific columns on users — role gate (admin vs regular), VAT context
 * for Paddle, abuse-prevention fingerprints from registration, and a marker
 * for the one-time signup credit so we never grant it twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // True only for super-admins who see /admin. Regular users get /user.
            // First seeded admin (admin@cortex.test) is flipped to true in seeder.
            $table->boolean('is_admin')->default(false)->after('email_verified_at');
            // EU VAT registration (PDV ID for HR) — when filled and valid,
            // Paddle applies reverse charge instead of adding 25% VAT.
            $table->string('vat_id', 32)->nullable()->after('is_admin');
            // VIES validation outcome; keeps history so we don't re-validate on
            // every checkout (VIES is flaky).
            $table->enum('vat_validation_status', ['unknown', 'valid', 'invalid', 'manual_override'])->default('unknown')->after('vat_id');
            $table->char('country_code', 2)->nullable()->after('vat_validation_status');
            // Stamped on signup grant; null = grant not yet issued, blocking duplicates.
            $table->timestamp('free_credit_granted_at')->nullable()->after('country_code');
            // Hashed (not raw) so we comply with GDPR data minimisation while
            // still being able to spot one-IP-many-accounts farming.
            $table->string('registration_ip_hash', 64)->nullable()->after('free_credit_granted_at');
            $table->string('registration_ua_hash', 64)->nullable()->after('registration_ip_hash');

            $table->index('registration_ip_hash');
            $table->index('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['registration_ip_hash']);
            $table->dropIndex(['is_admin']);
            $table->dropColumn([
                'is_admin', 'vat_id', 'vat_validation_status', 'country_code',
                'free_credit_granted_at', 'registration_ip_hash', 'registration_ua_hash',
            ]);
        });
    }
};
