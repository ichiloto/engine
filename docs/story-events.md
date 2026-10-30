# Story events and resumable command sessions

Ichiloto story events are ordered command lists executed by
`Events\Interpreter\EventInterpreter`. They drive map-based JRPG scenes; the
terminal is the rendering surface, not a replacement for field maps.

Scripts live in `assets/Events/<script-id>.php` and are started from a map
`ScriptEventTrigger`, an NPC conversation, or another engine call site.
The Engine and projects can add commands; see
[Registered commands](#registered-commands). The interpreter owns one
`EventExecutionSession` at a time. Immediate commands run in order, while
dialogue, choices, waits, movement routes, transfers, and battles yield or
suspend the session and continue it through the regular game loop.

These scripts are story events or reusable Common Events. First-class
Cinematics add cast, camera, presentation, safe-skip, and final-state metadata
while hosting this same interpreter and session model. Skits remain optional
party conversations, and Summons remain compiled frame timelines. See
[Cinematic cutscenes](cinematics.md) for the asset taxonomy and cinematic
runtime contract.

## Map trigger

```php
'E' => [
  'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
  'conditions' => [
    ['type' => 'switch', 'name' => 'scene_available'],
  ],
  'sets' => [
    ['type' => 'event', 'name' => 'scene_complete'],
  ],
  'whenBlocked' => 'That can wait.',
  'data' => [
    'scriptId' => 'technical-scene',
    'mode' => 'action', // action or auto
    'reusable' => false,
  ],
],
```

The filename stem is the stable script ID. A non-reusable trigger keeps its
existing `map-id:marker` one-shot identity. Its completion writes and one-shot
flag are applied only after the final command succeeds. An automatic trigger
or action trigger cannot re-enter while its session is active. Automatic
triggers are evaluated on initial field entry as well as after movement, so a
New Game or loaded save that starts inside the trigger area runs it without
requiring the player to step out and back in.

A scripted route can reach another automatic script or cinematic before its
current session finishes. The arriving trigger remains unentered while that
session owns the interpreter. At completion, the scene checks automatic triggers
at the current map, position, heading and conditions before returning control to
field input. Leaving the area or changing eligibility cancels that arrival;
there is no stored trigger to launch later. An active or failed trigger keeps
its normal completion and exit/reentry rules, so this handoff cannot duplicate
its rewards or repeatedly retry a failure. This behavior applies to arrivals
with and without a map transfer and requires no extra authored command.
Automatic trigger implementations expose read-only `sessionIsActive` alongside
`runsAutomatically()` so arrival handling can distinguish the current owner
from a waiting trigger. Script and cinematic triggers already own that state.

When the conditions fail, a non-empty `whenBlocked` makes entry into the event
area fail closed and presents that message without advancing field movement.
The area is exactly the cells the marker occupies, however they are placed
(see [Event markers](maps.md#event-markers)).
Omit it when the unavailable event should be absent rather than act as a gate.

### Optional player facing

Every event trigger may declare a root-level `facing` list alongside `class`,
`conditions` and `data`, for example `'facing' => ['North', 'West']`. Names are the
case-sensitive cardinal `MovementHeading` values: `North`, `East`, `South`, `West`.
Omission or `[]` allows every heading, preserving existing trigger behavior.
`null`, a scalar, an associative array, `None` and unknown names are invalid;
duplicate headings are normalized. This is interaction state, not a world condition.

With the default `engagement`, an opted-in event engages while the player is inside its exact marker area,
facing an allowed heading, its world conditions hold and it is not complete.
A blocked walking attempt still turns the player and re-evaluates these events;
explicit facing and sprite-facing changes do likewise. Turning away retires the
old event before a replacement enters, so prompts do not depend on authored event
order. A turn does not count as movement, an encounter step or a map-transfer
observer notification. Repeating the same facing does not retrigger entry.

Initial field entry and save restoration use the restored heading: opted-in
action events may present a matching prompt and automatic events run only when
the heading also matches. Unrestricted action/transfer events keep their existing
movement-driven entry. Installing a spawn does not run the old map's events;
trigger completion still belongs to the existing persisted map/marker state.
Temporary cinematic transform restoration remains silent. World-state changes
retire invalid active events without chaining newly available automatic events.

`isAvailable()` and `whenBlocked` remain world/spatial gates. Facing does not turn
an unavailable physical gate into a passable tile. Cues remain discovery guidance
and are independent of the player's current heading. The same contract applies to
sleep, script, chest, transfer, cinematic and other EventTrigger subclasses. The
Editor may offer a multi-select of these four values and persist the root list;
no class-specific facing field or presentation-driven collision is needed.

### Events on objects

Set root-level `'engagement' => 'facing'` to place the event marker on the object
itself. The player engages it facing an immediately adjacent marker cell, or
standing on one of its cells. Collision alone decides whether a cell can be
stood on: a solid object answers only from beside it, while an object lying on
walkable ground (a lamp on the floor, tracks in the dirt) also answers while the
player stands on it, whichever way the player faces. Solid object cells work:
interaction does not require walking onto them. A faced transfer or other entry
effect on a walkable cell happens on stepping onto that cell, as with `inside`;
map reachability treats such a transfer's cells as an exit. Exact marker cells define reach, including disconnected or
multi-cell objects; gaps, diagonals and cells farther away are excluded. Object
reach stops at the faced cell and does not extend across NPC talk counters.

```php
'R' => [
  'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
  'engagement' => 'facing',
  'data' => ['mode' => 'action', 'scriptId' => 'read-records'],
],
```

`engagement` accepts exactly `inside` and `facing`. Omission means `inside`,
preserving existing events. Null, other types and unknown values are rejected.
Direct PHP construction uses `Events\Enumerations\EventEngagement` as the final
optional constructor argument. An optional `facing` list further restricts the
player's allowed headings; without it every cardinal approach is supported.

Facing a different object replaces the prompt through the same event lifecycle
as movement. Turns against solid objects, explicit turns and restored headings
all re-evaluate object reach. Ordinary action events still wait for the semantic
interact action. Trigger classes keep their existing activation behavior: an
automatic script still starts automatically when engagement begins, and transfer
or audio triggers still perform their normal entry effect. Use an action-mode
script for an object-operated door or audio control that must wait for interaction.

This metadata applies to every trigger subclass. Cues stay on the authored object
cells and remain independent of current reach. World conditions, `whenBlocked`
entry gates, collision, terminal glyphs and map/marker completion identity remain
unchanged. Completion persists through the existing save format. Editor controls
should offer the two engagement choices and preserve the independent heading list.

## Transfer map trigger

Keep a direct map link as a `TransferPlayerTrigger`, including when crossing
that link records a story boundary. Its ordinary trigger-level `sets` apply
after the destination map commits and before destination automatic events,
interpreter continuation. Map transfer itself does not request an autosave:

```php
'D' => [
  'class' => 'Ichiloto\\Engine\\Events\\Triggers\\TransferPlayerTrigger',
  'sets' => [['type' => 'event', 'name' => 'outward_journey_started']],
  'data' => [
    'destinationMap' => 'countryside',
    'spawnPoint' => ['x' => 2, 'y' => 3],
    'spawnSprite' => ['South'],
    'reusable' => true,
  ],
],
```

Transfer markers remain movement-driven, not automatic on initial field entry.
Blocked movement, unavailable conditions and refused destination preparation
apply no completion writes or one-shot state. The destination remains explicit
in `data.destinationMap` for map-link authoring and reachability analysis.

Reusable is the default. Each successful pass applies its writes once; story
events are idempotent, while variable additions intentionally apply per pass.
With `data.reusable` false, completion persists under the original source
`map-id:marker`, so returning to that map does not reactivate the transfer.
This removes the previous silent dropping of transfer `sets` and the repeated
activation of transfers authored as one-shot. Ordinary reusable links retain
their existing behavior.

Programmatic initiators may pass `onCommitted` as the optional third `Closure`
argument to `GameScene::transferPlayer()`. Existing one- and two-argument calls
remain unchanged. A `GameScene` subclass overriding this method must accept
that optional argument and invoke it only after its own successful destination
commit, before continuation, automatic events or saving. Do not invoke it from
a refused or failed preparation path. The callback is scoped to that transfer;
it is not a persistent listener or serialized event continuation.

## Cinematic map trigger

A complete first-class Cinematic is referenced rather than copied into a
story-event script:

```php
'C' => [
  'class' => 'Ichiloto\\Engine\\Events\\Triggers\\CinematicEventTrigger',
  'conditions' => [
    ['type' => 'event', 'name' => 'presentation_available'],
  ],
  'sets' => [
    ['type' => 'event', 'name' => 'presentation_complete'],
  ],
  'whenBlocked' => 'The presentation is not ready.',
  'cue' => ['symbol' => '!', 'color' => 'bright-yellow'],
  'data' => [
    'cinematicId' => 'field-presentation',
    'mode' => 'action', // action or auto
    'reusable' => false,
  ],
],
```

The ID resolves through `CinematicLibrary`; no duplicate
`assets/Events/<id>.php` fallback is used. The trigger shares ordinary
conditions, blocked movement, cues, action behavior, completion writes, and
`map-id:marker` one-shot persistence. A reusable trigger remains available
after each successful completion. Normal and legally skipped cinematic
completion apply trigger state after `CinematicController` has completed its
own cleanup. Refused or controlled-failure launches retain field input and
action state and write neither cinematic completion nor trigger completion.

Programmatic code may use `GameScene::startCinematic()` at a field lifecycle
boundary. There is no `start_cinematic` command inside story-event command
trees because the interpreter already owns the parent session at that point.
See [Cinematic cutscenes](cinematics.md) for the asset, skip, completion-chain,
and cleanup contracts. Editor support for authoring this trigger is a separate
gate; the runtime and exported Engine schema do not imply that it has shipped.

## Execution lifecycle

### Text expressions

Event and cinematic `text` commands may supply an optional `emotion`:

```php
['type' => 'text', 'name' => 'Authored Speaker', 'emotion' => 'Concerned',
 'text' => 'A line with an authored expression.'],
```

The same field works on ordinary NPC/dialogue pages, including conditional
variants. Omission keeps `Neutral`; an explicit value must be a non-empty string.
Keys are case-sensitive and project-owned, not a fixed Engine emotion enum.
`name` remains the display speaker. Its existing `DialoguePresentationCatalog`
speaker alias selects a stable actor or artwork resource, and `emotion` selects
that identity's portrait role. No image path, filename inference, gameplay actor
mutation or alternate speaker identity is stored in a line. Unknown expressions
and missing art keep the catalog's existing Neutral/base-role fallback; text and
terminal presentation do not require any portrait.

The interpreter passes the existing `DialogueContext` to context-aware adapters
through `EventDialoguePresentationInterface::beginDialogue`. The default modal
uses that context for every page/typing snapshot. Legacy `EventPresentationInterface`
adapters still receive their original text/speaker call and continuation behavior;
they may opt into the additional capability without breaking terminal previews.
Each line gets a fresh context, so an omitted expression never inherits the
previous line's emotion. Choice, timed narration and title-card contracts are
unchanged.

The Engine exports this vocabulary as
`CinematicCommandSchema::export()['textPresentation']`. Editor/GUI emotion pickers
must use the selected speaker's catalog keys, offer omission, preserve authored
values through source-preserving edits/undo (including unknown current bindings),
and retain nested command/page data. Those controls and round-trip acceptance are
not delivered by this runtime slice. Existing artwork replacement remains owned
by the shared catalog and PNG checks, without frozen hashes or dimensions.

### Field conversation facing

A cinematic binds its conversation speakers to selected field subjects once in its
definition. This is separate from portrait artwork identity:

```php
'presentation' => ['initial' => 'field', 'conversation' => [
  'speakers' => [
    'hero' => ['kind' => 'player'],
    'guide' => ['kind' => 'staged_actor', 'id' => 'guide-look'],
  ],
]],
```

The `text` command's `speaker => 'hero'` selects a bound key independently of
its display `name`; two people may share a display label. A conversation may
instead explicitly bind exact display-name keys for existing lines. An omitted
`speaker` then selects that exact key. At each line, listeners
face the speaker. The speaker faces an authored addressee, the previous other
speaker, or the sole other participant. Group scenes can select a default per
speaker with `addressees => ['hero' => 'guide']`; a line may select another bound
speaker with `addressee => 'witness'`. An initial group line without an addressee
keeps the speaker's heading. Empty names and unbound off-field voices leave field
headings alone. Subject positions are resolved when the line starts.

`facing` or `faceToward` on a line overrides that speaker's automatic turn.
The conversation's `facing` map supplies persistent overrides by speaker, using
the same facing fields. Explicit staged-actor facing and NPC `directionFix` also
take precedence over automatic turns. Active movement retains its heading;
an explicit line turn must wait for that subject's route to finish. Resolve
speaker references through the current player, NPC or staged-actor picker and
select addressees from the bound speakers. Never infer subject ids from text,
filenames or artwork.

The same preparation runs for ordinary NPC/dialogue pages during the active
cinematic. Specialized dialogue `show()` methods retain their existing API.
Bound stage subjects turn their real occupant, so Terminal directional glyphs
and graphical character-sheet rows agree. Turning changes no position or
collision. Existing cinematic leases restore real transforms on failure; current
map identity and eligibility still govern cleanup. Duplicate real-subject
aliases and missing participants fail before any heading changes. Cleanup clears
the conversation context, and portrait-only Skits keep their own presentation.

`CinematicCommandSchema::export()['conversationFacing']` exposes these fields and
reference sources. Editor controls and project scene bindings remain separate
delivery steps.

### Session states

`EventExecutionStatus` distinguishes:

- `RUNNING`: the interpreter may execute the next command.
- `YIELDED`: a dialogue, choice, timer, or movement route is pending and is
  updated once per field tick.
- `SUSPENDED`: control is temporarily in map transfer or battle flow.
- `COMPLETED`: all frames and the trigger completion callback succeeded.
- `FAILED`: execution stopped with a controlled diagnostic.

`EventExecutionSession` retains the script ID, root execution lane, pending
operations, suspended transfer or battle state, and originating completion
target. Every lane owns its own stack of `EventExecutionFrame` objects,
command cursor, and pending operation. Choice and branch arms push frames;
`sequence` pushes an ordered block; `parallel` advances child lanes
cooperatively in authored order. They do not recursively block the terminal
loop or create operating-system threads. Only one event or cinematic session
can own a `GameScene`.

Existing commands retain their behavior. Unknown command types fail the active
session immediately at runtime, even when editor validation was skipped. The
diagnostic identifies the script, command index, nested frame, and available
map/marker/trigger/source context. No later command or parent frame runs, and
trigger completion writes, one-shot state, rewards, and deferred autosaves are
not applied. Failure cleanup releases field input and saving so a corrected
reusable or incomplete one-shot trigger can be tried again. Project validation
also reports unknown commands before playtesting from the same authoritative
`EventInterpreter::COMMAND_TYPES` vocabulary.

`recover_party` is the generic stable-checkpoint recovery command. It restores
HP, MP, and AP for every travelling member, clears battle-only states and
cures the persistent states authored as `curedByRest` (an inn stay cures the
same states). It does not change equipment, inventory, experience, party
order, persistent states authored with `curedByRest => false`, or story
state.

## Movement routes

Use one awaited `move_route` command for the player, one current-map NPC, or a
cinematic staged actor:

```php
[
  'type' => 'move_route',
  'subject' => 'npc',       // player, npc, or staged_actor
  'npcId' => 'field-guide', // required for npc
  'wait' => true,
  'secondsPerStep' => 0.15, // or speed: steps per second
  'steps' => [
    ['direction' => 'left', 'count' => 2, 'faceOnly' => false],
    ['direction' => 'down', 'count' => 1, 'faceOnly' => true],
  ],
],
```

Directions are `up`, `down`, `left`, and `right`. A step may set `seconds` to
override the route timing, `count` for repeats, and `faceOnly` to turn without
moving. Routes use the normal player/NPC collision and render paths. A blocked
step fails immediately with the map, subject, step, and attempted position;
it never waits forever or silently skips the step.

NPC definitions may add an `id` distinct from their display `name`:

```php
'npcs' => [[
  'id' => 'field-guide',
  'name' => 'Guide',
  'sprite' => 'G',
  'x' => 8,
  'y' => 4,
]],
```

IDs must be unique within a map. Existing NPCs without IDs still load, but a
scripted NPC route requires an explicit stable ID. Optional cardinal sprites
may be authored under `sprites` with `north`, `south`, `west`, and `east` keys;
otherwise the existing sprite is retained while facing changes.

When the player talks to an NPC, the NPC first turns to face the player, as in
RPG Maker, and then speaks. When the conversation ends it turns back to the
heading it had before: after its last dialogue page, or when its script (or a
dialogue variant's script) completes or fails. A wanderer then resumes its
wander schedule unchanged. Both turns use the same facing path as routes, so
the terminal glyph swaps to the authored directional sprite and the graphical
sheet shows the matching row.

Unlike RPG Maker, which restores the heading unconditionally, the turn-back
never undoes the conversation's own staging. It is skipped when anything else
set the NPC's heading or transform after the talk turn (a `move_route` turn or
step, even one toward the player, or cinematic staging that was restored),
when a cinematic still stages the NPC as the conversation ends, or when the
NPC has left the current map.

Set `'facing' => 'north'` (`north`, `east`, `south` or `west`, any case;
default `south`) for the heading an NPC stands with when its map loads, such
as a clerk facing the door a desk is approached from. The base `sprite` is the
south look; another heading shows its directional glyph and sheet row. Talking
turns it and turns it back to this heading like any other, and an unknown
value reads as south (`NpcManager::readFacing()`).

Set `'directionFix' => true` (RPG Maker's Direction Fix; a bool, default
`false`) to keep an NPC's heading when it is talked to; it neither turns nor
turns back. It governs the talk turn only: wander steps and `move_route` still
turn a direction-fixed NPC. A cinematic's staged NPC is never turned by
talking.

Concurrent routes are authored as separate lanes in a cinematic `parallel`
block. Pathfinding, diagonal movement, jumping, collision bypass, party
followers, and NPC patrol profiles are not supplied by this command.

## Immediate player placement

`move_player` places the player immediately on the current map; it is not a
walking route or map transfer. `GameScene::relocatePlayer()` owns the shared
handoff, also used by inn wake-up placement: cancel held walking, discard old
sprite interpolation and pending arrival, synchronize the field viewport, snap
an attached camera to the destination with normal map-edge clamping, and rebuild
the complete field through its existing compositor. Deliberately detached
cinematic cameras keep their framing and ownership.

Placement does not trigger walking arrivals, encounters, developer-requested autosaves or
event re-entry. Authored cinematic finalizers retain their existing subject
transform commit boundary. Cross-map transfers still load destination geometry
before positioning their camera; temporary cinematic rollback retains its
separate lease boundary.

## Transfers and battles

A transfer suspends the session before using the existing
`GameScene::transferPlayer()` path. Map, NPC, audio, and render configuration
complete normally, then the same in-memory session resumes on the destination
map. The originating trigger remains the completion target.

`start_battle` also suspends and returns through the existing battle result
path:

```php
[
  'type' => 'start_battle',
  'troop' => 'Training Pair',
  'resultVariable' => 'training_result', // optional
  'defeatPolicy' => 'game_over',         // default; or continue
  'escapePolicy' => 'forbidden',         // optional; allowed or forbidden
  'reservePolicy' => 'none',             // default; or replace_after_wipeout
  'firstStrike' => 'normal',             // optional; normal, party, or troop
],
```

When requested, `resultVariable` receives `victory`, `defeat`, or `escape`
using `BattleResult::outcome()`. Later `branch` conditions can compare that
variable through the existing `WorldConditionEvaluator`. Omitting the field
writes nothing.

`game_over` preserves the normal defeat flow. Only an explicitly authored
`continue` policy returns a defeat result to the field and resumes the script.
Victory rewards, quest tracking, achievements, bestiary recording, audio
restoration, party cleanup, and persistent-state handling stay on the existing
battle paths.

`firstStrike` pins the opening for a scripted encounter: `normal` (or explicit
`null`) disables advantage rolls, `party` forces a pre-emptive strike, and
`troop` forces an ambush. Omission uses the project's opening chances. Both
battle modes honor the same decision; the field does not make an additional
roll. Traditional battles skip the surprised side's first round; ATB starts
the favored side ready and the surprised side empty.

In `Data/system.php`, configure these mode-independent chances under
`battle.opening.preemptiveChancePercent` and `battle.opening.ambushChancePercent`
(defaults 8 and 6; their sum must not exceed 100). Existing
`battle.activeTime.surpriseAttackChancePercent` / `backAttackChancePercent`
remain fallbacks for older projects; the shared settings take precedence.

Normal ATB openings use a random gauge contribution (`openingVariance`, default
70) plus a speed bonus (`openingSpeedFactorPercent`, default 50). The bonus is
capped at 20 gauge points and the starting total at 90, preserving variation
at high levels. Thereafter gauges reset to zero and refill at the existing
speed-based rate. Ready battlers act in threshold-crossing order, with random
ties, rather than being reordered by gauge overflow or a delayed frame.

`escapePolicy` overrides the troop's optional policy for this launch. If both
are omitted, escape remains allowed for backward compatibility. A forbidden
battle omits the Escape command and rechecks the rule at resolution, so stale
or directly queued input cannot produce an escaped result. Malformed values
fail closed at validation and produce a controlled runtime event failure.

`reservePolicy` is a per-battle participation choice, shared by Traditional,
ATB, terminal and graphical play. The default `none` removes the former
automatic reserve fallback: the first three ordered party members remain
active, including KO members, and their complete wipeout ends the battle in
defeat even when reserves are healthy. Normal defeat leads to Game Over;
the separately authored `defeatPolicy => continue` remains a deliberate
story-event exception, not reserve replacement.

Only `replace_after_wipeout` brings the next living members forward, up to
three, after the outgoing wave's final damage/KO presentation has completed.
It does not replace individual KO members while an active member survives.
Each wave stays stable through redraws and revival; no rendering read can
promote a reserve or silently change the active lineup. Reordering the travelling
party applies when the next battle captures its roster, not during a redraw of
the current battle. Turn order, targeting, names/status and battlefield art
share that battle's roster. New ATB participants start at zero gauge; enemy
gauges are retained. Once no living replacement remains, defeat proceeds
normally. Party order and saves are unchanged and the option does not carry
into subsequent battles. Entry rules capture the actual starting roster.

Direct developers opt in through `SceneManager::loadBattleScene(...,
extraSettings: ['reservePolicy' => 'replace_after_wipeout'])` or
`BattleConfig`'s `settings`. `BattleSimulator::simulate` accepts the same
per-battle `settings` for balancing. Invalid policy values are rejected rather
than silently enabling replacement. This setting is not a project-wide default.

## Registered commands

Beside the built-in vocabulary (`CinematicCommandSchema::COMMAND_TYPES`), the
interpreter runs commands registered in `ScriptCommandRegistry`: the Engine's
own, and any a project declares. A registered command works wherever a script
does: map triggers, NPC conversations, Common Events and cinematics.

Field scripts can recruit a project actor through the same catalogue and
factory used by game start and save restoration:

```php
['type' => 'recruit_actor', 'actor' => 'actor.traveller'],
['type' => 'recruit_actor', 'actor' => 'actor.archer', 'levelPolicy' => 'partyAverage'],
['type' => 'recruit_actor', 'actor' => 'actor.archer', 'levelPolicy' => 'partyAverage',
  'spPolicy' => 'partyAverageEstimatedEarned'],
```

`Party::recruitActor()` appends a fully constructed character once by stable
actor identity. Repeating the command, including after save/load, keeps the
existing character's resources, growth, equipment and roster position. Different
actors may share a display name. The existing frontline capacity is unchanged;
an appended recruit beyond it is a reserve. Invalid references or factory errors
leave the roster unchanged. The command is field-only; changing a running battle's
roster is excluded. Its required Actor field uses the Editor's resource picker.
The optional Starting Level picker selects `authored` (the default) or
`partyAverage`. The latter uses the permanent roster, including reserves,
before adding the recruit and rounds the average level down. It requires a
nonempty party. The actor factory uses the recruit's own experience curve,
maximum level, stat growth and automatic role skills. Repeating recruitment
keeps an owned actor's progress. Story flags, dialogue and art remain project data.

The optional Starting SP picker (`spPolicy`, `RecruitmentPointPolicy`) selects
`authored`, `partyAverageEarned` or `partyAverageEstimatedEarned`. The first
retains the existing starting balance. The others grant the recruit the permanent
roster's average total earned SP, rounded down before recruitment. Current
spendable balances of existing members remain unchanged. Recorded earned totals
combine unspent points and actual spending, so changing node prices does not
recalculate history.

`partyAverageEarned` requires established histories. The explicit estimated mode
initializes an unknown legacy history from the current prices of its learned
historical nodes, once, and retains that baseline on save/load. Spending after
the journal begins is recorded separately while the older baseline remains
unknown; those purchases keep their actual paid amounts, and later free grants
are excluded from the historical estimate. A missing map or unknown or
unpriced historical node produces a diagnostic before recruiting or changing
any existing member. Projects resolve such historical content through their
content migration. The estimate is an authored policy choice; it is never
silently applied by the default recruitment path. Completed/repeated recruitment
preserves acquired progress and grants no second award.

Actor data can declare first-creation equipment by semantic slot and stable item
identity, for example `initialEquipment: ['weapon' => 'equipment.short-bow']`.
The factory equips independent catalogue instances after applying the actor's
class and starting level. Slot/type mismatches, unresolved references and class
restrictions reject construction before recruitment changes the roster. The
existing `equipment` field continues to describe hydrated equipment slots.
Starting loadouts apply to new characters; saved equipment, including empty
slots, is restored without re-granting or resetting initial gear. Changes to a
starting loadout therefore affect future recruits and new games.

The Engine registers two services, so an NPC can own the interaction a
trigger tile used to stand in for:

```php
// A shopkeeper's script: the script continues once the player leaves.
['type' => 'text', 'name' => 'Shopkeeper', 'text' => 'Have a look.'],
[
  'type' => 'shop',
  'items' => [['item' => 'Potion'], ['item' => 'Ether', 'price' => 120]],
  'buyRate' => 1.0,  // optional
  'sellRate' => 0.5, // optional
],

// An innkeeper's script.
[
  'type' => 'inn',
  'confirmDialogue' => ['name' => 'Innkeeper', 'text' => 'A bed for 30 G?'],
  'cost' => 30,                         // optional; 0
  'spawnPoint' => ['x' => 4, 'y' => 2], // optional; the party wakes where they stand
  'spawnSprite' => ['South'],           // optional; the current sprite
  'bgm' => 'Inn Lullaby',               // optional; the project's sleep theme
  'resultVariable' => 'inn_result',     // optional: stayed, declined or unaffordable
],
```

These read the same data, through the same `ShopOffer` and `InnOffer`, as
`ShopEventTrigger` and `SleepEventTrigger`, and run the same shop state and
`InnStay` those triggers run. The stay's question and rest are shown
synchronously, as the sleep trigger has always shown them. A stay's
`spawnPoint` is an arrival on the map running the script, so
[reachability](maps.md#reachability) checks it like any other.

A project declares its own commands in `assets/Data/script-commands.php`, a
file returning a list. Each declaration names its type, its handler class and
the fields authors give it:

```php
use MyGame\Commands\HireCarriage;

return [
  [
    'type' => 'hire_carriage',
    'class' => HireCarriage::class,
    'label' => 'Hire Carriage',
    'description' => 'Takes the party along a road for a fare.',
    'fields' => [
      ['key' => 'destination', 'label' => 'Destination', 'kind' => 'reference', 'reference' => 'map', 'required' => true],
      ['key' => 'arrival', 'label' => 'Arrival', 'kind' => 'position', 'required' => true],
      ['key' => 'fare', 'label' => 'Fare', 'kind' => 'integer', 'minimum' => 0],
    ],
  ],
];
```

Types are lower-case words joined by underscores, and may not reuse a
built-in or already registered type. Field kinds are `text`, `integer`,
`number`, `boolean`, `option` (with `options`), `reference` (with a
`reference` of `item`, `music`, `sound`, `map`, `troop`, `quest` or `actor`),
`position` (`x` and `y`) and `list` (with the `fields` of each entry; a list
cannot hold another list). A key may be a dotted path into nested data, such
as `confirmDialogue.text`. Because declarations are plain data, authoring
tools read and validate them without loading project code.

The handler implements `ScriptCommandHandlerInterface`, takes no constructor
arguments, and returns `ScriptCommandOutcome::complete()` to continue in the
same frame or `ScriptCommandOutcome::waitFor($operation)` to wait, one field
tick at a time, on an `EventPendingOperationInterface`:

```php
final class HireCarriage implements ScriptCommandHandlerInterface
{
  public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
  {
    // $context->scene is the GameScene: party, player, world state.
    return ScriptCommandOutcome::complete();
  }
}
```

The game reads the declarations at startup and refuses to start when one is
malformed or names a handler that does not exist or implement the contract.
Before a handler runs, the interpreter checks the command against its fields
and fails the script closed with every problem and the command path; script
validation applies the same checks. Authored cinematic skips reject
registered commands conservatively, as they do Common Events, since the
Engine cannot prove a project command safe to skip.

## Party capacity and temporary groups

`assets/Data/system.php` owns `battle.partySize`, an integer from 1 to 8.
Projects without this field retain the existing default of three active
battlers. The saved Party owns its current capacity; changing it never removes
actors, equipment, growth, inventory or summon assignments.

Field scripts can author:

```php
['type' => 'set_party_size', 'size' => 8],
['type' => 'select_party', 'members' => [['actor' => 'actor.scout']]],
// Transfers and battles use the selected original actor, including field menus.
['type' => 'regroup_party'],
```

`select_party` accepts 1–8 distinct permanent actor IDs in participation order.
The Editor exposes Actor pickers for its members. Selection replaces the current
group atomically; it does not nest groups. `regroup_party` is idempotent and
restores access to the permanent roster in its existing order. It keeps the
current battle capacity. A solo story sequence normally needs only selection,
so regrouping retains the project's normal capacity without a paired size edit.

Present members and frontline capacity are separate: the first `partySize`
present members fight; other present members are reserves. Separated members
cannot lead, be field targets or enter through reserve promotion. They retain
the existing travelling-roster experience policy. A split and its current size
survive normal Save, Quick Save and Continue. Existing active-session save
restrictions still apply; party commands do not serialize a suspended scene.

Runtime APIs are `Party::setBattleSize()`, `selectParticipants()` and
`regroupParticipants()`. `participants` reads the current field group;
`members` retains permanent ownership. Save compatibility resolves selected
actor IDs using the same project aliases as saved actors. Invalid, missing or
ambiguous selections fail instead of silently changing participants.

Terminal formations support all eight combatants. Battle name/vital panels
share the selected actor's four-row page, keeping members five through eight
accessible on both presentations. Graphical themes may author up to eight
formation slots or use the existing automatic arrangement. An explicit formation
with too few slots is diagnosed; it is not silently substituted. Battle Test
supports eight members and preserves its configured frontline size.

## Save safety

Active execution sessions are deliberately in-memory only. They do not enter
the WP1 save envelope.

- Numbered/manual saves and quicksaves are rejected by `SaveManager` while a
  session is active, with `ActiveEventSaveException` feedback.
- A developer-requested autosave is deferred and is written only after the
  whole event and its completion writes succeed.
- A failed event discards the deferred autosave.
- If the process exits mid-event, loading returns to the last stable save,
  never to a partly serialized command stack.

This restriction is centralized in the existing save writer, so all current
save entry points share it. See [Persistence](persistence.md) and
[Versioned save compatibility](save-compatibility.md).

## Current limits

There is no active-session save serialization, pathfinding, diagonal or jump
route command, party-follower staging, or NPC patrol-route command. Authored
safe skip, camera operations, transitions, field animations, and parallel
lanes are available through the first-class Cinematic contract described in
[Cinematic cutscenes](cinematics.md). The Engine exposes validation-facing
schemas, but the corresponding first-class TUI authoring surface is a separate
Editor gate.
