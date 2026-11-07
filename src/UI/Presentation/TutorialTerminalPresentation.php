<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\Progression\TutorialStep;
use Ichiloto\Engine\UI\SelectionStyle;

/** A static guide treatment for existing terminal rows and help lines. */
final class TutorialTerminalPresentation
{
  public static function getHint(?TutorialStep $step): ?string
  {
    if ($step === null) {
      return null;
    }
    $hint = ActionHints::resolve($step->action, $step->say ?? 'Continue');
    return $hint->control === null ? null : TerminalControlText::formatHint($hint);
  }

  public static function highlight(string $line): string
  {
    return SelectionStyle::applyMarked($line);
  }
}
