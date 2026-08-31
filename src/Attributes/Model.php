<?php

declare(strict_types=1);

namespace Datawell\Attributes;

use Attribute;
use Illuminate\Database\Eloquent\Model as Eloquent;

/**
 * Declares the Eloquent model backing a source (D46). Optional: a source that
 * merges several models, or sits on a raw query, simply omits it and declares
 * field cardinality explicitly.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Model
{
    /**
     * @param  class-string<Eloquent>  $class
     */
    public function __construct(public readonly string $class) {}
}
