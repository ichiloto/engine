<?php

namespace Ichiloto\Engine\Rendering\Presentation;

use Generator;
use IteratorAggregate;

/**
 * A world's operations queued for one renderer session, built only as the
 * upload sends them ({@see PresentationWorld::streamOperations()}). A world
 * map's per-cell operations then never exist all at once: the upload holds
 * one transport chunk of them at a time.
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final readonly class RetainedWorldUpload implements IteratorAggregate
{
    public function __construct(public PresentationWorld $world, public bool $tileCovers, public bool $tileShadows, public bool $rowRuns = false)
    {
    }

    /** @return Generator<int, array<string, mixed>> */
    public function getIterator(): Generator
    {
        return $this->world->streamOperations($this->tileCovers, $this->tileShadows, $this->rowRuns);
    }
}
