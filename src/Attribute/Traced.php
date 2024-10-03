<?php

declare(strict_types=1);

namespace Cardyo\SpiralSentryTracing\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Traced
{
}

