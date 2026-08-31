<?php

declare(strict_types=1);

use Datawell\Registry;

it('describes documents', function (): void {
    $schema = app(Registry::class)->find('documents')->describe($this->viewer());

    expect($schema->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))->toMatchSnapshot();
});

it('publishes a narrowed filter with its default as the resting posture', function (): void {
    $schema = app(Registry::class)->find('documents')->describe($this->viewer())->toArray();
    $archived = array_column($schema['filters'], null, 'key')['archived_at'];

    expect($archived['operators'])->toBe(['isEmpty', 'isNotEmpty'])
        ->and($archived['default'])->toBe(['operator' => 'isEmpty'])
        ->and($schema['defaultSort'])->toBe([['key' => 'created_at', 'direction' => 'desc']]);
});
