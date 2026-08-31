<?php

declare(strict_types=1);

use Datawell\Registry;
use Datawell\Tests\Fixtures\Models\User;
use Datawell\Timezone\TimezoneResolver;

it('resolves the schema per user with no shared state', function (): void {
    $source = app(Registry::class)->find('document-signatures');

    $first = $source->describe($this->privilegedViewer())->toArray();
    $second = $source->describe($this->viewer())->toArray();
    $third = $source->describe($this->privilegedViewer())->toArray();

    expect(array_column($first['fields'], 'key'))->toContain('signer_email')
        ->and(array_column($second['fields'], 'key'))->not->toContain('signer_email')
        ->and($third)->toBe($first)
        ->and($first['source']['timezone'])->toBe('Europe/London')
        ->and($second['source']['timezone'])->toBe('America/New_York');
});

it('falls through the timezone chain when the user declares none', function (): void {
    $source = app(Registry::class)->find('documents');
    $user = User::fake(9);

    expect($source->describe($user)->toArray()['source']['timezone'])->toBe('UTC');

    config()->set('datawell.timezone', 'Australia/Sydney');
    expect($source->describe($user)->toArray()['source']['timezone'])->toBe('Australia/Sydney');

    app(TimezoneResolver::class)->using(fn (): string => 'Asia/Tokyo');
    expect($source->describe($user)->toArray()['source']['timezone'])->toBe('Asia/Tokyo');
});
