<?php

namespace Ichiloto\Engine\Entities\Actions;

use Ichiloto\Engine\Core\Timers;
use Exception;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Interfaces\ActionContextInterface;
use Ichiloto\Engine\Events\Triggers\SleepEventTrigger;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Util\Config\ProjectConfig;

define('CONFIRM_CHOICE', 0);
define('DECLINE_CHOICE', 1);

/**
 * Class SleepAction. A field action that simulates a sleep event.
 *
 * @package Ichiloto\Engine\Entities\Actions
 */
class SleepAction extends FieldAction
{
  /**
   * The time to sleep in milliseconds.
   */
  protected const int SLEEP_TIME = 3; // 5 seconds
  /**
   * Constructs a new instance of SleepAction.
   *
   * @param SleepEventTrigger $trigger The trigger that initiated the sleep event.
   */
  public function __construct(
    protected SleepEventTrigger $trigger
  )
  {
  }

  /**
   * @inheritDoc
   * @throws Exception If an error occurs while showing the dialogue.
   */
  public function execute(ActionContextInterface $context): void
  {
    $this->trigger->confirmDialogue->show();
    if ($this->trigger->confirmDialogue->selectedChoice === CONFIRM_CHOICE) {
      if ($context->party->cannotAfford($this->trigger->cost)) {
        alert('Sorry, you cannot afford to sleep here.');
        return;
      }

      $context->party->debit($this->trigger->cost);

      // Remember what was playing so the field picks up exactly where it
      // left off: sleeping never changes maps, so nothing else restores it.
      $previousTrack = current_music();
      $restMusicStarted = $this->playSleepMusic();

      $sleepFrames = [
        'Z',
        'Zz',
        'ZzZ',
        'ZzZz',
        'ZzZzZ',
      ];
      $sleepAnimationFrameCount = count($sleepFrames);
      $sleepTime = config(ProjectConfig::class, 'inn.sleep_time', self::SLEEP_TIME);
      $sleepInterval = intval((clamp($sleepTime, 1, 10) * 1000000) / $sleepAnimationFrameCount);

      $leftMargin = intdiv(get_screen_width(), 2) - 2;
      $topMargin = intdiv(get_screen_height(), 2) - 1;
      for ($index = 0; $index < $sleepAnimationFrameCount; $index++) {
        Console::clear();
        Console::write($sleepFrames[$index], $leftMargin, $topMargin);
        Timers::wait($sleepInterval / 1_000_000);
      }

      $this->restoreMusic($previousTrack, $restMusicStarted);

      /** @var Character $member */
      foreach ($context->scene->party->members as $member) {
        $member->stats->currentHp = $member->stats->totalHp;
        $member->stats->currentMp = $member->stats->totalMp;
      }

      $context->player->availableAction = null;
      $context->player->position->x = $this->trigger->spawnPoint->x;
      $context->player->position->y = $this->trigger->spawnPoint->y;
      $context->player->setFacingSprite($this->trigger->spawnSprite);
      Console::clear();
      $context->scene->mapManager->render();
      $context->player->render();
    }
  }

  /**
   * Starts the rest theme while the party sleeps.
   *
   * An inn may declare its own track through the `bgm` entry in its trigger
   * data; otherwise the project-wide `audio.bgm.sleep` theme is used. A
   * project that configures neither keeps whatever is already playing, so
   * this stays optional like the rest of the audio.
   *
   * @return bool True when rest music replaced the current track.
   */
  protected function playSleepMusic(): bool
  {
    $track = $this->trigger->backgroundMusic
      ?? $this->getConfiguredTrack('audio.bgm.sleep');

    if ($track === null) {
      return false;
    }

    play_music($track);

    return true;
  }

  /**
   * Restores the music that was playing before the party slept.
   *
   * @param string|null $previousTrack The track playing before the rest.
   * @param bool $wasInterrupted Whether rest music actually replaced it.
   * @return void
   */
  protected function restoreMusic(?string $previousTrack, bool $wasInterrupted): void
  {
    if (! $wasInterrupted) {
      return;
    }

    if ($previousTrack === null) {
      stop_music();
      return;
    }

    play_music($previousTrack);
  }

  /**
   * Reads a background music reference from the project config.
   *
   * @param string $configPath The project config path.
   * @return string|null The configured track, or null when not configured.
   */
  protected function getConfiguredTrack(string $configPath): ?string
  {
    $track = config(ProjectConfig::class, $configPath);

    if (! is_string($track)) {
      return null;
    }

    $track = trim($track);

    return $track === '' ? null : $track;
  }
}
