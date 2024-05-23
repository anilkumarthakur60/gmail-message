<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_tokens', function(Blueprint $table) {
            $table->id();
            $table->string('email', 255);
            $table->string('access_token', 255);
            $table->integer('expires_in');
            $table->string('refresh_token', 255)->nullable();
            $table->timestamp('refresh_token_updated_at')->nullable();
            $table->string('scope', 255);
            $table->string('token_type', 255);
            $table->timestamp('created');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_tokens');
    }
};
