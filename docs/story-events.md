# Story events and resumable command sessions

Ichiloto story events are ordered command lists executed by
`Events\Interpreter\EventInterpreter`. They drive map-based JRPG scenes; the
terminal is the rendering surface, not a replacement for field maps.

Scripts live in `assets/Events/<script-id>.php` and are started from a map
`ScriptEventTrigger`, an NPC conversation, or another engine call site. The
interpreter owns one `EventExecutionSession` at a time. Immediate commands run
in order, while dialogue, choices, waits, movement routes, transfers, and
battles yield or suspend the session and continue it through the regular game
loop.

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

When the conditions fail, a non-empty `whenBlocked` makes entry into the event
area fail closed and presents that message without advancing field movement.
Omit it when the unavailable event should be absent rather than act as a gate.

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
HP, MP, and AP for every travelling member and clears battle-only states. It
does not change equipment, inventory, experience, party order, persistent
states, or story state.

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

Concurrent routes are authored as separate lanes in a cinematic `parallel`
block. Pathfinding, diagonal movement, jumping, collision bypass, party
followers, and NPC patrol profiles are not supplied by this command.

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

`escapePolicy` overrides the troop's optional policy for this launch. If both
are omitted, escape remains allowed for backward compatibility. A forbidden
battle omits the Escape command and rechecks the rule at resolution, so stale
or directly queued input cannot produce an escaped result. Malformed values
fail closed at validation and produce a controlled runtime event failure.

## Save safety

Active execution sessions are deliberately in-memory only. They do not enter
the WP1 save envelope.

- Numbered/manual saves and quicksaves are rejected by `SaveManager` while a
  session is active, with `ActiveEventSaveException` feedback.
- A transfer-requested autosave is deferred and is written only after the
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
