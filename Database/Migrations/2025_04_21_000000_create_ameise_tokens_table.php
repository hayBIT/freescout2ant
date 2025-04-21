<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ameise_tokens', function (Blueprint $table) {
            $table->id();
            $table->text('access_token');    // verschlüsselt
            $table->text('refresh_token');   // verschlüsselt
            $table->timestamp('expires_at'); // UTC‑Zeit
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ameise_tokens');
    }
};
