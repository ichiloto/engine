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
- Experience rewards use the same progression awarder as battles. Every
  crossed level grants the role's automatic skills exactly once, including
  reserve members. One acknowledged, paginated reward summary reports the
  rewards, new levels and learned abilities or magic; per-level toasts are
  removed. Quest completion toasts contain only the quest name. Progress
  notices contain the name and an optional count, not the full objective
  description, which stays in the journal. No second progression model is used.
- `optional` marks a side quest, which is offered rather than granted. See
  [Side quests the player can turn down](#side-quests-the-player-can-turn-down).
- `startWaymarks` lead the player to where the quest starts, before it is in
  the journal. See [Leading to a quest's start](#leading-to-a-quests-start).

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
partly satisfied starts with honest progress. They are re-evaluated when a
save loads too, and an active quest whose objectives are then all met
completes, quietly, with its rewards: a save can hold one when the content
now asks less of the quest than when it was saved (an objective removed or
lowered). Progress is kept per objective by position, so a content change
that inserts or reorders objectives needs a save migration.

## Waymarks: where an objective sends the player

An objective may list `waymarks`, the places the player should head for.
They are guidance only: they never advance or complete anything.

```php
['type' => 'flag', 'target' => 'garden_crosswind_evidence_complete',
  'description' => 'Take the five readings.',
  'waymarks' => [
    ['map' => 'overworld/garden-of-roads', 'event' => 'H',
      'conditions' => [['type' => 'event', 'name' => 'garden_crosswind_tracks_recorded', 'negate' => true]]],
    ['map' => 'overworld/garden-of-roads', 'npc' => 'jori-dalen'],
    ['map' => 'garden-of-roads/crosswind-relay', 'object' => 'pressure-trace-1'],
  ]],
```

Each waymark names a `map` and at most one stable subject on it: an `event`
marker letter, an `npc` id, a world `object` id or a minimap `landmark` id.
Moving that subject in the Editor moves the waymark with it, so waymarks
never store coordinates. A waymark without a subject is the map as a whole:
it marks the way there and nothing once the player arrives.

A waymark shows while its objective is incomplete, its quest is active and
its own `conditions` hold (the same condition shapes as event triggers). A
waymark on an event or an NPC also follows that subject as its map authors
it: an event shows while its conditions hold, its cue's conditions hold
(when it has a cue) and, if one-shot, it is not yet complete; an NPC shows
while its presence conditions hold. So a waymark on an event appears with
that event's own `!`, and its phase is never restated on the quest.
Together these cover the common shapes:

- **Phases.** A strictly ordered chain gives each step a condition on the
  previous step's flag or a step variable, so only the current step shows.
- **Free order.** Several waymarks without ordering conditions show at once,
  each with a condition that hides it once its own part is done.
- **Optional extras** are simply not listed; their own event cues remain.

Every active quest contributes, side quests included. Waymarks are derived
from the quest log and world state each time they are drawn, so they are
right straight after a load or a long break and add nothing to saves.

### Leading to a quest's start

Objectives only lead once their quest is in the journal. To lead the player
to where a quest starts (its giver, or the event that hands it over), give the
quest `startWaymarks`, in the same shape as an objective's waymarks:

```php
[
  'id' => 'breakfast-duty',
  // ...
  'startWaymarks' => [['map' => 'happyville/home', 'npc' => 'mother']],
],
```

A start waymark shows while its quest can start (not yet accepted or
finished, every prerequisite holding) and its own `conditions` hold. Once the
quest is accepted its objectives lead instead. Its text is spoiler-safe, since
the quest is not in the journal yet: the `quest.story_waymark` message
("Continue the story") for a main quest, `quest.side_quest_waymark` ("Side
Quest") for a side quest. The story's opening is the first main quest's start
waymark: a new game, before any quest is given, leads to its giver.

### When nothing authored leads: the story wayfinder

The player must never be left without a waymark on the story path. Authored
waymarks are the guidance; `Quests\Story\StoryWayfinder` only fills a gap.
While a main quest shows a waymark (an objective of an active one, or a start
waymark of one that can start) it adds nothing. When none does, it leads to
what takes the next step:

- With a main quest active, each unfinished objective: what sets its `flag`,
  the map of a `reach_map`, the NPC of a `talk_to`, or what starts a battle
  with the enemy of a `defeat`. A `collect` objective is not resolved.
- With none active, each main quest one step away: what starts it once its
  prerequisites hold, or what sets the story flags it still needs. A quest
  still waiting on another quest, or on anything but story flags, is further
  on.

Only what the player can set off now counts: an event or the NPC page talking
would run, as its map authors it, never a finished one-shot. When nothing
resolves it shows nothing rather than guess. Every fill, and every gap it
cannot fill, is written once per session to `logs/warning.log`, so the gap is
closed with an authored waymark rather than relied on. A filled waymark's
text is the `quest.story_waymark` message ("Continue the story") when it leads
to a quest's start, and the objective's text otherwise.

What leads where comes from `Quests\Story\StoryIndex`: every map's events,
NPC pages and random encounters, with what each can do (story flags set and
quests started) through its commands, `sets`, event scripts and cinematics,
and through battles: a `start_battle` command, or a map's encounters, can do
whatever that troop's event pages do. The Editor's story guidance check reads
the same index (`Quests\Story\StoryGuidance`). It warns where a main quest's
flag prerequisite, or a `whenBlocked` door's flag, has nothing that sets it,
or no objective of a main quest leading to it: one tracking the flag, or one
whose waymark points at what sets it. It also warns where nothing starts a
main quest, or nothing leads to what does: an objective of a main quest active
before it, or the quest's own start waymark. A start waymark shows only until
its quest starts, so it leads to a step through what starts its quest: it
counts when it points at something that both starts its quest and takes the
step (a gate's flag set where the quest is handed over, say). When everything
that starts its quest waits for the step (its giver speaks only once a flag
is set, by its page's or its presence conditions), the quest cannot start
before the step, so its start waymarks lead to whatever takes the step. That
is how the way to a quest's start passes through the step that opens it:
start waymarks with conditions on the step's flag, one leading to the step
while it is not taken and one to the giver once it is. Guidance only counts
when it can show before the step: its quest cannot need the step, an
objective cannot belong to a quest that starts only where the step is taken
or only after it, and a waymark cannot wait for the step.

### On the field, the local map and the world map

`GameScene::getFieldWaymarks()` resolves the current waymarks for the active
map. A subject here is marked where it is now (moving NPCs are followed). A
waymark on another map marks the first exit of the cheapest usable route
toward it. Routes come from `Field\MapRouteGraph`: transfer events, legacy
transfers, and reusable script events whose script transfers the player (a
repeatable transport). One-shot scripts and cinematics are story
consequences, so they are not routes. A route counts while its event's
conditions hold, and a route through a secret landmark counts only once that
landmark is revealed. Plain passages win over scripted transports of equal
length. An exit that is itself a target is marked once, as the target.

On the field, an on-screen waymark draws the game's waymark symbol, above a
character or at an event; an event's own cue stands in for it while visible.
An off-screen waymark hugs the view edge with an arrow pointing toward it,
kept off the player's cell. Guidance hides while a cinematic owns the field,
and the UI layer (dialogue, menus) always draws over it. The local map marks
objectives and routes with legend entries **Objective** and **Way to
objective**. The world map marks objectives that lie on it, and the places it
enters through for objectives inside them, only while that place is visible
to the player, so it never reveals an undiscovered location.

The style is the game's: `waymarks` in `Data/Presentation/field.php`.

```php
'waymarks' => ['color' => 'bright-yellow', 'symbol' => '!', 'inset' => 0],
```

`color` is a terminal colour and selects the graphical art too: the cue
binding of that colour supplies the upright mark and its eight pointer
images. An edge marker shows the upright mark, on the game's cue plate, with
only the pointer turned toward the target (see `cueStyle` in
[effect-animation.md](effect-animation.md)). `inset` keeps edge markers that
many cells in from the edge (0..8). These are the Engine defaults when
`waymarks` is omitted. Without a usable binding (effect image and all eight
pointers the renderer can draw), the terminal glyphs remain the waymarks in
the graphical field as well. Map
markers use the menu theme's `objective` colour and the optional icon roles
`map.objective` and `map.objective.route`.

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
locked door. A non-empty `whenBlocked` rejects the step before the player's
position or field state advances, and shows the message once per approach.
The player can always move out if an older save already placed them inside the
area, so introducing a new gate cannot trap an existing save.

Two limits worth knowing:

- Gating is per-trigger, not per-tile. A locked *door* works; a wall that
  crumbles later does not, because map tiles are static per map. Transfer to
  a different version of the map for that.
- Conditions are re-evaluated on movement, so a door unlocking while the
  player stands in it takes effect when they step out and back.
