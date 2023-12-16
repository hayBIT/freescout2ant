<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmArchiveThreadsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::dropIfExists('crm_archive_thread');
        Schema::create('crm_archive_threads', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('crm_archive_id');
            $table->unsignedBigInteger('thread_id');
            $table->unsignedBigInteger('conversation_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('crm_archive_threads');
    }
}
