<?php

declare(strict_types=1);

namespace Ichiloto\Engine\IO;

use InvalidArgumentException;

/** A semantic action and its resolved display control, snapshotted for one measured redraw. */
final readonly class ActionHint
{
  public function __construct(public string $action, public string $label, public ?ControlHint $control)
  {
    foreach ([$action, $label] as $text) {
      if (trim($text) === '' || preg_match('//u', $text) !== 1 || preg_match('/\p{Cc}/u', $text) === 1) {
        throw new InvalidArgumentException('Action hints require nonempty UTF-8 action and label text without controls.');
      }
    }
  }
}
