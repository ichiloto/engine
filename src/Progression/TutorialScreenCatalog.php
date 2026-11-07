<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\PlayerActionState as BattleInput;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState as MainMenu;
use Ichiloto\Engine\Scenes\Game\States\MasteryMenuState as Mastery;
use Ichiloto\Engine\Scenes\Game\States\SummonsMenuState as Summons;
use Ichiloto\Engine\UI\Presentation\MasteryMenuLevel;

/** The semantic targets and successful outcomes the Engine menus expose to authors. */
final class TutorialScreenCatalog
{
  /**
   * A dynamic target is resolved by the named project catalogue. Prefixes match
   * the corresponding live menu row identities; outcome subjects are raw ids.
   *
   * @return array<string, array<string, array{
   *   targets: list<string>,
   *   targetSource?: string,
   *   targetPrefix?: string,
   *   actions: list<string>,
   *   outcomes: array<string, list<string>|string>
   * }>>
   */
  public static function getViews(): array
  {
    return [
      MainMenu::TUTORIAL_SCREEN => [
        MainMenu::TUTORIAL_COMMANDS => [
          'targets' => array_values(MainMenu::TUTORIAL_COMMAND_TARGETS),
          'actions' => ['confirm'],
          'outcomes' => [MainMenu::TUTORIAL_COMMAND_OPENED => array_values(MainMenu::TUTORIAL_COMMAND_TARGETS)],
        ],
        MainMenu::TUTORIAL_PARTY => [
          'targets' => [], 'targetSource' => 'actor', 'targetPrefix' => 'member-',
          'actions' => ['confirm'],
          'outcomes' => [MainMenu::TUTORIAL_MEMBER_CONFIRMED => 'actor'],
        ],
      ],
      Summons::TUTORIAL_SCREEN => [
        Summons::TUTORIAL_LIST => [
          'targets' => [Summons::TUTORIAL_ASSIGNED_CONTRACT], 'targetSource' => 'summon', 'actions' => ['confirm'],
          'outcomes' => [Summons::TUTORIAL_SUMMON_OPENED => 'summon'],
        ],
        Summons::TUTORIAL_ACTIONS => [
          'targets' => ['assign'], 'actions' => ['confirm'],
          'outcomes' => [Summons::TUTORIAL_ASSIGN_OPENED => 'summon'],
        ],
        Summons::TUTORIAL_HOLDERS => [
          'targets' => [Summons::TUTORIAL_ASSIGNABLE_HOLDER], 'targetSource' => 'actor', 'targetPrefix' => 'member-',
          'actions' => ['confirm'],
          'outcomes' => [Summons::TUTORIAL_ASSIGNED => 'summon'],
        ],
      ],
      BattleInput::TUTORIAL_SCREEN => [
        BattleInput::TUTORIAL_COMMAND_VIEW => [
          'targets' => [BattleCommandType::SUMMON->value], 'actions' => ['action'],
          'outcomes' => [BattleInput::TUTORIAL_SUMMON_EXECUTED => []],
        ],
      ],
      Mastery::TUTORIAL_SCREEN => [
        MasteryMenuLevel::DISCIPLINE->value => [
          'targets' => [], 'targetSource' => 'mastery_region', 'actions' => ['confirm'],
          'outcomes' => [Mastery::TUTORIAL_DISCIPLINE_OPENED => 'mastery_region'],
        ],
        MasteryMenuLevel::FAMILY->value => [
          'targets' => [], 'targetSource' => 'mastery_family', 'actions' => ['confirm'],
          'outcomes' => [Mastery::TUTORIAL_FAMILY_OPENED => 'mastery_family'],
        ],
        MasteryMenuLevel::TREE->value => [
          'targets' => [], 'targetSource' => 'mastery_node', 'actions' => ['confirm', 'up', 'down', 'left', 'right'],
          'outcomes' => [Mastery::TUTORIAL_NODE_FOCUSED => 'mastery_node', Mastery::TUTORIAL_LEARNED => 'mastery_node'],
        ],
      ],
    ];
  }
}
