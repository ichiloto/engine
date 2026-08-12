# Story events and resumable cutscenes

Ichiloto story events are ordered command lists executed by
`Events\Interpreter\EventInterpreter`. They drive map-based JRPG scenes; the
terminal is the rendering surface, not a replacement for field maps.

Scripts live in `assets/Events/<script-id>.php` and are started from a map
`ScriptEventTrigger`, an NPC conversation, or another engine call site. The
interpreter owns one `EventExecutionSession` at a time. Immediate commands run
in order, while dialogue, choices, waits, movement routes, transfers, and
battles yield or suspend the session and continue it through the regular game
loop.

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

## Execution lifecycle

`EventExecutionStatus` distinguishes:

- `RUNNING`: the interpreter may execute the next command.
- `YIELDED`: a dialogue, choice, timer, or movement route is pending and is
  updated once per field tick.
- `SUSPENDED`: control is temporarily in map transfer or battle flow.
- `COMPLETED`: all frames and the trigger completion callback succeeded.
- `FAILED`: execution stopped with a controlled diagnostic.

`EventExecutionSession` retains the script ID, a stack of
`EventExecutionFrame` objects, the pending command and its plain-data state,
and the originating completion target. Choice and branch arms push frames;
they do not recursively block the terminal loop. Only one session can be
active on a `GameScene`.

Existing commands retain their behavior. Unknown command types fail the active
session immediately at runtime, even when editor validation was skipped. The
diagnostic identifies the script, command index, nested frame, and available
map/marker/trigger/source context. No later command or parent frame runs, and
trigger completion writes, one-shot state, rewards, and deferred autosaves are
not applied. Failure cleanup releases field input and saving so a corrected
reusable or incomplete one-shot trigger can be tried again. Project validation
also reports unknown commands before playtesting from the same authoritative
`EventInterpreter::COMMAND_TYPES` vocabulary.

## Movement routes

Use one awaited `move_route` command for either the player or one current-map
NPC:

```php
[
  'type' => 'move_route',
  'subject' => 'npc',       // player or npc
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

Parallel routes, pathfinding, diagonal movement, jumping, collision bypass,
party followers, and NPC patrol profiles are not supported by this extension.

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

There is no event-session save serialization, cutscene skipping or skip-state
restoration, camera/focus command, screen-fade command, field-animation
command, parallel route execution, or NPC patrol-route command. Choice and
branch blocks execute resumably, but the TUI still preserves rather than
structurally edits those nested trees.
