<?php

namespace Ichiloto\Engine\Scenes\Battle\States;

use Ichiloto\Engine\Animations\Timelines\EffectPlaybackSession;
use Ichiloto\Engine\Animations\Timelines\LegacyAnimationTimeline;
use Ichiloto\Engine\Battle\UI\BattleResultWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\UI\Accessibility;
use Override;

class BattleStartState extends BattleSceneState
{
  protected ?EffectPlaybackSession $introPlayback = null;
  protected float $lastIntroTime = 0;
  protected float $animationDuration = 0.2;
  private ?int $renderedIntroFrame = null;
  private bool $reducedMotion = false;

  /**
   * @inheritDoc
   */
  #[Override]
  public function enter(): void
  {
    // Meeting an enemy is enough to open its bestiary entry.
    $gameScene = $this->scene->getGame()->sceneManager->findScene(GameScene::class);

    if ($gameScene instanceof GameScene && $gameScene->isStarted()) {
      foreach ($this->scene->troop?->members?->toArray() ?? [] as $enemy) {
        $gameScene->knowledge->discoverEnemy($enemy);
      }
    }

    $this->scene->ui = new BattleScreen($this->scene);
    $this->scene->resultWindow = new BattleResultWindow($this->scene->ui);
    if ($this->scene->getGame()->getRendererRuntime() !== null) {
      $characters = $this->scene->party->battlers->toArray();
      $this->scene->ui->characterNameWindow->setNames(array_map(static fn($member): string => $member->name, $characters));
      $this->scene->ui->characterStatusWindow->setCharacters($characters);
      $this->scene->ui->characterStatusWindow->clearAtbPercentages();
      $this->scene->ui->render();
      return;
    }
    $this->startTheIntroAnimation();
  }

  /**
   * @inheritDoc
   */
  public function execute(?SceneStateContext $context = null): void
  {
    if ($this->scene->getGame()->getRendererRuntime() !== null) {
      $this->scene->completeGraphicalEntry();
      return;
    }
    $this->playIntroAnimation();

    if ($this->introPlayback?->isCompleted) {
      // Remove the cover and build the incoming battle in one visible frame.
      Console::recomposeFrame(fn() => $this->setState($this->scene->runState));
    }
  }

  public function exit(): void
  {
    Console::removeOverlay($this->getIntroLayerId());
    $this->introPlayback = null;
    $this->renderedIntroFrame = null;
  }

  public function suspend(): void
  {
    $this->introPlayback?->pause();
  }

  public function resume(): void
  {
    $this->lastIntroTime = Time::getTime();
    $this->introPlayback?->resume();
    $this->refreshIntroPresentation();
  }

  /**
   * Start playing the intro animation.
   *
   * @return void
   */
  protected function startTheIntroAnimation(): void
  {
    Debug::info('Playing intro animation...');
    if (!is_finite($this->animationDuration) || $this->animationDuration <= 0) {
      throw new \InvalidArgumentException('Battle entry duration must be finite and positive.');
    }
    $frames = $this->loadAnimationFrameData();
    $fps = min(120, max(1, (int)ceil(count($frames) / $this->animationDuration)));
    $timeline = LegacyAnimationTimeline::compileTextFrames('battle-entry', $frames, $fps);
    $this->introPlayback = new EffectPlaybackSession($timeline,
      speed: count($frames) / ($fps * $this->animationDuration));
    $this->lastIntroTime = Time::getTime();
    $this->reducedMotion = Accessibility::prefersReducedMotion();
    if ($this->reducedMotion) { $this->introPlayback->seek($timeline->defaults['restFrame']); }
    $this->refreshIntroPresentation();
  }

  /**
   * Load the animation frame data.
   *
   * @return list<string>
   */
  protected function loadAnimationFrameData(): array
  {
    $frameSeparator = "@@---\n";
    try {
      $animationData = graphics('Animations/battle-transition', false);
    } catch (\Throwable $exception) {
      Debug::warn($exception->getMessage());
      $animationData = <<<TXT
****************
*   BATTLE!    *
****************
TXT;
    }

    return explode($frameSeparator, $animationData);
  }

  /**
   * Redraw the owned cover after a resize or resume without advancing playback.
   */
  public function refreshIntroPresentation(): void
  {
    if ($this->introPlayback === null) { return; }
    $content = $this->introPlayback->getActiveSegments()[0]['drawCommands'][0]['content'] ?? '';
    $frameLines = explode("\n", $content);
    $bounds = $this->scene->ui->screenDimensions;
    $lines = [];
    for ($row = 0; $row < Console::getHeight(); $row++) {
      $sourceRow = $row - $bounds->getTop();
      $line = $sourceRow >= 0 && $sourceRow < $bounds->getHeight()
        ? str_repeat(' ', max(0, $bounds->getLeft()))
          . TerminalText::padRight($frameLines[$sourceRow] ?? '', $bounds->getWidth()) : '';
      $lines[] = TerminalText::padRight($line, Console::getWidth());
    }
    Console::replaceOverlay($this->getIntroLayerId(), $lines, 0, 0, PresentationLayerPolicy::TRANSITION);
    $this->renderedIntroFrame = $this->introPlayback->currentFrame;
  }

  private function getIntroLayerId(): string
  {
    return 'battle-entry:' . spl_object_id($this);
  }

  /**
   * Play the intro animation.
   *
   * @return void
   */
  protected function playIntroAnimation(): void
  {
    if ($this->introPlayback === null) { return; }
    $now = Time::getTime();
    $elapsed = max(0.0, $now - $this->lastIntroTime);
    $this->lastIntroTime = $now;
    $this->introPlayback->update($this->reducedMotion ? $this->animationDuration : $elapsed);
    if (!$this->introPlayback->isCompleted && $this->renderedIntroFrame !== $this->introPlayback->currentFrame) {
      $this->refreshIntroPresentation();
    }
  }
}
