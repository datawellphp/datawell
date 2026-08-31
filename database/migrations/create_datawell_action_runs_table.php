<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datawell_action_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_key');
            $table->string('action_key');
            $table->string('status');
            $table->string('channel');
            $table->string('user_id')->nullable();
            $table->string('approval')->nullable();
            $table->unsignedInteger('targeted')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->json('failures')->nullable();
            $table->boolean('truncated')->default(false);
            $table->json('skipped_rows')->nullable();
            $table->json('links')->nullable();
            $table->text('message')->nullable();
            $table->string('batch_id')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datawell_action_runs');
    }
};
