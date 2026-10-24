<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ActionHint;
use Ichiloto\Engine\IO\ControlHint;

/** Live lookup snapshot; neither presentation nor a glyph provider owns rebinding. */
final readonly class ControlsMenuContent
{
  /**
   * @param list<array{action:string, description:string, keys:list<ControlHint>, control:?ControlHint}> $rows
   *   Each action's every bound key, and the one control hints show for it.
   * @param list<ActionHint> $hints What the screen's controls do now.
   */
  public function __construct(public array $rows, public int $index, public bool $listening, public string $status,
    public array $hints = []) {}
}
