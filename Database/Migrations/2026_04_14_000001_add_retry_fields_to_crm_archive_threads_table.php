<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddRetryFieldsToCrmArchiveThreadsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_archive_threads', function (Blueprint $table) {
            $table->text('last_error')->nullable()->after('conversation_id');
            $table->timestamp('last_attempt_at')->nullable()->after('last_error');
            $table->timestamp('archived_at')->nullable()->after('last_attempt_at');
        });

        DB::table('crm_archive_threads')
            ->whereNull('archived_at')
            ->update([
                'archived_at' => DB::raw('created_at'),
                'last_attempt_at' => DB::raw('updated_at'),
            ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('crm_archive_threads', function (Blueprint $table) {
            $table->dropColumn(['last_error', 'last_attempt_at', 'archived_at']);
        });
    }
}
