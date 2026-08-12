<?php

use App\Ai\Admin\AdminOwner;
use App\Ai\Chat\SessionOwner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * laravel/ai 0.10 made conversation participants polymorphic: the store now
 * reads and writes participant_type + participant_id instead of user_id.
 * This converges our tables to the vendor 0.10 shape — while keeping
 * participant_id a string, because our participants are anonymous keys
 * (session id, "telegram:<chat id>", "admin:<id>"), not bigint user ids.
 * approval_state (new in 0.10, HITL approvals) is added to messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->renameColumn('user_id', 'participant_id');
        });

        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->string('participant_type')->nullable();
        });

        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->renameColumn('user_id', 'participant_id');
        });

        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->string('participant_type')->nullable();
            $table->text('approval_state')->nullable();
        });

        foreach (['agent_conversations', 'agent_conversation_messages'] as $tableName) {
            DB::table($tableName)
                ->whereNotNull('participant_id')
                ->where('participant_id', 'like', 'admin:%')
                ->update(['participant_type' => AdminOwner::class]);

            DB::table($tableName)
                ->whereNotNull('participant_id')
                ->whereNull('participant_type')
                ->update(['participant_type' => SessionOwner::class]);
        }

        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->dropIndex('agent_conversations_user_id_updated_at_index');
            $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
        });

        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->dropIndex('conversation_index');
            $table->dropIndex('agent_conversation_messages_user_id_index');
            $table->index(['conversation_id', 'participant_type', 'participant_id', 'updated_at'], 'conversation_index');
            $table->index(['participant_type', 'participant_id'], 'participant_index');
        });
    }

    public function down(): void
    {
        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->dropIndex('conversation_index');
            $table->dropIndex('participant_index');
            $table->dropColumn(['participant_type', 'approval_state']);
        });

        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->renameColumn('participant_id', 'user_id');
        });

        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'user_id', 'updated_at'], 'conversation_index');
            $table->index(['user_id']);
        });

        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->dropIndex('participant_updated_at_index');
            $table->dropColumn('participant_type');
        });

        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->renameColumn('participant_id', 'user_id');
        });

        Schema::table('agent_conversations', function (Blueprint $table) {
            $table->index(['user_id', 'updated_at']);
        });
    }
};
