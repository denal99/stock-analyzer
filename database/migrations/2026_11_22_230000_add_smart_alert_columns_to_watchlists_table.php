<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watchlists', function (Blueprint $table) {
            if (!Schema::hasColumn('watchlists', 'alert_mode')) {
                $table->string('alert_mode', 20)->default('all')->after('symbol');
            }
            if (!Schema::hasColumn('watchlists', 'target_bow')) {
                $table->double('target_bow')->nullable()->after('alert_mode');
            }
            if (!Schema::hasColumn('watchlists', 'target_bob')) {
                $table->double('target_bob')->nullable()->after('target_bow');
            }
            if (!Schema::hasColumn('watchlists', 'target_sl')) {
                $table->double('target_sl')->nullable()->after('target_bob');
            }
            if (!Schema::hasColumn('watchlists', 'min_vol_ratio')) {
                $table->double('min_vol_ratio')->default(1.2)->after('target_sl');
            }
        });
    }

    public function down(): void
    {
        Schema::table('watchlists', function (Blueprint $table) {
            $drop = array_values(array_filter(
                ['alert_mode', 'target_bow', 'target_bob', 'target_sl', 'min_vol_ratio'],
                fn($col) => Schema::hasColumn('watchlists', $col)
            ));
            if (!empty($drop)) {
                $table->dropColumn($drop);
            }
        });
    }
};