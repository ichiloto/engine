<?php

namespace Ichiloto\Engine\Inn;

use Exception;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * A stay at an inn: the party is asked, pays, sleeps and recovers.
 *
 * Every way a stay is offered runs this one stay: the sleep trigger's
 * SleepAction and the `inn` script command.
 *
 * The question and the rest are shown synchronously, as the sleep trigger
 * has always shown them.
 *
 * @package Ichiloto\Engine\Inn
 */
class InnStay
{
  /** The confirmation choice that accepts the stay. */
  public const int CONFIRM_CHOICE = 0;
  /**
   * The time to sleep, in seconds, unless the project sets `inn.sleep_time`.
   */
  protected const int SLEEP_TIME = 3;

  /**
   * @param InnOffer $offer What the inn asks and where the party wakes.
   */
  public function __construct(
    protected InnOffer $offer,
  )
  {
  }

  /**
   * Offers the stay and, when the party accepts and can pay, rests them.
   *
   * @param GameScene $scene The scene the party is in.
   * @return InnStayOutcome How the stay ended.
   * @throws Exception If an error occurs while showing the dialogue.
   */
  public function perform(GameScene $scene): InnStayOutcome
  {
    $this->offer->confirmDialogue->show();

    if ($this->offer->confirmDialogue->selectedChoice !== self::CONFIRM_CHOICE) {
      return InnStayOutcome::DECLINED;
    }

    if ($scene->party->cannotAfford($this->offer->cost)) {
      alert('Sorry, you cannot afford to sleep here.');
      return InnStayOutcome::UNAFFORDABLE;
    }

    $scene->party->debit($this->offer->cost);

    // Remember what was playing so the field picks up exactly where it
    // left off: sleeping never changes maps, so nothing else restores it.
    $previousTrack = current_music();
    $restMusicStarted = false;
    $scene->holdFieldMusic();
    try {
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
    } finally {
      try {
        $this->restoreMusic($previousTrack, $restMusicStarted);
      } finally {
        $scene->releaseFieldMusic();
      }
    }

    /** @var Character $member */
    foreach ($scene->party->members as $member) {
      $member->restoreVitals();
    }

    $player = $scene->player;

    if ($this->offer->spawnPoint !== null) {
      $player->availableAction = null;
      $player->position->x = $this->offer->spawnPoint->x;
      $player->position->y = $this->offer->spawnPoint->y;
    }

    if ($this->offer->spawnSprite !== null) {
      $player->setFacingSprite($this->offer->spawnSprite);
    }

    Console::clear();
    $scene->mapManager->render();
    $player->render();

    return InnStayOutcome::STAYED;
  }

  /**
   * Starts the rest theme while the party sleeps.
   *
   * An inn may declare its own track through the `bgm` entry in its data;
   * otherwise the project-wide `audio.bgm.sleep` theme is used. A project
   * that configures neither keeps whatever is already playing, so this
   * stays optional like the rest of the audio.
   *
   * @return bool True when rest music replaced the current track.
   */
  protected function playSleepMusic(): bool
  {
    $track = $this->offer->backgroundMusic
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
