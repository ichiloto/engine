# Quests

Quests are authored in the project's `assets/Data/quests.php` and driven
entirely by the engine: game systems report moments (an NPC talked to, an
enemy defeated, a map entered, a flag set, the inventory changed), matching
objectives advance, progress is announced through the notification system,
and rewards are granted the moment a quest completes. Progress lives in the
party's quest log, which rides the save file with the rest of the
[persistence spine](persistence.md).

## Defining quests

```php
<?php
// assets/Data/quests.php
return [
  [
    'id' => 'breakfast-duty',
    'name' => 'Breakfast Duty',
    'description' => 'Stock the family medicine chest before your journey.',
    'giver' => 'Mom',
    'objectives' => [
      ['type' => 'reach_map', 'target' => 'happyville/town-center'],
      ['type' => 'collect', 'target' => 'S-Mana', 'quantity' => 1],
    ],
    'rewards' => [
      'gold' => 200,
      'experience' => 50,
      'items' => ['S-Potion'],
    ],
  ],
];
```

- `id` is the quest's stable identity (used in the log, prerequisites, and
  trigger `sets`). Required, as is at least one objective.
- `description` and `giver` feed the journal; both optional.
- `rewards` supports `gold`, `experience` (granted to every member), and
  `items` (names resolved through the item store). All optional.

## Objectives

| Type | Advances when | Notes |
|---|---|---|
| `talk_to` | a dialogue finishes with a matching NPC | matched against the trigger's `npcId`, falling back to the first speaker's name |
| `collect` | the party holds the item | mirrors the inventory — using or selling the item lowers progress again |
| `defeat` | a matching enemy's troop is defeated | counts each enemy by name |
| `reach_map` | the map loads | target is the map's asset path |
| `flag` | a switch or story event with that name is set | |

Each objective takes `target`, an optional `quantity` (default 1), and an
optional `description` for the journal (derived from the type when omitted:
"Defeat Sewer Rat x2").

Objectives that mirror world state (`collect`, `reach_map`, `flag`) are
re-evaluated when the quest is accepted, so a quest the player has already
partly satisfied starts with honest progress.

## Giving quests

Any event trigger can grant a quest through its completion `sets`:

```php
'C' => [
  'class' => 'Ichiloto\\Engine\\Events\\Triggers\\DialogueEventTrigger',
  'sets' => [
    ['type' => 'quest', 'name' => 'breakfast-duty'],
  ],
  'data' => ['dialogue' => [ /* ... */ ]],
],
```

Accepting is idempotent — re-running the dialogue never re-grants a
completed or active quest.

### Prerequisites

A quest declares `prerequisites` with the same condition shapes triggers
use (`switch`, `event`, `variable`, `item`, each accepting
`'negate' => true`), plus:

```php
'prerequisites' => [
  ['type' => 'quest', 'name' => 'breakfast-duty', 'status' => 'completed'],
],
```

A grant is silently refused while any prerequisite fails, so a chain of
quests can hang off one repeatable dialogue.

Triggers can also gate on quest state from their `conditions`:
`['type' => 'quest', 'name' => 'breakfast-duty', 'status' => 'active']`.

## The journal

When a project defines quests, a **Quests** entry appears in the main menu.
The journal lists active and completed quests on separate tabs (tab
switches); opening an entry shows the giver, description, each objective's
progress, and the rewards.

## Completion

When every objective is satisfied the quest completes on the spot: rewards
are granted, a `quest_completed:<id>` story event is recorded (usable in
any trigger or prerequisite condition), and a notification announces it.
The quest moves to the journal's Completed tab and can never re-activate.
