<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ControlHint;

/** Live lookup snapshot; neither presentation nor a glyph provider owns rebinding. */
final readonly class ControlsMenuContent
{
  /** @param list<array{action:string, description:string, keys:string, control:?ControlHint}> $rows */
  public function __construct(public array $rows, public int $index, public bool $listening, public string $status) {}
}
