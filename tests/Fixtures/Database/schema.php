<?php

declare(strict_types=1);

use Datawell\Tests\Fixtures\Models\Document;
use Datawell\Tests\Fixtures\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class
{
    public function create(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('workspace_id')->default(1);
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('role')->default('viewer');
            $table->unsignedInteger('age')->nullable();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->date('joined_on')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->string('timezone')->nullable();
            $table->json('abilities')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('documents');
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('title');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Documents referenced by the worked example: 123 (Acme NDA) and 500 (shared read-only).
     */
    public function seedDocuments(): void
    {
        Document::query()->create(['id' => 123, 'owner_id' => 1, 'title' => 'Acme Corp — NDA']);
        Document::query()->create(['id' => 500, 'owner_id' => 2, 'title' => 'Shared read-only']);
    }

    /**
     * Ten people in workspace 1, one outsider in workspace 2.
     */
    public function seedPeople(): void
    {
        $rows = [
            ['name' => 'Anna Smith', 'joined_on' => '2026-08-18', 'last_seen_at' => '2026-08-18 03:30:00', 'email' => 'anna@acme.com', 'role' => 'admin', 'age' => 41, 'active' => true, 'notes' => 'Founder'],
            ['name' => 'Ben Okoro', 'joined_on' => '2026-08-17', 'last_seen_at' => '2026-08-18 04:30:00', 'email' => 'ben@acme.com', 'role' => 'editor', 'age' => 29, 'active' => true, 'notes' => null],
            ['name' => 'Cara Smith', 'joined_on' => '2026-08-18', 'last_seen_at' => '2026-08-19 03:59:00', 'email' => 'cara@rival.com', 'role' => 'viewer', 'age' => 17, 'active' => true, 'notes' => '50% intern'],
            ['name' => 'Dev Patel', 'joined_on' => null, 'last_seen_at' => null, 'email' => 'dev@acme.com', 'role' => 'editor', 'age' => null, 'active' => false, 'notes' => null],
            ['name' => 'Eli Brown', 'joined_on' => '2026-07-01', 'last_seen_at' => '2026-08-19 04:00:00', 'email' => 'eli@acme.com', 'role' => 'viewer', 'age' => 35, 'active' => true, 'notes' => 'under_score'],
            ['name' => 'Fay Chen', 'joined_on' => '2026-03-07', 'last_seen_at' => '2026-03-08 04:30:00', 'email' => 'fay@acme.com', 'role' => 'viewer', 'age' => 35, 'active' => true, 'notes' => null],
            ['name' => 'Gus Reyes', 'joined_on' => '2026-03-08', 'last_seen_at' => '2026-03-09 03:30:00', 'email' => 'gus@acme.com', 'role' => 'editor', 'age' => 52, 'active' => false, 'notes' => null],
            ['name' => 'Hana Ito', 'joined_on' => '2026-03-09', 'last_seen_at' => '2026-03-09 04:30:00', 'email' => 'hana@acme.com', 'role' => 'viewer', 'age' => 35, 'active' => true, 'notes' => null],
            ['name' => 'Ivy Smithson', 'joined_on' => '2026-08-01', 'last_seen_at' => '2026-08-10 12:00:00', 'email' => 'ivy@acme.com', 'role' => 'viewer', 'age' => 23, 'active' => true, 'notes' => null],
            ['name' => 'Jon Alder', 'joined_on' => '2025-12-31', 'last_seen_at' => '2026-07-20 12:00:00', 'email' => 'jon@acme.com', 'role' => 'admin', 'age' => 60, 'active' => true, 'notes' => null],
        ];

        foreach ($rows as $row) {
            User::query()->create($row + ['workspace_id' => 1]);
        }

        User::query()->create(['name' => 'Zed Outsider', 'email' => 'zed@else.com', 'role' => 'viewer', 'age' => 30, 'active' => true, 'workspace_id' => 2]);
    }
};
