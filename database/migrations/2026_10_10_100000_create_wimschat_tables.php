<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WIMSChat — the internal messenger. Users, roles and files are NOT duplicated: members point at the existing users
 * table and attachments are private files on the local disk. Per-person state (read cursor, pin, archive, mute …) lives
 * on the membership row so unread counts are one indexed count, never a per-message receipt table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wimschat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 10)->default('direct'); // direct | group
            $table->string('title', 120)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('avatar_path')->nullable();
            // "min-max" of the two user ids: guarantees one direct conversation per pair
            $table->string('direct_key', 40)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['account_id', 'direct_key'], 'wc_direct_unique');
            $table->index(['last_message_at', 'deleted_at'], 'wc_last_idx');
        });

        Schema::create('wimschat_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('wimschat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 10)->default('member'); // member | admin
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->unsignedBigInteger('last_delivered_message_id')->default(0);
            $table->timestamp('pinned_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->boolean('muted')->default(false);
            $table->timestamp('muted_until')->nullable();
            $table->timestamp('hidden_at')->nullable(); // "deleted from my view" — comes back on the next message
            $table->boolean('marked_unread')->default(false);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id'], 'wc_member_unique');
            $table->index(['user_id', 'left_at', 'hidden_at'], 'wc_member_user_idx');
        });

        Schema::create('wimschat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->constrained('wimschat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 10)->default('text'); // text | system | nudge
            $table->text('body')->nullable();
            $table->unsignedBigInteger('reply_to_id')->nullable();
            $table->unsignedBigInteger('forwarded_from_id')->nullable();
            $table->string('client_uuid', 64)->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('pinned_at')->nullable();
            $table->foreignId('pinned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['conversation_id', 'id'], 'wc_msg_conv_idx');
            $table->index(['conversation_id', 'updated_at'], 'wc_msg_changed_idx');
            $table->index(['user_id', 'created_at'], 'wc_msg_user_idx');
            $table->unique(['user_id', 'client_uuid'], 'wc_msg_uuid_unique');
        });

        Schema::create('wimschat_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->constrained('wimschat_messages')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('wimschat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('extension', 16)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->string('category', 30)->nullable(); // images | pdf | documents …
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->char('checksum', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('thumb_path')->nullable();
            // a forwarded copy shares the original file on disk: it uses no new space and no upload quota
            $table->boolean('is_copy')->default(false);
            $table->timestamp('purged_at')->nullable(); // file erased by the retention rule; the record stays
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at'], 'wc_att_quota_idx');
            $table->index(['conversation_id', 'category', 'deleted_at'], 'wc_att_conv_idx');
        });

        Schema::create('wimschat_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('wimschat_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 32);
            $table->timestamp('created_at')->nullable();

            $table->unique(['message_id', 'user_id', 'emoji'], 'wc_react_unique');
        });

        Schema::create('wimschat_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('wimschat_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unique(['message_id', 'user_id'], 'wc_mention_unique');
            $table->index('user_id', 'wc_mention_user_idx');
        });

        Schema::create('wimschat_user_prefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->unique();
            $table->string('status', 20)->default('auto'); // auto = follow activity; otherwise a configured status key
            $table->json('prefs')->nullable(); // sound, desktop alerts, message preview, enter-to-send, receipts …
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['wimschat_user_prefs', 'wimschat_mentions', 'wimschat_reactions', 'wimschat_attachments', 'wimschat_messages', 'wimschat_members', 'wimschat_conversations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
