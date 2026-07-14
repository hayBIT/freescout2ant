<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCrmArchiveAttemptsTable extends Migration
{
    public function up()
    {
        Schema::create('crm_archive_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('conversation_id')->index();
            $table->unsignedBigInteger('thread_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('status', 40);
            $table->text('reason')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedSmallInteger('attempt_no')->default(1);
            $table->unsignedSmallInteger('attachments_total')->nullable();
            $table->unsignedSmallInteger('attachments_failed')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();

            $table->index(['thread_id', 'user_id']);
            $table->index(['status', 'resolved_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_archive_attempts');
    }
}
