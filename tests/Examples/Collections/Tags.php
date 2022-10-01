<?php

declare(strict_types=1);

namespace Rutek\DataclassTest\Examples\Collections;

use Rutek\Dataclass\Collection;

/** @extends Collection<string> */
class Tags extends Collection
{
    public function __construct(string ...$names)
    {
        $this->items = $names;
    }
}
