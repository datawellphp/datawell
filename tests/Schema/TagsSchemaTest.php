<?php

declare(strict_types=1);

use Datawell\Registry;

it('describes tags', function (): void {
    $schema = app(Registry::class)->find('tags')->describe($this->viewer());

    expect($schema->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))->toMatchSnapshot();
});
