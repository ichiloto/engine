<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\{Console, TerminalText};
use Ichiloto\Engine\Progression\{TutorialInstructionPlacement, TutorialStep};
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;
use Ichiloto\Engine\UI\Windows\{Window, WindowAlignment};
use Ichiloto\Engine\UI\SelectionStyle;

/** A static guide treatment for existing terminal rows and help lines. */
final class TutorialTerminalPresentation
{
  private const string INSTRUCTION_LAYER = 'tutorial-instruction';

  /** Presents a centered instruction or returns text for the owner's explicitly contextual help. */
  public static function presentInstruction(?TutorialStep $step, ?string $speakerName = null): ?string
  {
    $hint = self::getHint($step, $speakerName);
    if ($hint === null || $step->placement === TutorialInstructionPlacement::CONTEXTUAL) {
      self::clearInstruction();
      return $hint;
    }

    $columns = Console::getWidth();
    $rows = Console::getHeight();
    $width = min(max(8, intdiv($columns * 2, 3)), TerminalText::displayWidth($hint) + 4);
    $lines = TerminalText::wrapToWidth($hint, $width - 4);
    $height = count($lines) + 2;
    if ($width > $columns || $height > $rows) {
      self::clearInstruction();
      return null;
    }

    $window = new Window(position: new Vector2(intdiv($columns - $width, 2), intdiv($rows - $height, 2)),
      width: $width, height: $height, alignment: WindowAlignment::middleCenter());
    $window->setContent($lines);
    $window->renderOverlay(self::INSTRUCTION_LAYER, PresentationLayerPolicy::UI + PresentationPriority::FIELD_HUD->value);
    return null;
  }

  public static function clearInstruction(): void
  {
    Console::removeOverlay(self::INSTRUCTION_LAYER);
  }

  public static function getHint(?TutorialStep $step, ?string $speakerName = null): ?string
  {
    $instruction = $step?->say;
    if ($instruction === null) {
      return null;
    }
    $label = $speakerName === null ? $instruction : $speakerName . ': ' . $instruction;
    $hint = ActionHints::resolve($step->action, $label);
    return $hint->control === null ? null : TerminalControlText::formatHint($hint);
  }

  public static function highlight(string $line): string
  {
    return SelectionStyle::applyMarked($line);
  }
}
