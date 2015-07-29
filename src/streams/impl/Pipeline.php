<?php

declare(strict_types = 1);

namespace streams\impl;

use ArrayIterator;
use Exception;
use InvalidArgumentException;
use Iterator;
use streams\Comparator;
use Traversable;

class Pipeline {

    /** @var array Operations this pipeline will apply to the provided values */
    private $operations = [];

    /** @var Pipeline Source pipeline */
    private $sourcePipeline;

    /** @var Pipeline|null Next set of operation (the pipeline is split in case it needs sorting) */
    private $nextPipeline;

    /** @var int Limit of elements to handle. Only valid for the source pipeline */
    private $limit = PHP_INT_MAX;

    /** @var int Number of elements to skip. Only valid for the source pipeline */
    private $skipping = 0;

    /** @var int Whether this pipeline has already been executed or not. Only valid for the source pipeline */
    private $expired = false;

    /** @var int Count of the elements successfully handled by this pipeline */
    private $count = 0;

    /** @var bool Flag set if the pipeline execution ended with a sort operation */
    private $needsSorting = false;

    /** @var Comparator|null Comparator to use to sort the result of this pipeline if `needsSorting` is set */
    private $comparator = null;

    /** @var bool Whether this pipeline accepts new operations or not */
    private $finalized = false;

    /** @var bool Flag set when the sorting the pipeline is useless. Only valid for the source pipeline */
    private $preventSorting = false;

    public function __construct(Pipeline $source = null) {
        $this->sourcePipeline = $source ?? $this;
    }

    public function addOperation(/*OperationType*/int $type, $operation) {
        if($this->finalized && $this->nextPipeline === null) {
            $this->nextPipeline = new Pipeline();
        }

        if($this->nextPipeline !== null) {
            $this->nextPipeline->addOperation($type, $operation);
            return;
        }

        if($type === OperationType::SORT) {
            $this->needsSorting = true;
            $this->comparator = $operation;
            $this->finalized = true;
            return;
        }

        $this->operations[] = [$type, $operation];
    }

    public function setLimit(int $count) {
        if($this->expired) {
            throw new Exception('Pipeline has already been executed');
        }

        if($count < 0) {
            throw new InvalidArgumentException('Can\'t set negative limit');
        }

        $this->limit = $count;
    }

    public function setPreventSorting() {
        $this->preventSorting = true;
    }

    public function execute(Traversable... $targets): Iterator {
        if($this->expired) {
            throw new Exception('Pipeline has already been executed');
        }
        $this->expired = true;

        $pipeline = $this;
        $values = $this->mergeTraversables(...$targets);

        while($pipeline !== null) {
            $values = $pipeline->applyPipeline($values);
            if($pipeline->needsSorting && !$this->preventSorting) {
                $values = $pipeline->sort($values);
            }

            $pipeline = $pipeline->nextPipeline;
        }

        return $values;
    }

    private function mergeTraversables(Traversable... $targets): Iterator {
        foreach($targets as $target) {
            foreach($target as $v) {
                yield $v;
            }
        }
    }

    private function applyPipeline(Iterator $values) {
        foreach($values as $v) {
            if($this->count >= $this->sourcePipeline->limit) {
                break;
            }

            if($this->sourcePipeline->skipping > 0) {
                --$this->sourcePipeline->skipping;
                continue;
            }

            list($value, $filtered) = $this->applyOperations($v);

            if(!$filtered) {
                ++$this->count;
                yield $value;
            }
        }
    }

    private function applyOperations($value) {
        foreach($this->operations as &$operation) {
            list($value, $filtered) = $this->applyOperation($operation[0], $operation[1], $value);

            if($filtered) {
                return [$value, $filtered];
            }
        }
        return [$value, false];
    }

    private function applyOperation(/*OperationType*/int $type, &$operation, $value) {
        switch($type) {
            case OperationType::FILTER: {
                if(!$operation->test($value)) {
                    return [null, true];
                }
                break;
            }
            case OperationType::MAP: {
                $value = $operation->apply($value);
                break;
            }
            case OperationType::FLAT_MAP: {
                // TODO
                break;
            }
            case OperationType::SKIP: {
                if($operation > 0) {
                    $this->sourcePipeline->skipping += $operation - 1;
                    $operation = 0;
                    return [$value, true];
                }
                break;
            }
            case OperationType::PEEK: {
                $operation->accept($value);
                break;
            }
            default: {
                throw new Exception('Unsupported operation in the pipeline: ' . $type);
            }
        }
        return [$value, false];
    }

    private function sort(Iterator $values): Iterator {
        $values = iterator_to_array($values, false);

        usort($values, function($o1, $o2): int {
            return $this->comparator->compare($o1, $o2);
        });

        return new ArrayIterator($values);
    }

}
