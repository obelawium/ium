<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Obelaw\Ium\Bases\MigrationBase;

return new class extends MigrationBase
{
    public function up(): void
    {
        Schema::create($this->prefix . 'outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->string('topic', 150)->index();
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('dispatched_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->prefix . 'outbox_messages');
    }
};
