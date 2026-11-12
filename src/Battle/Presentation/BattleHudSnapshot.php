<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Battle\UI\BattleResultWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\UI\Windows\Window;

/** Null panels are hidden; visible empty panels remain explicit snapshots. */
final readonly class BattleHudSnapshot
{
  public function __construct(
    public ?BattleHudListSnapshot $commands = null,
    public ?BattleHudListSnapshot $context = null,
    public ?BattleHudListSnapshot $names = null,
    public ?BattleHudStatusSnapshot $status = null,
    public ?string $message = null,
    public int $messageRows = 1,
    /** The battle result window's page, while the battle shows its result. */
    public ?BattleOutcomeSnapshot $outcome = null,
  ) {}

  public static function fromScreen(BattleScreen $screen): self
  {
    $visible = $screen->presentationWindows();
    $result = array_find($visible, static fn(Window $window): bool => $window instanceof BattleResultWindow);

    return new self(
      commands: in_array($screen->commandWindow, $visible, true) ? $screen->commandWindow->presentationSnapshot() : null,
      context: in_array($screen->commandContextWindow, $visible, true) ? $screen->commandContextWindow->presentationSnapshot() : null,
      names: in_array($screen->characterNameWindow, $visible, true) ? $screen->characterNameWindow->presentationSnapshot() : null,
      status: in_array($screen->characterStatusWindow, $visible, true) ? $screen->characterStatusWindow->presentationSnapshot() : null,
      message: in_array($screen->messageWindow, $visible, true) ? $screen->messageWindow->presentationSnapshot() : null,
      messageRows: max(0, $screen->messageWindow->getHeight() - 2),
      outcome: $result?->presentationSnapshot(),
    );
  }

  /**
   * Whether a skinned HUD draws this battle window itself. Every window a snapshot reads is drawn by the HUD,
   * so no skinned battle shows another copy of it in Terminal cells.
   */
  public static function isPresentedWindow(BattleScreen $screen, Window $window): bool
  {
    return $window instanceof BattleResultWindow || in_array($window, [$screen->commandWindow,
      $screen->commandContextWindow, $screen->characterNameWindow, $screen->characterStatusWindow,
      $screen->messageWindow], true);
  }
}
