# Scheduled income

Scheduled income pays the party on a regular cadence: a salary, a stipend, rent
from a property, a guild allowance. It is off unless the project declares at
least one schedule, and every choice below is project data authored in the
Editor (Database, Income) or the GUI editor.

## Data

`assets/Data/income.php` returns one list of schedules:

```php
return [
  'schedules' => [
    [
      'id' => 'guild-stipend',
      'name' => 'Guild Stipend',
      'conditions' => [['type' => 'event', 'name' => 'joined_guild']],
      'cadence' => 'active_time',
      'interval' => 3600,
      'idleTimeout' => 120,
      'amount' => 300,
      'rates' => [
        ['conditions' => [['type' => 'variable', 'name' => 'guild_rank', 'value' => 'B']], 'amount' => 600],
        ['conditions' => [['type' => 'variable', 'name' => 'guild_rank', 'value' => 'C']], 'amount' => 450],
      ],
      'delivery' => 'hold',
    ],
  ],
];
```

| Field | Meaning |
| --- | --- |
| `id` | Stable identity. Saves and script commands name it. Required and unique. |
| `name` | Title of the schedule's notices. Required. |
| `conditions` | Shared world conditions. While they do not hold, the cadence counts nothing and `payday` pays nothing. Optional. |
| `cadence` | `active_time`, `steps`, `battles` or `event`. Required. |
| `interval` | Seconds of active play, steps or won battles per payment. Required for counted cadences; refused for `event`. |
| `idleTimeout` | `active_time` only. Seconds without input after which play stops counting. Default 120. |
| `amount` | Pay per period when no rate holds. Required, at least 0. |
| `rates` | Ordered pay table. The first row whose conditions hold when a payment is made sets that payment. Each row needs non-empty `conditions` and an `amount`. |
| `delivery` | `deposit` (straight into party gold) or `hold` (kept until collected). Default `deposit`. |

Rates use the same conditions as every other Engine feature, so pay can follow
a rank variable, a story event, a switch, a completed quest or an item. Rates
are read at payment time, so a promotion raises the next payment.

## Cadences

- **`active_time`** counts seconds the player spends playing. Each scene
  reports a `PlayActivity`:
  - *playing*: free field movement, the overworld, dialogue, battle commands.
    The idle timeout applies here.
  - *presenting*: cutscenes, a running event on the field, battle openings and
    results. These count without input.
  - *paused*: menus, shops, saving, map views, the battle pause menu, the title,
    open modals, and any scene that does not implement
    `PlayActivitySourceInterface`. Nothing counts.

  One frame contributes at most `PlayActivity::MAX_FRAME_SECONDS` (0.5), so time
  spent blocked in a modal or with the machine asleep never counts. Saved play
  time (the wall clock shown in menus and save slots) is unchanged and separate.
  A game-defined scene, such as a minigame excursion, counts once it implements
  `PlayActivitySourceInterface`.
- **`steps`** counts every step the player walks on a field map under their own
  control. Scripted movement (move routes, cinematics) is not counted.
- **`battles`** counts battles the party wins. Escapes and defeats do not count.
- **`event`** counts nothing. Pay arrives only through `payday`, so a game with
  its own calendar, day and night cycle or story beats decides when.

When one count completes several periods at once, every completed period is
paid. What remains carries over to the next period.

## Script commands

| Command | Fields | Effect |
| --- | --- | --- |
| `payday` | `schedule`, optional `resultVariable` | Pays one period now at the current rate, held or deposited as the schedule says. Counted progress is untouched. Pays 0 while the schedule's conditions do not hold. |
| `collect_income` | `schedule`, optional `resultVariable` | Moves held pay into party gold. What does not fit under the gold limit stays held. Collection does not check the schedule's conditions: earned pay stays collectable. |
| `adjust_income` | `schedule`, `amount` | Adds a one-time bonus (positive) or deduction (negative) to the next payment. A deduction larger than that payment carries on to later payments. A payment never takes gold away. |

`resultVariable` receives the amount paid or collected, so a collection event
can answer "nothing waiting" in its own words, or run the game's own scene.
Naming a schedule the project does not declare fails the event with that id.

## Notices

Each payment or collection shows an info notice titled with the schedule's
name. Its text is an Engine message (Database, Terms):

| Term | Default |
| --- | --- |
| `income.paid` | `%1 %2 received.` |
| `income.held` | `Pay waiting: %4 %2.` |
| `income.collected` | `Collected %1 %2.` |

`%1` is the amount, `%2` the currency symbol, `%3` the schedule name and `%4`
the pay still held. A blank message is silent.

## Saves

`GameConfig::$income` holds each schedule's standing: progress towards the
next period, held pay and the pending adjustment. It is an additive save field:
saves written before it existed load with nothing counted, held or owed, and no
schema or content migration is needed. Malformed values never grant pay.
Standing for a schedule the project no longer declares is kept as saved, so
removing and restoring a schedule during development loses nothing.

## Ownership

`Income\IncomeCatalog` reads and validates the project file at boot (an invalid
file stops the game with the record and field named). `Income\IncomeService`
belongs to the campaign (`GameScene::$income`) and is rebuilt from the save on
every load. The Game loop passes each frame to `GameScene::advanceActivePlay()`
with the scene that ran it; field steps and `GameScene::concludeBattle()` feed
the other cadences. `InputManager::getSecondsSinceInput()` supplies input
recency, counting held controls as input.
