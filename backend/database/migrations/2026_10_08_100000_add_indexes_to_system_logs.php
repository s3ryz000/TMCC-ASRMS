<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The log viewer (#94) sorts by created_at and filters by user and date:
 * indexes on system_logs(created_at) and (user_id, created_at), each added
 * only when missing. Indexes don't touch rows; the row count is compared
 * before and after all the same.
 */
return new class extends Migration
{
    private const INDEXES = [
        'system_logs_created_at_index' => ['created_at'],
        'system_logs_user_id_created_at_index' => ['user_id', 'created_at'],
    ];

    public function up(): void
    {
        $before = DB::table('system_logs')->count();

        Schema::table('system_logs', function (Blueprint $table) {
            foreach (self::INDEXES as $name => $columns) {
                if (! Schema::hasIndex('system_logs', $columns) && ! Schema::hasIndex('system_logs', $name)) {
                    $table->index($columns, $name);
                }
            }
        });

        if (DB::table('system_logs')->count() !== $before) {
            throw new RuntimeException('Adding indexes changed the number of system_logs rows.');
        }
    }

    public function down(): void
    {
        Schema::table('system_logs', function (Blueprint $table) {
            foreach (array_keys(self::INDEXES) as $name) {
                if (Schema::hasIndex('system_logs', $name)) {
                    $table->dropIndex($name);
                }
            }
        });
    }
};
