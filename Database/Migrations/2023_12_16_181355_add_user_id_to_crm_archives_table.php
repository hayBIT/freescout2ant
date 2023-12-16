<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddUserIdToCrmArchivesTable extends Migration
{
    public function up()
    {
        Schema::table('crm_archives', function (Blueprint $table) {
            $table->integer('archived_by')->after('divisions');
        });
        $firstUserId = \App\User::first();
        DB::table('crm_archives')->update(['archived_by' => $firstUserId]);
    }

    public function down()
    {
        Schema::table('crm_archives', function (Blueprint $table) {
            $table->dropColumn('archived_by');
        });
    }
}
