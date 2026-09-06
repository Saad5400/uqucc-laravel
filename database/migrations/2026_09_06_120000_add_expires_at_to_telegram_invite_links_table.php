<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_invite_links', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('member_limit');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_invite_links', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
