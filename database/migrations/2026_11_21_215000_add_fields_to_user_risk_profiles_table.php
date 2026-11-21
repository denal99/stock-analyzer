<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_risk_profiles', function (Blueprint $table) {
            $table->string('chat_id')->nullable()->unique()->after('id');$table->unsignedBigInteger('capital')->nullable()->after('chat_id');
            $table->decimal('risk_percent', 5, 2)->nullable()->after('capital');$table->unsignedBigInteger('max_risk')->default(1000000)->after('risk_percent');
        });
    }

    public function down(): void
    {
        Schema::table('user_risk_profiles', function (Blueprint $table) {$table->dropColumn(['chat_id', 'capital', 'risk_percent', 'max_risk']);
        });
    }
};
