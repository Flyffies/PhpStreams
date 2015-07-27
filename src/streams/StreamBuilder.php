<?php

namespace streams;

use ArrayIterator;
use Generator;
use streams\impl\StreamImpl;
use Traversable;

class StreamBuilder {

    public static function generate(Traversable $generator): Stream {
        return new StreamImpl($generator);
    }

    public static function fromArray(array $array): Stream {
        return new StreamImpl(new ArrayIterator($array));
    }

    public static function of(...$params): Stream {
        return static::fromArray($params);
    }

    public static function empty(): Stream {
        // TODO
    }

}