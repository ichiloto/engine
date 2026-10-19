<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use Throwable;

/**
 * Lets the party's conditions act while the player walks.
 *
 * Each step the player takes counts for every party member's states; a state
 * authored with `fieldTickSteps` acts once that many steps make up a field
 * turn (see {@see State}). The player is told when a condition brings a
 * member down to their lowest field HP or knocks them out, since the field
 * shows no HP. When the battle members are all knocked out the game is over,
 * as after a lost battle.
 *
 * @package Ichiloto\Engine\Field
 */
class FieldConditions
{
  /**
   * @param GameScene $gameScene The scene the party walks in.
   */
  public function __construct(protected GameScene $gameScene)
  {
  }

  /**
   * Registers one player step.
   *
   * @return bool True when the party walks on; false when it has fallen and the game is over.
   */
  public function registerStep(): bool
  {
    $party = $this->gameScene->party ?? null;

    if (! $party instanceof Party) {
      return true;
    }

    $outcome = self::advanceParty($party);

    foreach ($outcome['notices'] as $notice) {
      $this->notifyPlayer($notice['state'], $notice['member'], $notice['knockedOut']);
    }

    if ($outcome['defeated']) {
      $this->gameScene->sceneManager->loadGameOverScene();
      return false;
    }

    return true;
  }

  /**
   * Counts one step for every member's states.
   *
   * @param Party $party The party.
   * @return array{notices: list<array{state: State, member: Character, knockedOut: bool}>, defeated: bool}
   *   What the player is told, and whether a field condition knocked out the last standing battle member.
   */
  public static function advanceParty(Party $party): array
  {
    $notices = [];
    $knockedOut = false;

    /** @var Character $member */
    foreach ($party->members as $member) {
      foreach ($member->tickFieldStep() as $event) {
        if ($event['hpDelta'] >= 0) {
          continue;
        }

        $hp = $member->stats->currentHp;
        $before = $hp - $event['hpDelta'];

        if ($member->isKnockedOut) {
          $knockedOut = true;
          $notices[] = ['state' => $event['state'], 'member' => $member, 'knockedOut' => true];
        } elseif ($hp === 1 && $before > 1) {
          $notices[] = ['state' => $event['state'], 'member' => $member, 'knockedOut' => false];
        }
      }
    }

    return ['notices' => $notices, 'defeated' => $knockedOut && $party->isDefeated()];
  }

  /**
   * Tells the player what a condition has done to a member.
   *
   * @param State $state The condition.
   * @param Character $member The member.
   * @param bool $knockedOut True when the condition knocked the member out.
   * @return void
   */
  protected function notifyPlayer(State $state, Character $member, bool $knockedOut): void
  {
    try {
      notify(
        $this->gameScene->getGame(),
        NotificationChannel::WARNING,
        $state->name,
        $knockedOut
          ? get_engine_message('field.condition_collapsed', $member->name)
          : get_engine_message('field.condition_weakened', $member->name, $member->stats->currentHp),
        NotificationDuration::SHORT,
      );
    } catch (Throwable $exception) {
      Debug::warn(sprintf('Field condition notification failed: %s', $exception->getMessage()));
    }
  }
}
