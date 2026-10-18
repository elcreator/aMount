<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `amount_rules.on_conflict`: what a transfer does when the name is already taken at the other
 * end - `skip` (keep what is there, the default) or `newer` (a newer file replaces an older
 * one, never the other way round). Replacing is destructive, so it is never implied: a
 * manager ticks it, an agent needs eMCP's destructive permission.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('amount_rules') && !Schema::hasColumn('amount_rules', 'on_conflict')) {
            Schema::table('amount_rules', function (Blueprint $table) {
                $table->string('on_conflict', 8)->default('skip');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('amount_rules', 'on_conflict')) {
            Schema::table('amount_rules', function (Blueprint $table) {
                $table->dropColumn('on_conflict');
            });
        }
    }
};
