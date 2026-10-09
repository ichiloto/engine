# Summons

Summons are frame-driven audiovisual timelines used by battle and preview
hosts. They are distinct from field Cinematics, which execute story-command
trees through `EventInterpreter`; see [Cinematic cutscenes](cinematics.md).

A summon is an authored cutscene paired with a battle skill. Each summon lives
in its own folder under the project's `assets/Cutscenes/Summons/` directory:

```
assets/Cutscenes/Summons/
└── ifrit/
    ├── ifrit.data.php      # identity, transitions, wielder rules
    └── ifrit.timeline.php  # frame-by-frame animation
```

A summon appears under the battle Summon command when its `linkedActionId`
matches the name of a battle-usable skill in `assets/Data/skills.php`.

## Data file keys

```php
return [
  'id' => 'ifrit',
  'name' => 'Ifrit',                       // shown on the title card
  'moveName' => 'Hell Fire',               // announced when the summon attacks
  'description' => '...',
  'linkedActionId' => 'Ifrit',             // must match a skill name
  'availability' => [
    'conditions' => [
      ['type' => 'event', 'name' => 'ifrit_linked'],
    ],
  ],
  'transitionIn' => ['type' => 'fadeToBlack', 'durationMs' => 450, 'color' => 'red'],
  'transitionOut' => ['type' => 'fadeFromBlack', 'durationMs' => 300, 'color' => 'red'],
  'effectTiming' => ['mode' => 'cue', 'cueId' => 'apply_ifrit_damage'],
  'targetPresentation' => ['mode' => 'full_screen', 'showCasterNameBanner' => true],
];
```

- `name` is the summon's identity (the "[ IFRIT ]" title card).
- `moveName` is what the battle log announces when the summon acts. Without
  it, the summon name is announced instead.

### Codex fields

Optional lore fields feed the in-game summon codex (the detail view players
open from the Summons menu):

```php
'lore' => 'A djinn of living flame, bound to the mortal world by an oath …',
'element' => 'Fire',
'strengths' => ['Ice', 'Flora'],
'weaknesses' => ['Water'],
'attributes' => [               // free-form label => value pairs
  'Power' => 'A',
  'Speed' => 'C',
  'Temperament' => 'Wrathful',
],
```

`lore` is the long-form "who or what is this being" text (the short
`description` describes the move instead). All codex fields are optional —
missing ones are simply omitted from the detail view.

## Controlling world availability

Availability and assignment answer different questions. Availability says
whether a summon exists for the player in the current world state. The
optional `wielders` policy says who may hold or use it once available. Games
may use either contract independently or compose both.

An omitted `availability` block preserves the historical open behavior. When
the block is present, every condition must hold according to the existing
world-condition vocabulary described in [Story events](story-events.md).
Locked summons are absent from battle, the management screen, and the main
menu's Summons entry when nothing else is available.

Availability can therefore model common JRPG schemes without changing the
summon runtime: an always-known spell, a story unlock, an item- or actor-gated
entity, or a summon temporarily suppressed by world state. Unknown condition
types, an empty conditions list, and malformed blocks fail closed. They do
not turn an authored asset into an open summon.

## Choosing who can wield a summon

By default a summon is **open**: every party member can use it and no
assignment is needed. To restrict it, add a `wielders` block to the data file:

```php
'wielders' => [
  'mode' => 'characters',       // 'all' | 'roles' | 'characters'
  'characters' => ['actor.luna'], // stable data.id values, used when mode = characters
  'roles' => ['Summoner'],      // used when mode = roles
  'tenancy' => 'exclusive',     // 'shared' | 'exclusive'
],
```

Eligibility (`mode`):

- `all` — any party member may be assigned the summon.
- `roles` — only characters whose role name is listed in `roles`.
- `characters` — only characters whose stable `actorId` is listed. References
  match the actor definition's `data.id`, case-insensitively after trimming,
  not its display name or filename. This builds an actor-signature summon or
  an explicitly identified group of summoners.

The existing `characters` key and list-of-strings format are retained. A
display rename cannot remove eligibility, grant it to another actor with the
same name, or bypass an exclusion. Grant, command visibility, execution
rechecks and restored-assignment validation all use this same policy.

Legacy name-only actors already capture their original name as the provisional
`actorId`; existing wielder entries containing that original name still work,
even after a runtime display rename. Before renaming authored actor data,
freeze that original name as `data.id` through the existing actor identity
repair. The old wielder entry then remains a stable ID without a new schema.

For an actor that already has a different explicit `data.id`, migrate each
legacy display-name wielder entry to that ID in the summon data file. Resolve
the intended definition before writing; duplicate names must not be guessed.
Authoring pickers must store the stable actor ID (the Editor's `actor_ids`
reference category), not the current name. This is an explicit authoring
reference migration, not a runtime name alias or a rewrite of saved ownership.
An identified actor never gets a display-name fallback: a stale name-only
entry that is not its ID fails closed until corrected.

Tenancy:

- `shared` — any number of eligible members may hold the summon at once.
- `exclusive` — only one member may hold it at a time; it must be
  held by at most one member at a time.

As soon as a summon declares `wielders`, it becomes **assignment-based**: a
character must both satisfy the eligibility rules *and* currently hold the
summon for it to appear in their battle menu.

These two independent policies cover the usual JRPG arrangements without a
game-specific summon subsystem:

| Scheme | Availability | Wielder policy | Starting/runtime assignment |
| --- | --- | --- | --- |
| Shared spell-like summon list | omitted or world-gated | omitted | none; every member with the command may use it |
| Equippable entity pool | optional world gate | `all` plus `exclusive` | player assigns one holder at a time |
| Shared learned entity | optional world gate | `all` plus `shared` | assign every member who has learned it |
| Job or class summon | optional world gate | `roles`, shared or exclusive | assign eligible role members |
| Character-signature summon | optional world gate | `characters` plus `exclusive` | start or script the identified actor's assignment |
| Small identified summoner group | optional world gate | `characters` plus either tenancy | assign within that authored ID group |

Omitting a policy is intentionally different from declaring a malformed one.
Omission retains the legacy open pool; malformed declared policies fail
closed and require authoring correction.

### Assigning summons

Grant starting summons in actor data:

```php
// assets/Data/Actors/Luna.php
'data' => [
  'id' => 'actor.luna',
  'name' => 'Luna',
  // ...
  'summons' => ['ifrit'],   // summon ids
],
```

Or assign at runtime — the party enforces eligibility and tenancy:

```php
$definition = new SummonCutsceneLibrary()->findById('ifrit');

if ($party->assignSummon($definition, $character, $gameState)) {
  // assigned — appears in this character's battle menu
}

$party->unassignSummon('ifrit', $character);
$party->getSummonHolders('ifrit');          // Character[]
$party->canAssignSummon($definition, $character, $gameState);
$party->reassignSummon($definition, $otherCharacter, $gameState); // atomic move
```

Assignments serialize with the character, so they survive saving and loading.
A legal saved assignment remains recorded while its availability gate is
false, but it cannot be used until the gate becomes true again. Corrupt,
missing, ineligible, or duplicate exclusive ownership is rejected during
runtime hydration instead of being silently opened or discarded.

Starting assignments are appropriate for definitions that are available at
New Game. A condition-gated summon should normally be assigned by game logic
after its gate opens; project validation reports a gated starting assignment.

## Graphical Tracks And Battle Preview

Summon image tracks use the same validation and rendering contract as ordinary
[effect timelines](effect-animation.md), not a separate cinematic renderer.
Assets are root-relative PNG paths; sheet geometry, cells, depth, battler/screen
anchors, ground/body attachment, normalized pivots, optional image fitting and east/west facing have
the same meaning. Image keyframes select sourceFrame and optional boolean
flipX/flipY. Read current file dimensions; changing approved art does not
require updating a hash or duplicating file dimensions in the summon source.
Use `fit => contain` to keep source-frame proportions inside the authored cells
box and available battle canvas. Omission retains legacy stretch sizing. The
same option applies to ordinary field and battle effects, without changing
Terminal geometry, a battler's identity, or the selected gameplay outcome.

```php
return [
  'formatVersion' => 1,
  'fps' => 24,
  'lengthFrames' => 48,
  'restFrame' => 24,
  'tracks' => [[
    'id' => 'arrival', 'type' => 'image', 'presentation' => 'graphical',
    'asset' => 'Effects/summon-arrival.png',
    'sheet' => ['columns' => 2, 'rows' => 1],
    'cells' => ['width' => 4, 'height' => 6], 'fit' => 'contain',
    'depth' => 'front', 'anchor' => 'caster', 'attachment' => 'ground',
    'pivot' => ['x' => 0.5, 'y' => 1.0], 'facing' => 'west',
    'keyframes' => [
      ['frame' => 0, 'duration' => 24, 'sourceFrame' => 0],
      ['frame' => 24, 'duration' => 24, 'sourceFrame' => 1],
    ],
  ]],
  'cues' => [['id' => 'contact', 'frame' => 24, 'type' => 'applyEffect']],
];
```

The data file can select contact with effectTiming mode cue and cueId contact.
Art supplies the presentation; a cue selects the existing skill outcome and
does not invent damage in the renderer. Legacy glyph/text tracks without an
explicit anchor retain screen coordinates. New anchored tracks use caster,
target or screen explicitly. Legacy overlapping glyph keyframes remain legal;
image source-frame spans are exclusive within a track.

A timeline may instead contain exactly one presentations wrapper with terminal
and graphical sequences, each with its own fps, lengthFrames, restFrame, tracks
and cues. Keep formatVersion and editor metadata at the outer document only,
not inside either sequence. It may not mix that wrapper with shared tracks/cues. Compilation
selects one sequence using EffectPresentation; Terminal selection never opens
the unselected graphical PNGs. Both presentations share summon identity,
wielder policy, skill and effectTiming. Provide the selected impact cue in each
sequence when using cue timing. Rich graphical footage need not dictate the
Terminal's visual cadence.

For a selected graphical sequence with a validated cinematic stage, that stage
owns its covers on its authored clock. Common data-file transitionIn/out remain
authored legacy treatments for non-staged selections, including independent
Terminal playback. They do not need to be zeroed to compile a stage. This removes
the former rejection of staged summons with nonzero common transition durations.
Flat sources follow the same selected-presentation rule.

The compiler's transitionCache contains effective legacy transitions: in/out
durationMs is zero for a selected stage; other transition metadata is retained.
Non-staged selections retain the complete common transitions. Battle playback
omits legacy in/title/out phases for a stage, so covers are never applied twice.
Compilation does not rewrite definition/source arrays. Editors must not save
effective compiled durations back into authored data. Version-4 compiled caches
use this contract; the library revalidates current assets and compares the full
selected payload before accepting a disk cache. A per-battle cache stays stable
for that battle, with Terminal and Graphical entries independent.

```php
$compiler = new SummonCutsceneCompiler(assetRoot: $assetRoot);
$graphical = $compiler->compile($definition, EffectPresentation::GRAPHICAL);
$terminal = $compiler->compile($definition, EffectPresentation::TERMINAL);
$plan = new BattleCommandTimeline($timings,
  target: $graphical, terminalTarget: $terminal);
$preview = new BattleCommandPreview($battleConfig, $plan,
  $actor, $targets, BattlePoseRole::SUMMON, $catalog, $assetRoot);
$frame = $preview->getFrameAtTime($seconds, $reducedMotion)->toArray();
```

BattleCommandPreview is random-access inspection, not command execution. Its
frame payload contains frame, phase, canvas (or null), terminalLines,
terminalCanvas, cues,
crossedCues and diagnostics. Explicit frame indices use the command lane's
120 fps; authored summon fps is preserved within its effect stage. The
totalFrames property supplies the range. Every seek builds a side-effect-free
playback: no MP/HP/inventory writes, gameplay callback, sound or native launch.
The canvas uses GraphicalBattlePresentation and the arena rows use
TerminalBattlePresentation, the same geometry/effect owners as runtime.

`terminalCanvas` is the structured, text-only projection of `terminalLines` for
the GUI's Terminal tab. `TerminalPresentationComposer` supplies the same colour
and logical-cell conversion used by Console output, at the battle arena's grid
dimensions. Editors must paint that canvas instead of fitting ANSI byte lengths
or adding another escape-sequence parser. The formatted `terminalLines` remain
unchanged for Terminal/TUI clients; terminal-only preview construction still
needs no graphical catalog or PNG. The graphical `canvas` remains independent.

The paired terminalTarget lane previews Terminal visuals on the graphical
command's stage duration. To inspect the independent Terminal command cadence,
build its plan with the Terminal-compiled summon as target. A TUI author never
needs a graphical asset picker to preserve the graphical source. No runtime
video decoding or automatic alpha extraction is implied by image-track support.

## Non-blocking Playback And Preview

`SummonCutsceneCompiler` remains the source-to-runtime boundary. A compiled
cutscene can now be hosted cooperatively by `SummonPlaybackSession`:

```php
$compiled = (new SummonCutsceneCompiler())->compile($definition);
$playback = new SummonPlaybackSession($compiled);

$update = $playback->update($elapsedSeconds);
$playback->currentFrame;
$playback->activeSegments();
$update->crossedFrames;
$update->crossedCues;
```

The session exposes total frames, FPS, effective speed, current frame,
paused/completed/looping state, pause/resume, clamped seek, forward/backward
step, restart, active segments, and per-frame cue inspection. `update()`
returns every crossed frame and cue in deterministic source order even when
one elapsed-time update spans several frames.

`cuesAt()` is inspection only: scrubbing or seeking in an authoring preview
does not pretend runtime cues fired. Remaining in a frame does not re-emit its
cue. The first positive elapsed-time update reports any frame-zero cues once.
A loop begins a new traversal cleanly, and restart returns to frame zero with
the same one-time opening-cue behavior.

The existing blocking `SummonCutscenePlayer::play()` API is source-compatible
and delegates traversal to this session. Battle now passes its frame and cue
callbacks, so `effectTiming` determines when the skill's gameplay effect
lands. `cue` requires an existing `cueId`; `frame`/`explicit_frame` requires
a `frame` number; `end` resolves after the last frame and before the outgoing
transition. Resolution happens once even when rendering fails or the host
draws no summon art. A render failure still traverses remaining cues
logically, reports the presentation error and restores the battlefield;
gameplay failures propagate instead of being retried. MP spending, assignment
rules and the authored data format are unchanged.

If `effectTiming` or its mode is omitted, the effect resolves at `end`.
Unknown modes produce a warning and use `end` rather than silently changing
when combat resolves.

Timeline cues use `id`, `frame`, `type`, and an optional `payload` map. Battle
recognizes these payload keys:

| Cue type | Payload keys | Behavior |
| --- | --- | --- |
| `applyEffect` | none | Its id can be selected by `effectTiming.cueId`; the timing gate resolves gameplay once. |
| `showMessage` | `text` (or `message`) | Shows non-empty text in the battle message panel. |
| `playSound` | `soundEffect` (or `sound`, `assetId`) | Plays the named effect through the game's audio manager. |
| `flash` | `color`, `durationFrames` (or `duration`), `scope` | Flashes the `screen` (default) or `target`; default color is white and minimum duration is one frame. |
| `shake` | `amplitude`, `durationFrames` (or `duration`) | Shakes the battlefield for at least one frame; default amplitude is one. |
| `restoreBattlefield` | none | Clears flash, shake and effect art, then redraws the field. |

Visual `flash` and `shake` cues are skipped in reduced motion. Other cues and
their ordering remain active, and the final frame stays visible through the
effect display phase before the battlefield returns.

Reduced motion delivers every cue in authored order, applies the skill effect,
and holds the authored restFrame (the final frame for legacy timelines without
one). The battle skips summon title and transition
motion as well as visual flash and shake. In terminal presentation, active
segments draw by `zIndex`; `clearBeforeDraw` clears lower queued effect art.
Flash cues can recolour the target area for their authored frame duration;
screen flashes, camera shake and graphical pose animation are removed from
Terminal command drawing. A battle uses one cached definition/compiled-timeline library
until the scene stops; the next battle reads current assets. Invalid optional
summon definitions are warned about individually so valid summons still appear
in the command catalogue. Authoring tools should obtain preview and
definition field names from `CinematicCommandSchema::export()` instead of
copying them.

### The in-game Summons menu

Any project with at least one currently available summon gets a summon-management entry
in the in-game main menu (labelled from the project's summon vocabulary —
"Summons", "Petitions", …). Players pick a character and see every summon
with its move name, wielder rules, and status:

- `[Open to all]` — no wielder policy; the whole party can call it.
- `[Assigned]` — held by this character; confirm releases it.
- `[Available]` — eligible; confirm assigns it.
- `[Held by <name>]` — an exclusive summon bound to another member; it must
  be released by its holder first.
- `[Not eligible]` — the character fails the summon's role or name rules.

Tab cycles through party members. Pressing confirm on a summon opens its
**codex entry**: lore, move, element, attributes, strengths, and weaknesses,
plus the wielder rule and this character's status. Inside the entry, confirm
assigns or releases policy summons; `c` returns to the list.

The screen is the natural home for future summon mechanics (growth,
junctioned skills and magic attributes). It disappears when the project has
no currently available summons, and locked definitions do not appear as
assignable or player-owned.

## Renaming the summon command

The battle command's label is project vocabulary. Rename it game-wide, or per
character role for games whose lore names the technique differently by tribe
or calling:

```php
// config.php
'vocab' => [
  'command' => [
    'summon' => 'Summon',            // game-wide label
    'summon_by_role' => [            // per-role overrides
      'Oracle' => 'Petition',
      'Vanguard' => 'Request',
    ],
  ],
],
```

A character whose role has an override sees that label in their battle menu;
everyone else sees the game-wide label. All labels — default, renamed, and
per-role — resolve back to the same summon command internally, so submenu
routing, help text, and empty-state messages follow the rename automatically.
