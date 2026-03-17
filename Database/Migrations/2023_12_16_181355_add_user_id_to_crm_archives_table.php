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
            $table->integer('archived_by')->nullable()->after('divisions');
        });
        $firstUser = \App\User::first();
        if ($firstUser) {
            DB::table('crm_archives')->update(['archived_by' => $firstUser->id]);
        }
    }

    public function down()
    {
        Schema::table('crm_archives', function (Blueprint $table) {
            $table->dropColumn('archived_by');
        });
    }
}
