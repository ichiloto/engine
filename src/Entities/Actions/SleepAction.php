<?php

namespace Ichiloto\Engine\Entities\Actions;

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

      $leftMargin = (get_screen_width() / 2) - 2;
      $topMargin = (get_screen_height() / 2)  - 1;
      for ($index = 0; $index < $sleepAnimationFrameCount; $index++) {
        Console::clear();
        Console::write($sleepFrames[$index], $leftMargin, $topMargin);
        usleep($sleepInterval);
      }

      /** @var Character $member */
      foreach ($context->scene->party->members as $member) {
        if ($member->isConscious) {
          $member->stats->currentHp = $member->stats->totalHp;
          $member->stats->currentMp = $member->stats->totalMp;
        }
      }

      $context->player->availableAction = null;
      $context->player->position->x = $this->trigger->spawnPoint->x;
      $context->player->position->y = $this->trigger->spawnPoint->y;
      $context->player->sprite = $this->trigger->spawnSprite;
      Console::clear();
      $context->scene->mapManager->render();
      $context->player->render();
    }
  }
}