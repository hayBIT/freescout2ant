<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Datensatz je Archiveintrag in der Ameise — nicht je Thread.
 *
 * Eine Konversation erzeugt mehrere Einträge (einen je Nachricht, einen je
 * Anhang). Erst diese Tabelle macht den einzelnen Eintrag adressierbar und
 * damit nachträglich bearbeitbar.
 */
class CreateCrmArchiveEntriesTable extends Migration
{
    public function up()
    {
        Schema::create('crm_archive_entries', function (Blueprint $table) {
            $table->increments('id');

            // Herkunft in FreeScout
            $table->unsignedInteger('crm_archive_id')->nullable();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('thread_id')->nullable();
            $table->unsignedBigInteger('attachment_id')->nullable();
            $table->string('kind', 16)->default('thread');
            $table->integer('archived_by')->nullable();

            // Identität in der Ameise
            $table->string('customer_id', 64);
            $table->uuid('archive_entry_id')->nullable();
            $table->string('legacy_id', 64)->nullable();

            // Anzeige in der Seitenleiste ohne Remote-Aufruf
            $table->string('subject', 191)->nullable();
            $table->string('entry_type', 32)->nullable();
            $table->dateTime('entry_date')->nullable();

            // Statusspiegel
            $table->boolean('is_public')->nullable();
            $table->boolean('requires_review')->nullable();
            $table->boolean('is_deleted')->nullable();

            // Zuordnungsspiegel
            $table->text('contracts')->nullable();
            $table->text('contract_lines')->nullable();
            $table->text('tags')->nullable();

            // Abgleich mit der Archive-API
            $table->string('sync_state', 16)->default('pending');
            $table->dateTime('remote_synced_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->unique('archive_entry_id');
            $table->index(['conversation_id', 'customer_id']);
            $table->index(['sync_state', 'entry_date']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_archive_entries');
    }
}
