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
`assets/Events/<id>.php` module within the same session.

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

An active battle is an irreversible boundary for generic skipping. Skip is
refused while a battle is active; authors must not use skip to infer or
duplicate a battle outcome.

## Skip, checkpoints, finalizers, and cleanup

The default skip policy is `forbidden`. `authored` skip requires a non-empty
finalizer. A skip cancels every active lane and pending operation, clears
dialogue and temporary presentation, then runs the authored finalizer through
the same interpreter. Normal and skipped completion share the stable
`cinematic:<id>:completed` identity and exactly-once completion claim.

Finalizers deliberately use a restricted central vocabulary:
`set_switch`, `set_variable`, `record_event`, `move_player`, `transfer`,
`camera`, `remove_actor`, `clear_presentation`, `cinematic_music`, and
`checkpoint`. These commands must describe the deterministic, idempotent
state needed after either playback route. Items, gold, quest changes, and
battle rewards do not belong in a skip finalizer because replaying them could
duplicate irreversible results.

`checkpoint` records named progress inside the active in-memory session. It
does not serialize the lane stack and is not a substitute for the finalizer.
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
- `CinematicScriptValidator::validate()` validates nested command structure
  without booting a game;
- `CinematicCommandSchema::export()` exposes command types, nested block
  shapes, cinematic fields, subject kinds, camera operations, staged-actor
  fields, skip policies, allowed finalizer types, music fields, and summon
  definition, timeline, playback-config, and session fields;
- `EventInterpreter::COMMAND_TYPES` remains the authoritative runtime command
  vocabulary and is derived from the same schema.

Validation failures include the cinematic ID and command path. Runtime
failures add lane path, nested content reference where available, and the
underlying reason. Editor authoring for these contracts is a separate gate;
the Engine APIs do not claim that the Editor surface is complete.
