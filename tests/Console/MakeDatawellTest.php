<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::deleteDirectory(app_path('Datawell'));
});

afterEach(function (): void {
    File::deleteDirectory(app_path('Datawell'));
});

it('stamps the literal key and the model attribute into the stub', function (): void {
    $this->artisan('make:datawell', ['name' => 'DocumentSignaturesSource', '--model' => 'Signature'])
        ->assertSuccessful();

    $contents = File::get(app_path('Datawell/DocumentSignaturesSource.php'));

    expect($contents)
        ->toContain('namespace App\\Datawell;')
        ->toContain("return 'document-signatures';")
        ->toContain('#[Model(Signature::class)]')
        ->toContain('use App\\Models\\Signature;')
        ->toContain('use Datawell\\Attributes\\Model;')
        ->toContain('return Signature::query();')
        ->toContain('class DocumentSignaturesSource extends DataSource');
});

it('generates a model-less source that refuses to run until a query is written', function (): void {
    $this->artisan('make:datawell', ['name' => 'Audit/Events'])->assertSuccessful();

    $contents = File::get(app_path('Datawell/Audit/Events.php'));

    expect($contents)
        ->toContain('namespace App\\Datawell\\Audit;')
        ->toContain("return 'events';")
        ->not->toContain('#[Model(')
        ->toContain('Define the base query for this source.');
});

it('refuses to overwrite without --force', function (): void {
    $this->artisan('make:datawell', ['name' => 'Tags'])->assertSuccessful();
    $this->artisan('make:datawell', ['name' => 'Tags'])->expectsOutputToContain('already exists');
    $this->artisan('make:datawell', ['name' => 'Tags', '--force' => true])->assertSuccessful();
});
