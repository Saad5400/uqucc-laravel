<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            if (! Schema::hasColumn('pages', 'quick_response_require_prefix')) {
                $table->boolean('quick_response_require_prefix')
                    ->default(true)
                    ->after('quick_response_send_link');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            if (Schema::hasColumn('pages', 'quick_response_require_prefix')) {
                $table->dropColumn('quick_response_require_prefix');
            }
        });
    }
};
