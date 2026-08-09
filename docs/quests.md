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
- `optional` marks a side quest, which is offered rather than granted. See
  [Side quests the player can turn down](#side-quests-the-player-can-turn-down).

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

Accepting is idempotent: re-running the dialogue never re-grants a completed
or active quest.

### Side quests the player can turn down

Story quests are handed over the moment their trigger fires. A quest marked
`'optional' => true` is *offered* instead: the player sees a confirmation
carrying the description and rewards, and it only enters the journal if they
accept.

```php
[
  'id' => 'lost-cat',
  'name' => 'Lost Cat',
  'description' => 'Whiskers has wandered off again.',
  'optional' => true,
  'objectives' => [ /* ... */ ],
],
```

Declining changes nothing except one story event, `quest_declined:<id>`, so:

- the giver can offer the quest again on the next conversation, and
- dialogue can acknowledge the refusal with a
  `['type' => 'event', 'name' => 'quest_declined:lost-cat']` condition.

Optional quests are labelled **Side Quest** in the journal. To hand one over
without asking (the character has already agreed to it in dialogue, say), add
`'confirm' => false` to the grant:

```php
'sets' => [
  ['type' => 'quest', 'name' => 'lost-cat', 'confirm' => false],
],
```

The same flag works on the `accept_quest` event-script command.

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

## Dialogue that acknowledges progress

A speaker's `dialogue` may be a plain list of pages (always the same), or an
ordered list of **variants** where the first whose conditions hold is spoken:

```php
'dialogue' => [
  [
    'conditions' => [['type' => 'quest', 'name' => 'breakfast-duty', 'status' => 'completed']],
    'lines' => [['name' => 'Mom', 'text' => 'You found one! I knew I could count on you.']],
  ],
  [
    'conditions' => [['type' => 'quest', 'name' => 'breakfast-duty', 'status' => 'active']],
    'lines' => [['name' => 'Mom', 'text' => 'Still no S-Mana wafer? The shop is in the town centre.']],
  ],
  [
    'lines' => [['name' => 'Mom', 'text' => 'Good morning, dear.']],   // fallback
  ],
],
```

Order matters: put the most advanced state first and leave an unconditional
variant last as the fallback. Conditions use the same vocabulary as event
triggers (switch, event, variable, item, key_item, quest), so dialogue can
react to anything the world remembers.

A variant may also carry:

- `sets` — world-state writes applied when that line is spoken, which is how
  "the first time you report back" is recorded.
- `script` — event-script commands run instead of (or alongside) its lines,
  for handing over rewards or starting a cutscene.

This works for both map dialogue triggers and NPCs, and existing flat page
lists keep working untouched — they are simply a single unconditional variant.

## Gating areas behind progress

Any event trigger accepts `conditions`, and `TransferPlayerTrigger` is an
event trigger, so a door is gated by adding them. Pair it with `whenBlocked`
so the door explains itself:

```php
'B' => [
  'class' => 'Ichiloto\\Engine\\Events\\Triggers\\TransferPlayerTrigger',
  'conditions' => [
    ['type' => 'quest', 'name' => 'breakfast-duty', 'status' => 'active'],
  ],
  'whenBlocked' => 'The shop is still shuttered. Perhaps someone at home needs something first.',
  'data' => [ /* destinationMap, spawnPoint, … */ ],
],
```

Without `whenBlocked` a gated trigger is simply absent: the player walks into
the doorway and nothing happens at all, which reads as a bug rather than a
locked door. The message is shown once each time they step into the area.

Two limits worth knowing:

- Gating is per-trigger, not per-tile. A locked *door* works; a wall that
  crumbles later does not, because map tiles are static per map. Transfer to
  a different version of the map for that.
- Conditions are re-evaluated on movement, so a door unlocking while the
  player stands in it takes effect when they step out and back.
