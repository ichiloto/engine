<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Events\Interfaces\EventTriggerContextInterface;
use Override;

/**
 * Plays audio when the player enters the trigger area.
 *
 * This is the map-event equivalent of RPG Maker's "Play BGM" / "Play SE"
 * commands, for authored moments such as a boss appearing. Reference it from
 * a map's event data:
 *
 * ```php
 * 'M' => [
 *   'class' => 'Ichiloto\\Engine\\Events\\Triggers\\PlayAudioEventTrigger',
 *   'data' => [
 *     'bgm' => 'boss-approach',   // optional: track that replaces the music
 *     'sfx' => 'roar',            // optional: one-shot sound effect
 *     'once' => true,             // optional: fire a single time (default false)
 *     'restoreMapBgmOnExit' => true, // optional: bring the map theme back
 *                                    // when the player leaves the area
 *   ],
 * ],
 * ```
 *
 * @package Ichiloto\Engine\Events\Triggers
 */
class PlayAudioEventTrigger extends EventTrigger
{
  /**
   * @var string The background music to play on enter, if any.
   */
  protected string $backgroundMusic = '';
  /**
   * @var string The sound effect to play on enter, if any.
   */
  protected string $soundEffect = '';
  /**
   * @var bool Whether the map's declared theme is restored on exit.
   */
  protected bool $restoreMapBgmOnExit = false;

  /**
   * @inheritDoc
   */
  public function configure(): void
  {
    $this->backgroundMusic = trim(strval($this->data->bgm ?? ''));
    $this->soundEffect = trim(strval($this->data->sfx ?? ''));
    $this->restoreMapBgmOnExit = boolval($this->data->restoreMapBgmOnExit ?? false);
    $this->isReusable = ! boolval($this->data->once ?? false);
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function enter(EventTriggerContextInterface $context): void
  {
    parent::enter($context);

    if ($this->isComplete) {
      return;
    }

    $audioManager = $context->scene->getGame()->audioManager;

    if ($this->soundEffect !== '') {
      $audioManager->playSoundEffect($this->soundEffect);
    }

    if ($this->backgroundMusic !== '') {
      $audioManager->playBackgroundMusic($this->backgroundMusic);
    }

    $this->complete();
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function exit(EventTriggerContextInterface $context): void
  {
    parent::exit($context);

    if (! $this->restoreMapBgmOnExit) {
      return;
    }

    $mapTheme = $context->scene->mapManager?->backgroundMusic;

    if ($mapTheme !== null) {
      $context->scene->getGame()->audioManager->playBackgroundMusic($mapTheme);
    }
  }
}
