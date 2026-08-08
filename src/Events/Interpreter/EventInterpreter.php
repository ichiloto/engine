<?php

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Field\Location;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Throwable;

/**
 * Runs a data-driven event script: the generic cutscene engine.
 *
 * A script is a list of command entries executed in order. Commands use the
 * engine's blocking presentation primitives (dialogue boxes, selection
 * dialogs), so a script runs to completion within the frame that starts it —
 * the same model the dialogue system already uses.
 *
 * Supported commands:
 * - `['type' => 'text', 'name' => 'Elder', 'text' => '...']`
 * - `['type' => 'choice', 'prompt' => '...', 'options' => [['text' => 'Yes', 'then' => [...]], ...]]`
 * - `['type' => 'wait', 'seconds' => 1.0]`
 * - `['type' => 'set_switch', 'name' => 'x', 'value' => true]`
 * - `['type' => 'set_variable', 'name' => 'n', 'op' => 'set'|'add', 'value' => 1]`
 * - `['type' => 'record_event', 'name' => 'story_flag']`
 * - `['type' => 'give_item', 'item' => 'S-Potion', 'quantity' => 1]`
 * - `['type' => 'give_gold', 'amount' => 100]` (negative debits)
 * - `['type' => 'play_sound', 'sound' => '...']` / `['type' => 'play_music', 'music' => '...']`
 * - `['type' => 'accept_quest', 'id' => 'quest-id']`
 * - `['type' => 'move_player', 'x' => 5, 'y' => 6]`
 * - `['type' => 'transfer', 'map' => 'happyville/town-center', 'x' => 10, 'y' => 8]`
 * - `['type' => 'start_battle', 'troop' => 'Bat x 2']` — switches scenes; make it the final command
 * - `['type' => 'branch', 'conditions' => [...], 'then' => [...], 'else' => [...]]` —
 *   trigger-style conditions (switch/event/variable/item/quest, negatable)
 *
 * @package Ichiloto\Engine\Events\Interpreter
 */
class EventInterpreter
{
  /**
   * EventInterpreter constructor.
   *
   * @param GameScene $gameScene The running game scene.
   */
  public function __construct(protected GameScene $gameScene)
  {
  }

  /**
   * Runs a script.
   *
   * @param array<int, array<string, mixed>> $commands The script commands.
   * @return void
   */
  public function run(array $commands): void
  {
    foreach ($commands as $command) {
      if (! is_array($command)) {
        continue;
      }

      try {
        $this->execute($command);
      } catch (Throwable $exception) {
        Debug::error(sprintf(
          'Event command %s failed: %s',
          strval($command['type'] ?? '?'),
          $exception->getMessage()
        ));
      }
    }
  }

  /**
   * Executes one command.
   *
   * @param array<string, mixed> $command The command entry.
   * @return void
   */
  protected function execute(array $command): void
  {
    $gameState = $this->gameScene->gameState;

    switch (strval($command['type'] ?? '')) {
      case 'text':
        show_text(
          strval($command['text'] ?? ''),
          strval($command['name'] ?? ''),
          charactersPerSecond: dialogue_speed()
        );
        break;

      case 'choice':
        $options = array_values(array_filter((array) ($command['options'] ?? []), is_array(...)));

        if (empty($options)) {
          break;
        }

        $labels = array_map(static fn(array $option): string => strval($option['text'] ?? '…'), $options);
        $chosen = select(strval($command['prompt'] ?? 'Choose:'), $labels, strval($command['title'] ?? ''));
        $this->run((array) ($options[$chosen]['then'] ?? []));
        break;

      case 'wait':
        usleep(intval(floatval($command['seconds'] ?? 0.5) * 1_000_000));
        break;

      case 'set_switch':
        $gameState->setSwitch(strval($command['name'] ?? ''), (bool) ($command['value'] ?? true));
        break;

      case 'set_variable':
        $name = strval($command['name'] ?? '');
        strval($command['op'] ?? 'set') === 'add'
          ? $gameState->addToVariable($name, is_numeric($command['value'] ?? 1) ? $command['value'] + 0 : 1)
          : $gameState->setVariable($name, $command['value'] ?? 0);
        break;

      case 'record_event':
        $gameState->recordStoryEvent(strval($command['name'] ?? ''));
        break;

      case 'give_item':
        $itemStore = ConfigStore::get(ItemStore::class);
        $quantity = max(1, intval($command['quantity'] ?? 1));

        if ($itemStore instanceof ItemStore && $this->gameScene->party) {
          for ($count = 0; $count < $quantity; $count++) {
            $this->gameScene->party->addItems(...$itemStore->load([strval($command['item'] ?? '')]));
          }
        }
        break;

      case 'give_gold':
        $this->gameScene->party?->credit(intval($command['amount'] ?? 0));
        break;

      case 'play_sound':
        play_sound(strval($command['sound'] ?? ''));
        break;

      case 'play_music':
        play_music(strval($command['music'] ?? ''));
        break;

      case 'accept_quest':
        QuestManager::current()?->acceptQuest(strval($command['id'] ?? ''));
        break;

      case 'move_player':
        $player = $this->gameScene->player;

        if ($player !== null && isset($command['x'], $command['y'])) {
          $player->erase();
          $player->position->x = intval($command['x']);
          $player->position->y = intval($command['y']);
          $player->render();
        }
        break;

      case 'transfer':
        $spawn = new Vector2(intval($command['x'] ?? 0), intval($command['y'] ?? 0));
        $sprite = (array) ($command['sprite'] ?? ($this->gameScene->player?->sprite ?? ['@']));
        $this->gameScene->transferPlayer(new Location(strval($command['map'] ?? ''), $spawn, $sprite));
        break;

      case 'start_battle':
        $troop = get_troop(strval($command['troop'] ?? ''));

        if ($this->gameScene->party) {
          $this->gameScene->sceneManager->loadBattleScene($this->gameScene->party, $troop);
        }
        break;

      case 'branch':
        $holds = $this->conditionsHold((array) ($command['conditions'] ?? []));
        $this->run((array) ($command[$holds ? 'then' : 'else'] ?? []));
        break;

      default:
        Debug::warn(sprintf('Unknown event command type: %s', strval($command['type'] ?? '')));
    }
  }

  /**
   * Evaluates a trigger-style condition list against the world state.
   *
   * @param array<int, array<string, mixed>> $conditions The condition entries.
   * @return bool True when every condition holds.
   */
  protected function conditionsHold(array $conditions): bool
  {
    return WorldConditionEvaluator::allHold(
      $conditions,
      $this->gameScene->gameState,
      $this->gameScene->party
    );
  }
}
