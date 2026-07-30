<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAutoAssignToCrmArchivesTable extends Migration
{
    public function up()
    {
        Schema::table('crm_archives', function (Blueprint $table) {
            // Wurde die Zuordnung automatisch erzeugt (statt im Modal bestätigt)?
            $table->boolean('auto_assigned')->default(false)->after('archived_by');
            // 'email', 'customer_number' oder 'both'
            $table->string('match_source')->nullable()->after('auto_assigned');
            // Gesetzt, sobald ein Nutzer die Zuordnung bestätigt oder selbst vorgenommen hat.
            $table->timestamp('confirmed_at')->nullable()->after('match_source');
        });
    }

    public function down()
    {
        Schema::table('crm_archives', function (Blueprint $table) {
            $table->dropColumn(['auto_assigned', 'match_source', 'confirmed_at']);
        });
    }
}
