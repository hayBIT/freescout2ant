<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddLastErrorToCrmArchiveThreadsTable extends Migration
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
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('crm_archive_threads', function (Blueprint $table) {
            $table->dropColumn('last_error');
        });
    }
}
