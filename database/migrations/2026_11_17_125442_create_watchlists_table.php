<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_id');
            $table->string('symbol');
            $table->timestamps();

            $table->unique(['telegram_id', 'symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlists');
    }
};
