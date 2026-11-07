<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventSessionCompletionTargetInterface;
use Ichiloto\Engine\Scenes\Game\GameScene;

/** Acknowledges a saved story handoff at the cinematic completion boundary. */
final readonly class TutorialFollowOn implements EventSessionCompletionTargetInterface
{
  public function __construct(private TutorialDirector $director, private string $tutorialId) {}

  public function onEventSessionCompleted(GameScene $gameScene, EventExecutionSession $session): void
  {
    $this->director->acknowledgeFollowOn($this->tutorialId);
  }

  public function onEventSessionFailed(GameScene $gameScene, EventExecutionSession $session): void
  {
    $this->director->deferFailedFollowOn($this->tutorialId);
  }
}
