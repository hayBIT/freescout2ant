<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCrmArchivesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crm_archives', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('conversation_id');
            $table->string('crm_user_id');
            $table->text('crm_user');
            $table->text('contracts');
            $table->text('divisions');
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
        Schema::dropIfExists('crm_archives');
    }
}
