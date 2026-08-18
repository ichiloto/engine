# Cinematic cutscenes

Ichiloto cinematics are first-class field assets for authored JRPG scenes.
They use the same `Events\Interpreter\EventInterpreter` and
`EventExecutionSession` as map-triggered story events; a cinematic is not a
second scripting runtime. `GameScene` supplies the lifecycle host, temporary
cast, camera control, and presentation layer while the interpreter retains
command ordering, transfer and battle continuation, failure handling, and
the active-session save guard.

## Which authored asset to use

| Asset | Purpose | Execution model |
| --- | --- | --- |
| Story event | A map or NPC interaction, including resumable event logic | Event interpreter command tree |
| Common Event | A reusable command module called by story events or cinematics | Event interpreter command tree |
| Cinematic | A complete field scene with explicit cast, camera, presentation, skip, and cleanup policy | Event interpreter command tree hosted by `CinematicController` |
| Skit | An optional party conversation surfaced by `SkitManager` | Skit beats, not a field-staging asset |
| Summon | A frame-driven battle or preview timeline compiled by the summon compiler | `SummonPlaybackSession` / `SummonCutscenePlayer` |

Use a Common Event for reusable logic, not as a substitute for a Cinematic's
asset metadata and final-state contract. Use a Summon for frame-authored
summon presentation, not for general field story scenes.

## Asset layout

Each stable cinematic ID owns one folder and two PHP array files:

```text
assets/Cutscenes/Cinematics/<id>/<id>.data.php
assets/Cutscenes/Cinematics/<id>/<id>.script.php
```

`CinematicLibrary` discovers and hydrates this layout without booting a game
scene. The folder name and the `id` in the data file must match. IDs use
lowercase letters, numbers, `.`, `_`, and `-`; the display `name` may change
without changing identity.

```php
// dawn-crossing.data.php
return [
  'id' => 'dawn-crossing',
  'name' => 'The Dawn Crossing',
  'description' => 'Three signal craft cross an open field.',
  'version' => 1,
  'authoring' => ['author' => 'Studio'],
  'startMap' => 'cinematic/night-field',
  'presentation' => ['initial' => 'hidden'],
  'cast' => [
    [
      'kind' => 'staged_actor',
      'id' => 'lead-craft',
      'asset' => 'Sprites/Craft/lead.php',
      'x' => 4,
      'y' => 6,
      'facing' => 'East',
      'visible' => true,
      'collision' => false,
    ],
  ],
  'skip' => ['policy' => 'authored'],
  'checkpoints' => ['formation-complete'],
  'finalizer' => [
    ['type' => 'transfer', 'map' => 'cinematic/dawn-field', 'x' => 8, 'y' => 5],
    ['type' => 'camera', 'operation' => 'attach'],
    ['type' => 'remove_actor', 'actorId' => 'lead-craft'],
    ['type' => 'clear_presentation'],
    ['type' => 'set_switch', 'name' => 'dawn_crossing_complete', 'value' => true],
  ],
];
```

Unknown metadata is retained in `CinematicDefinition::$extra`. `startMap` and
`presentation` are authoring metadata; the command tree remains responsible
for every runtime transition and final-state write.

## Launching a cinematic

Code that already owns an appropriate field boundary may launch a hydrated
definition or stable ID:

```php
$scene->startCinematic($definition);
$scene->startCinematic('dawn-crossing');
```

Map data launches the same asset through an Engine-owned trigger:

```php
'C' => [
  'class' => 'Ichiloto\\Engine\\Events\\Triggers\\CinematicEventTrigger',
  'conditions' => [
    ['type' => 'switch', 'name' => 'crossing_ready'],
  ],
  'sets' => [
    ['type' => 'event', 'name' => 'crossing_complete'],
  ],
  'whenBlocked' => 'The crossing is not ready.',
  'cue' => ['symbol' => '!', 'color' => 'bright-yellow'],
  'data' => [
    'cinematicId' => 'dawn-crossing',
    'mode' => 'auto', // auto or action
    'reusable' => false,
  ],
],
```

`auto` launches when the player enters the area, including an initial map or
save spawn already inside it. `action` presents the ordinary field action.
Both modes re-check inherited conditions at execution time, preserve cues and
blocked messages, and refuse re-entry or launch while another field session
owns control. Missing, malformed, and unresolved IDs fail closed with map,
marker, and asset context; they never fall back to a story script.

`CinematicController` remains the interpreter's direct completion target.
`GameScene::startCinematic()` and `CinematicController::start()` accept an
optional typed downstream completion target so the controller can first
record `cinematic:<id>:completed`, finalize audio, restore camera and field
presentation, and remove staged actors. It then notifies the trigger exactly
once. Normal and legally skipped completion call the inherited
`EventTrigger::complete()` path, which applies `sets` and persists a
non-reusable `mapId:marker`. Reusable triggers apply their completion writes
but do not persist that map marker. Controlled failure performs cinematic
cleanup and notifies failure without cinematic completion, trigger
completion, or trigger `sets`, leaving the trigger retryable.

There is deliberately no nested `start_cinematic` event command. A command
would execute while the same interpreter already has a session owner, making
parent continuation and cleanup ambiguous. Compose reusable command work with
a Common Event, or launch the complete Cinematic from the programmatic or map
trigger boundary above.

## Command trees and cooperative lanes

The script file returns a command list, or an array with a `commands` list.
Immediate commands may be placed directly in that list. A `sequence` owns a
nested `commands` list. A `parallel` owns ordered lanes; each lane is either a
command list or an object containing a stable `id` and `commands` list.

```php
return [[
  'type' => 'parallel',
  'lanes' => [
    [
      'id' => 'formation',
      'commands' => [[
        'type' => 'move_route',
        'subject' => 'staged_actor',
        'actorId' => 'lead-craft',
        'secondsPerStep' => 0.12,
        'steps' => [['direction' => 'right', 'count' => 6]],
      ]],
    ],
    [
      'id' => 'camera',
      'commands' => [[
        'type' => 'camera',
        'operation' => 'track',
        'target' => ['kind' => 'staged_actor', 'id' => 'lead-craft'],
        'seconds' => 0.72,
      ]],
    ],
    [
      'id' => 'narration',
      'commands' => [[
        'type' => 'narration',
        'title' => 'Before Sunrise',
        'text' => 'The formation crossed the quiet plain.',
        'seconds' => 0.72,
      ]],
    ],
  ],
]];
```

Parallelism is cooperative and deterministic. Each lane owns its frame stack,
cursor, and pending operation. Every game tick advances lanes in authored
order, and the parent completes only after all lanes complete. No operating-
system threads are used. A `common_event` command calls an existing
`assets/Events/<id>.php` module within the same session. Cinematic Common
Events are validated recursively when loaded; malformed list shapes or
command entries fail the cinematic with its ID, Common Event ID, lane, and
command path instead of being silently filtered.

## Subjects, staged actors, and movement

Subject references support `player`, `party_actor`, `npc`, `staged_actor`,
`position`, `screen_position`, and `marker` where the receiving command makes
sense. Map NPCs use their stable map-local `id`. Temporary staged actors use a
cutscene-local ID and are not party members, enemies, persistent NPC records,
or saved world entities.

The declared staged-actor fields are `id`, `sprite`, `asset`, `x`, `y`,
`facing`, `visible`, `collision`, and directional `sprites`. Collision
defaults to `false`. `stage_actor`, `show_actor`, `hide_actor`, and
`remove_actor` alter the temporary cast. `move_route` accepts
`subject => staged_actor` with `actorId`; its timing and cardinal step shape
are the same as player and NPC routes.

A staged actor with `collision => true` obeys map passability and cannot enter
the player's tile, a visible NPC's tile, or another visible collidable staged
actor's tile. A failed route names the staged actor, attempted coordinate, and
blocker. A staged actor with `collision => false` is explicitly
presentation-only: its route ignores terrain, occupancy, and map bounds and
does not affect field collision.

Staged actors render through the field camera. Map transfer, normal
completion, authored skip, and controlled failure remove temporary cast state.

## Camera operations

The `camera` command delegates movement and clamping to `Rendering\Camera`.
Its operations are:

- `detach`, `attach`, and `reset` for player-follow ownership;
- `focus` for an immediate subject or coordinate focus;
- `pan` for timed interpolation;
- `route` for ordered `points`, each with its own target and duration;
- `track` for a moving subject over a duration;
- `shake` for bounded displacement that returns to its base position;
- `restore` for the previous camera state.

Timed operations yield inside their lane, so camera, actor, dialogue, audio,
and presentation work may overlap. Reduced-motion mode jumps each motion to
the same final camera state. Completion, skip, transfer, and failure cannot
leave ordinary play with a stranded detached camera.

## Presentation and audio

Cinematics may compose existing Engine presentation systems with:

- `field_animation` at a subject, map position, or screen position;
- `title_card` and `narration` timed overlays;
- `transition` for Engine-supported fades and wipes;
- `clear_presentation` for temporary overlays;
- `cinematic_music` for a non-blocking music transition.

`cinematic_music` supports `track`, `loop`, `fadeIn`, `fadeOut`,
`completionBehavior`, and `restorePreviousMusic`. Completion behavior is
`continue`, `stop`, or `restore_previous`; `restorePreviousMusic` remains a
compatible boolean spelling of the restoration intent. The same authored
audio final state is applied for completion, legal skip, and controlled
failure.

Field animation and transition sessions advance from elapsed time and do not
sleep the game loop. Reduced-motion mode preserves command ordering and final
presentation state while omitting unnecessary intermediate motion.

## Transfer and battle continuation

`transfer` and `start_battle` retain the story-event behavior described in
[Story events](story-events.md). They suspend and resume the same cinematic
session rather than launching a second interpreter. Battle results still use
the existing `BattleResult` contract and existing reward, quest, bestiary,
party-cleanup, and audio paths.

Cinematic transfers do not invoke the project's legacy blocking configured
`ScreenTransition` out/in pair. Authors cover them with explicit cinematic
`transition` commands; an active transition cover is recomposed over the new
map until the authored reveal. Ordinary event and field transfers retain the
configured transition path. Reduced-motion mode reaches the same covered and
revealed final states without intermediate animation frames.

An active battle is an irreversible boundary for generic skipping. Skip is
refused while a battle is active; authors must not use skip to infer or
duplicate a battle outcome.

## Skip, checkpoints, finalizers, and cleanup

The default skip policy is `forbidden`. `authored` skip requires a non-empty
finalizer and is accepted only when every reachable path is skip-safe.
Validation recursively inspects sequences, parallel lanes, branches, choice
arms, and cancellation arms. `start_battle`, `give_item`, `give_gold`,
`accept_quest`, `recover_party`, and `knowledge` are irreversible for this
policy. Common Events inside authored-skippable cinematics are conservatively
rejected because their future contents cannot be proven by the asset alone.

A skip cancels every active playback lane and pending operation, clears
dialogue and temporary presentation, then starts the authored finalizer once
through the same interpreter. Further skip input while that finalizer is
active is ignored and cannot cancel it. Normal and skipped completion share
the stable `cinematic:<id>:completed` identity and exactly-once completion
claim.

Finalizers deliberately use a restricted central vocabulary:
`set_switch`, `set_variable`, `record_event`, `move_player`, `transfer`,
`camera`, `remove_actor`, `clear_presentation`, and `cinematic_music`. Type is
not enough: `set_switch` requires an explicit boolean; `set_variable` requires
an explicit scalar value and only `set`; player movement and transfer require
integral coordinates; transfer requires a map; camera allows only `attach` or
`reset`; actor removal requires an ID; presentation cleanup accepts no extra
payload; and cinematic music requires explicit track, loop, and completion
policies. These shapes are exported by `CinematicCommandSchema`.

`checkpoint` remains a playback command, not a finalizer command. Checkpoint
declarations are non-empty stable unique strings and record progress only
inside the active in-memory session; they do not serialize the lane stack.
A controlled failure cancels all lanes, clears temporary stage and
presentation state, restores camera and field input, discards deferred
autosave, and does not write cinematic completion.

## Save restriction

Active cinematic and ordinary event sessions are intentionally not
serializable. `SaveManager` rejects numbered saves and quicksaves while either
is active. Transfer autosaves remain deferred until successful completion and
are discarded on failure. After completion or legal skip, saving becomes
available again at the stable final map and state.

## Runtime and validation entry points

Games start a hydrated asset with `GameScene::startCinematic($definition)` or
by stable ID with `GameScene::startCinematic('dawn-crossing')`. An authored
skip is requested through `GameScene::skipCinematic()`; ordinary field input
is otherwise suspended while `CinematicController` owns control.

Authoring tools must consume Engine-owned contracts rather than copy lists:

- `CinematicLibrary` discovers and hydrates assets;
- `CinematicDefinition::fromArrays()` validates metadata and finalizers;
- `CinematicCommandPolicy` validates authored-skip reachability and finalizer
  payload shapes;
- `CinematicScriptValidator::validate()` validates nested command structure
  without booting a game;
- `CinematicCommandSchema::export()` exposes command types, nested block
  shapes, cinematic fields, subject kinds, camera operations, staged-actor
  fields, skip policies, finalizer types and shapes, irreversible skip
  commands, the Common Event skip policy, music fields, the cinematic map-
  trigger class/data/modes/completion semantics, and summon definition,
  timeline, playback-config, and session fields;
- `EventInterpreter::COMMAND_TYPES` remains the authoritative runtime command
  vocabulary and is derived from the same schema.

Present cinematic list fields must be actual lists, every command entry must
be a keyed array, and versions must be positive integers. Validation failures
include the cinematic ID and command path. Runtime
failures add lane path, nested content reference where available, and the
underlying reason. Editor authoring for these contracts is a separate gate;
the Engine APIs do not claim that the Editor surface is complete.
