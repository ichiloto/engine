<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\RetainedPresentation;
use Ichiloto\Engine\Rendering\RendererClient;

/**
 * A retained presentation for tests, on a declared work clock that never runs out: a world upload is sliced the
 * same way on every machine, however busy, instead of by how much real time the test happened to take.
 */
function makeRetainedTestPresentation(RendererClient $client, ?Closure $serviceTransport = null,
  ?Closure $clock = null): RetainedPresentation
{
  return new RetainedPresentation($client, $serviceTransport, $clock, workClock: static fn(): int => 0);
}
