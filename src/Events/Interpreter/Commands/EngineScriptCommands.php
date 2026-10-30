<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

/**
 * The script commands the Engine registers for every project, declared in
 * the same form a project declares its own.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
final class EngineScriptCommands
{
  public const array DECLARATIONS = [
    [
      'type' => 'shop',
      'class' => ShopCommand::class,
      'label' => 'Shop',
      'description' => 'Opens a shop. The script continues once the player leaves it.',
      'fields' => [
        [
          'key' => 'items',
          'label' => 'Merchandise',
          'kind' => 'list',
          'required' => true,
          'fields' => [
            ['key' => 'item', 'label' => 'Item', 'kind' => 'reference', 'reference' => 'item', 'required' => true],
            [
              'key' => 'price',
              'label' => 'Price',
              'kind' => 'integer',
              'minimum' => 0,
              'description' => "Replaces the item's own price in this shop.",
            ],
          ],
        ],
        [
          'key' => 'buyRate',
          'label' => 'Buy Rate',
          'kind' => 'number',
          'minimum' => 0,
          'description' => 'What the party pays, as a share of each price; 1 when not given.',
        ],
        [
          'key' => 'sellRate',
          'label' => 'Sell Rate',
          'kind' => 'number',
          'minimum' => 0,
          'description' => 'What the party is paid, as a share of each price; 0.5 when not given.',
        ],
      ],
    ],
    [
      'type' => 'inn',
      'class' => InnCommand::class,
      'label' => 'Inn',
      'description' => 'Offers the party a paid rest that restores them, then the script continues.',
      'fields' => [
        ['key' => 'confirmDialogue.text', 'label' => 'Question', 'kind' => 'text', 'required' => true],
        ['key' => 'confirmDialogue.name', 'label' => 'Speaker', 'kind' => 'text'],
        ['key' => 'cost', 'label' => 'Cost', 'kind' => 'integer', 'minimum' => 0],
        [
          'key' => 'spawnPoint',
          'label' => 'Wake At',
          'kind' => 'position',
          'description' => 'Where on this map the party wakes; where they stand when not given.',
        ],
        [
          'key' => 'bgm',
          'label' => 'Rest Music',
          'kind' => 'reference',
          'reference' => 'music',
          'description' => "Played while the party sleeps; the project's sleep theme when not given.",
        ],
        [
          'key' => 'presentation',
          'label' => 'Rest Presentation',
          'kind' => 'reference',
          'reference' => 'stage_timeline',
          'description' => 'Graphical rest stage identity or explicit leader/party stage selection; graphics.inn.presentation when not given. Terminal rest timing is unchanged.',
        ],
        [
          'key' => 'resultVariable',
          'label' => 'Result Variable',
          'kind' => 'text',
          'description' => 'Receives stayed, declined or unaffordable.',
        ],
      ],
    ],
    [
      'type' => 'payday',
      'class' => PaydayCommand::class,
      'label' => 'Payday',
      'description' => 'Pays one period of an income schedule now, at its current rate, held or deposited as the schedule says.',
      'fields' => [
        ['key' => 'schedule', 'label' => 'Schedule', 'kind' => 'reference', 'reference' => 'income_schedule', 'required' => true],
        [
          'key' => 'resultVariable',
          'label' => 'Result Variable',
          'kind' => 'text',
          'description' => 'Receives the pay; 0 while the schedule is not running.',
        ],
      ],
    ],
    [
      'type' => 'collect_income',
      'class' => CollectIncomeCommand::class,
      'label' => 'Collect Income',
      'description' => 'Moves the pay an income schedule holds into party gold.',
      'fields' => [
        ['key' => 'schedule', 'label' => 'Schedule', 'kind' => 'reference', 'reference' => 'income_schedule', 'required' => true],
        [
          'key' => 'resultVariable',
          'label' => 'Result Variable',
          'kind' => 'text',
          'description' => 'Receives the amount collected; 0 when nothing was waiting.',
        ],
      ],
    ],
    [
      'type' => 'adjust_income',
      'class' => AdjustIncomeCommand::class,
      'label' => 'Adjust Income',
      'description' => "Adds a one-time bonus, or a deduction when negative, to an income schedule's next payment.",
      'fields' => [
        ['key' => 'schedule', 'label' => 'Schedule', 'kind' => 'reference', 'reference' => 'income_schedule', 'required' => true],
        ['key' => 'amount', 'label' => 'Amount', 'kind' => 'integer', 'required' => true],
      ],
    ],
    [
      'type' => 'set_party_size',
      'class' => PartyParticipationCommand::class,
      'label' => 'Set Party Battle Size',
      'description' => 'Sets this party’s frontline capacity from 1 to 8 without changing membership.',
      'fields' => [
        ['key' => 'size', 'label' => 'Battle Members', 'kind' => 'integer', 'minimum' => 1, 'maximum' => 8, 'required' => true],
      ],
    ],
    [
      'type' => 'select_party',
      'class' => PartyParticipationCommand::class,
      'label' => 'Select Present Party',
      'description' => 'Selects 1 to 8 permanent actors for field movement, menus and battles. Other members keep their progress.',
      'fields' => [
        ['key' => 'members', 'label' => 'Present Members', 'kind' => 'list', 'minimum' => 1, 'maximum' => 8, 'required' => true, 'fields' => [
          ['key' => 'actor', 'label' => 'Actor', 'kind' => 'reference', 'reference' => 'actor', 'required' => true],
        ]],
      ],
    ],
    [
      'type' => 'regroup_party',
      'class' => PartyParticipationCommand::class,
      'label' => 'Regroup Party',
      'description' => 'Restores the permanent party’s membership and order after a split, without recreating actors.',
      'fields' => [],
    ],
    [
      'type' => 'recruit_actor',
      'class' => RecruitActorCommand::class,
      'label' => 'Recruit Actor',
      'description' => 'Adds the selected actor once using its current definition and selected starting-level policy. Existing members keep their progress and order; a full frontline sends the new member to reserve.',
      'fields' => [
        ['key' => 'actor', 'label' => 'Actor', 'kind' => 'reference', 'reference' => 'actor', 'required' => true],
        ['key' => 'levelPolicy', 'label' => 'Starting Level', 'kind' => 'option',
          'options' => ['authored', 'partyAverage'],
          'description' => 'Authored level, or the permanent party roster average rounded down before this actor joins.'],
        ['key' => 'spPolicy', 'label' => 'Starting SP', 'kind' => 'option',
          'options' => ['authored', 'partyAverageEarned', 'partyAverageEstimatedEarned'],
          'description' => 'Authored points, roster-average recorded earned SP, or that average with explicitly authorized legacy spending estimated once from current learned-node prices. Existing members receive no repeat grant.'],
      ],
    ],
    [
      'type' => 'end_battle',
      'class' => EndBattleCommand::class,
      'label' => 'End Battle',
      'description' => 'Ends the battle once this page finishes. Victory counts every enemy still fighting as defeated and awards the ordinary victory rewards.',
      'scopes' => ['battle'],
      'fields' => [
        [
          'key' => 'outcome',
          'label' => 'Outcome',
          'kind' => 'option',
          'options' => ['victory', 'escape', 'defeat'],
          'required' => true,
          'description' => "Defeat follows the battle's defeat policy: game over unless the script that started it continues.",
        ],
      ],
    ],
    [
      'type' => 'concede',
      'class' => ConcedeCommand::class,
      'label' => 'Enemy Concedes',
      'description' => 'The enemy stops fighting and counts as defeated for victory and rewards. It stays on screen in its conceding pose instead of fading out like a knockout.',
      'scopes' => ['battle'],
      'fields' => [
        [
          'key' => 'enemy',
          'label' => 'Enemy',
          'kind' => 'reference',
          'reference' => 'troop_member',
          'required' => true,
        ],
      ],
    ],
    [
      'type' => 'select_conversation_group',
      'class' => SelectConversationGroupCommand::class,
      'label' => 'Conversation Group',
      'description' => 'Selects the active cinematic conversation phase and its participating speakers.',
      'fields' => [
        ['key' => 'group', 'label' => 'Group', 'kind' => 'reference', 'reference' => 'conversation_group', 'required' => true],
      ],
    ],
    [
      'type' => 'set_bed_posture',
      'class' => SetBedPostureCommand::class,
      'label' => 'Bed Posture',
      'description' => 'Changes a current bed occupant posture; checks the exit before getting up and preserves saved state on interruption.',
      'fields' => [
        ['key' => 'npc', 'label' => 'Occupant', 'kind' => 'reference', 'reference' => 'npc', 'required' => true],
        ['key' => 'posture', 'label' => 'Posture', 'kind' => 'option', 'options' => ['lying', 'sitting', 'standing'], 'required' => true],
        ['key' => 'seconds', 'label' => 'Transition Seconds', 'kind' => 'number', 'minimum' => 0, 'maximum' => 30],
      ],
    ],
    [
      'type' => 'commit_npc_placement',
      'class' => CommitNpcPlacementCommand::class,
      'label' => 'Commit NPC Placement',
      'description' => 'Saves a named placement after the real NPC physically reaches it; reload uses current authored geometry.',
      'fields' => [
        ['key' => 'npc', 'label' => 'NPC', 'kind' => 'reference', 'reference' => 'npc', 'required' => true],
        ['key' => 'placement', 'label' => 'Placement', 'kind' => 'reference', 'reference' => 'npc_placement', 'required' => true],
      ],
    ],
    [
      'type' => 'show_power_unlocks',
      'class' => ShowPowerUnlocksCommand::class,
      'label' => 'Show Unlocked Powers',
      'description' => 'Presents current unlocked power definitions one at a time and waits for acknowledgement.',
      'fields' => [
        ['key' => 'abilities', 'label' => 'World Powers', 'kind' => 'list', 'fields' => [
          ['key' => 'ability', 'label' => 'Power', 'kind' => 'reference', 'reference' => 'world_ability', 'required' => true],
        ]],
        ['key' => 'actor', 'label' => 'Actor', 'kind' => 'reference', 'reference' => 'actor'],
        ['key' => 'includeCrisis', 'label' => 'Current Crisis Technique', 'kind' => 'boolean'],
        ['key' => 'announcement', 'label' => 'Opening Announcement', 'kind' => 'text'],
      ],
    ],
  ];
}
