<?php

declare(strict_types=1);

use Datawell\Tests\Fixtures\Models\Document;
use Datawell\Tests\Fixtures\Models\Reminder;
use Datawell\Tests\Fixtures\Models\Signature;
use Datawell\Tests\Fixtures\Models\Tag;
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

        Schema::dropIfExists('signatures');
        Schema::create('signatures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('signer_id')->nullable();
            $table->string('status')->default('pending');
            $table->dateTime('requested_at');
            $table->dateTime('signed_at')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('tags');
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::dropIfExists('signature_tag');
        Schema::create('signature_tag', function (Blueprint $table): void {
            $table->unsignedBigInteger('signature_id');
            $table->unsignedBigInteger('tag_id');
        });

        Schema::dropIfExists('reminders');
        Schema::create('reminders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('signature_id');
            $table->dateTime('sent_at');
            $table->timestamps();
        });
    }

    /**
     * The worked example's signatures on document 123 (plus one on 500): signers drawn
     * from the people seeds, one signer outside the people scope (D36), one with no
     * signer at all (null ordering), tags in id order 3 < 9 < 14, and reminders.
     */
    public function seedSignatures(): void
    {
        Tag::query()->create(['id' => 14, 'name' => 'Urgent']);
        Tag::query()->create(['id' => 3, 'name' => 'Legal']);
        Tag::query()->create(['id' => 9, 'name' => 'Internal']);

        $rows = [
            1 => ['signer_id' => 1, 'status' => 'signed', 'requested_at' => '2026-08-10 09:00:00', 'signed_at' => '2026-08-11 10:00:00', 'tags' => [14, 3], 'reminders' => 2],
            2 => ['signer_id' => 2, 'status' => 'pending', 'requested_at' => '2026-08-12 09:00:00', 'signed_at' => null, 'tags' => [14], 'reminders' => 1],
            3 => ['signer_id' => 3, 'status' => 'declined', 'requested_at' => '2026-08-13 09:00:00', 'signed_at' => null, 'tags' => [], 'reminders' => 0],
            4 => ['signer_id' => 1, 'status' => 'pending', 'requested_at' => '2026-08-14 09:00:00', 'signed_at' => null, 'tags' => [3, 9, 14], 'reminders' => 0],
            5 => ['signer_id' => 11, 'status' => 'pending', 'requested_at' => '2026-08-15 09:00:00', 'signed_at' => null, 'tags' => [], 'reminders' => 3],
            6 => ['signer_id' => null, 'status' => 'pending', 'requested_at' => '2026-08-16 09:00:00', 'signed_at' => null, 'tags' => [9], 'reminders' => 0],
        ];

        foreach ($rows as $id => $row) {
            $this->createSignature($id, 123, $row);
        }

        $this->createSignature(7, 500, ['signer_id' => 2, 'status' => 'signed', 'requested_at' => '2026-08-01 09:00:00', 'signed_at' => '2026-08-02 09:00:00', 'tags' => [14], 'reminders' => 1]);
    }

    /**
     * @param  array{signer_id: int|null, status: string, requested_at: string, signed_at: string|null, tags: list<int>, reminders: int}  $row
     */
    private function createSignature(int $id, int $documentId, array $row): void
    {
        $signature = Signature::query()->create([
            'id' => $id,
            'document_id' => $documentId,
            'signer_id' => $row['signer_id'],
            'status' => $row['status'],
            'requested_at' => $row['requested_at'],
            'signed_at' => $row['signed_at'],
        ]);

        $signature->tags()->attach($row['tags']);

        for ($i = 1; $i <= $row['reminders']; $i++) {
            Reminder::query()->create(['signature_id' => $id, 'sent_at' => "2026-08-2{$i} 09:00:00"]);
        }
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
