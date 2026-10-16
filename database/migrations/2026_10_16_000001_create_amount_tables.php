<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything aMount stores.
 *
 * Idempotent on purpose (`hasTable` guards): the CMS re-runs package
 * migrations on `make:site update`, and a half-applied earlier run must not
 * wedge the next one.
 *
 * Two deliberate absences:
 *  - no foreign keys. The user, group and file-group tables belong to the core
 *    and differ in engine/collation between installs; integrity is kept by the
 *    package (see Listeners\Cleanup) instead of by a constraint that can
 *    refuse the migration.
 *  - no index on a long path. MySQL caps an index at 3072 bytes, so files are
 *    found by `path_hash` (sha1 of the site-relative path), which is also
 *    collation-proof: `report.txt` and `REPORT.txt` are two files on Linux.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('amount_mounts')) {
            Schema::create('amount_mounts', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 36)->unique();
                $table->string('name', 120);
                $table->string('driver', 32);
                // NULL = a site-wide mount, a user id = that manager's own account
                // (their Google Drive, their Dropbox). Never exposed to anybody else.
                $table->unsignedInteger('owner_id')->nullable()->index();
                // Encrypted JSON: credentials and connection details.
                $table->longText('config_enc')->nullable();
                // Plain JSON: public_url, serve mode, root prefix, visibility handling.
                $table->text('options_json')->nullable();
                // Last "how much is stored here" measurement (a `size` run):
                // {bytes, files, measured_at, rows:[{label, bytes, files}]}.
                // The page renders it instantly; counting is a click away.
                $table->text('usage_json')->nullable();
                $table->string('status', 16)->default('untested');
                $table->text('last_error')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->unsignedInteger('created_by')->default(0);
                $table->timestamps();
            });
        }

        // Which user groups (udperms: member_groups.user_group) may use a
        // site-wide mount. A site mount with no row here is usable by every
        // manager holding the `amount` permission.
        if (!Schema::hasTable('amount_mount_groups')) {
            Schema::create('amount_mount_groups', function (Blueprint $table) {
                $table->unsignedBigInteger('mount_id');
                $table->unsignedInteger('user_group');
                $table->primary(['mount_id', 'user_group']);
            });
        }

        if (!Schema::hasTable('amount_rules')) {
            Schema::create('amount_rules', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 36)->unique();
                $table->unsignedBigInteger('mount_id')->index();
                // The manager whose file-manager visibility bounds this rule.
                $table->unsignedInteger('owner_id')->index();
                $table->string('name', 120);
                // Relative to the site root, forward slashes, no edges.
                $table->string('local_path', 1000);
                $table->string('remote_prefix', 500)->default('');
                $table->boolean('recursive')->default(true);
                $table->string('mode', 8)->default('copy');          // copy | move
                $table->string('visibility', 8)->default('private'); // private | public
                // mode=move deletes the local file once the remote copy is verified.
                // These two delete on the store: mirror_deletes follows local
                // deletions, retention_days expires copies older than N days
                // (rotating backups). 0 = keep forever.
                $table->boolean('mirror_deletes')->default(false);
                $table->unsignedInteger('retention_days')->default(0);
                $table->text('usage_json')->nullable(); // same shape as on the mount, for this rule's remote prefix
                $table->text('include_json')->nullable();
                $table->text('exclude_json')->nullable();
                $table->unsignedInteger('min_age')->default(60);      // seconds a file must be quiet
                $table->unsignedBigInteger('min_size')->default(0);
                $table->unsignedBigInteger('max_size')->default(0);   // 0 = unlimited
                $table->unsignedInteger('interval_minutes')->default(0); // 0 = manual only
                $table->boolean('enabled')->default(true);
                // A one-off delivery made through the API ("put this on my Google
                // Drive"): runs once, never scheduled, listed under Activity
                // rather than Folders. `origin` says who asked: ui, api, aimage...
                $table->boolean('ephemeral')->default(false)->index();
                $table->string('origin', 32)->default('ui');
                $table->timestamp('last_run_at')->nullable();
                $table->timestamp('next_run_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('amount_files')) {
            Schema::create('amount_files', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('rule_id');
                $table->unsignedBigInteger('mount_id')->index();
                $table->char('path_hash', 40);
                $table->string('local_path', 1000);
                $table->string('remote_path', 1500);
                $table->unsignedBigInteger('size')->default(0);
                $table->unsignedBigInteger('mtime')->default(0);
                $table->string('visibility', 8)->default('private');
                $table->string('status', 12)->default('pending'); // pending|uploading|uploaded|failed|expired
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->text('error')->nullable();
                $table->boolean('local_removed')->default(false);
                $table->timestamp('uploaded_at')->nullable();
                $table->timestamp('seen_at')->nullable();
                $table->timestamps();

                $table->unique(['rule_id', 'path_hash']);
                $table->index('path_hash');
                $table->index(['rule_id', 'status']);
            });
        }

        if (!Schema::hasTable('amount_runs')) {
            Schema::create('amount_runs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid', 36)->unique();
                $table->unsignedBigInteger('rule_id')->index();
                $table->unsignedInteger('started_by')->default(0);
                $table->string('queue', 16)->default('');
                $table->unsignedBigInteger('task_id')->nullable(); // system_cli_tasks.id or s_tasks.id
                $table->string('trigger', 12)->default('manual');   // manual | schedule
                // up: local -> store (the rule's job). down: store -> local, "bring
                // that backup back"; payload_json says which files, where to, and
                // whether to delete the store's copy afterwards. size: count what a
                // mount / remote folder / rule holds ({target, path}); it only reads.
                $table->string('direction', 4)->default('up'); // up | down | size
                $table->text('payload_json')->nullable();
                $table->string('status', 12)->default('queued')->index(); // queued|scanning|uploading|done|failed|cancelled
                $table->unsignedInteger('files_total')->default(0);
                $table->unsignedInteger('files_done')->default(0);
                $table->unsignedInteger('files_failed')->default(0);
                $table->unsignedBigInteger('bytes_total')->default(0);
                $table->unsignedBigInteger('bytes_done')->default(0);
                $table->string('current_file', 1000)->nullable();
                $table->text('message')->nullable();
                $table->timestamp('heartbeat_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        // Small key/value store: generated-config fingerprints, one-off flags.
        if (!Schema::hasTable('amount_state')) {
            Schema::create('amount_state', function (Blueprint $table) {
                $table->string('key', 80)->primary();
                $table->text('value')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['amount_state', 'amount_runs', 'amount_files', 'amount_rules', 'amount_mount_groups', 'amount_mounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
