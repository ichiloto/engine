<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

use Ichiloto\Engine\Util\Debug;

/**
 * Reports a graphical presentation that falls back to a simpler one. A fallback keeps play going, so it is
 * logged as a warning once per distinct cause for as long as its owner (a scene or a presentation) lives,
 * rather than on every frame. Its owner keeps one report; a fallback never happens without one.
 */
final class PresentationFallbackReport
{
  /** @var array<string, true> */
  private array $reported = [];

  /** Logs the fallback the first time its owner meets this cause. */
  public function report(string $message): void
  {
    if (isset($this->reported[$message])) { return; }
    $this->reported[$message] = true;
    Debug::warn($message);
  }

  /** @return list<string> Every cause reported so far, in order. */
  public function getReportedMessages(): array
  {
    return array_keys($this->reported);
  }
}
