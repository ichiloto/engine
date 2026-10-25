# Versioned save compatibility

This is a production-hardening extension to the shipped persistence systems
in roadmap Phases 1, 2, 3, and 6. It does not replace `SaveManager`,
`GameConfig`, `GameState`, `QuestLog`, the party, achievements, bestiary,
quicksave, autosave, or title Continue.

## Container and envelope

The file family remains unchanged:

1. the four-byte `IED1` header;
2. a gzip stream;
3. one PHP-serialized root array.

Schema version 1 changes only the root array:

```php
[
  'schemaVersion' => 1,
  'contentVersion' => 1,
  'projectId' => 'vendor/stable-project-id',
  'payload' => [
    'slot' => $saveSlot,
    'config' => $gameConfig,
  ],
]
```

`schemaVersion` describes the engine-owned structure. `contentVersion`
describes the game-owned identity and meaning of content. They advance
independently. The project ID is read from the canonical `ichiloto.json` `id`;
the manifest does not define a competing identity.

A root containing `slot` and `config` but no envelope fields is legacy schema
0 and content 0. `SchemaVersion0To1Migration` wraps it in memory and folds its
old `GameConfig::$events` strings into `GameConfig::$gameState['storyEvents']`.
This is the only legacy-events migration path; `GameScene::configure()` no
longer repeats it.

## Save discovery and checkpoint ordering

`SaveManager::getManualSlotCount()` is the single runtime capacity authority.
It resolves `config.php` project configuration `saves.manual_slots`, a positive
integer defaulting to **30** when omitted. Save and Title Load ask that same
manager without menu-owned counts. Projects can customize capacity, for example
`'saves' => ['manual_slots' => 40]`; this is not a player option or campaign-save
field. Invalid configured values are refused, not silently replaced. Existing
explicit `getSaveSlots($count)` / `getLoadSlots($count)` inspection ranges remain
available for callers; the runtime menus do not use those overrides.

Changing capacity does not rename, reset, migrate or delete numbered files.
Legacy `file-01.iedata` through `file-05.iedata` retain their identities, and
slot 30 uses `file-30.iedata`. Lowering capacity hides higher numbered slots
from these menus, without preventing explicit `loadSlot()` / path loading;
raising it exposes them again. Latest-file discovery still considers existing
files independently of menu capacity. No Game setting is needed for 30 slots.
Editor controls for authoring this project setting are not implemented here;
source-preserving settings authoring remains in the Editor owner's lane.

Arriving on a save point cell (`?` collision) runs the event script named by
`config.php` `save.point_script`, as a `common_event` would, so a game can heal,
pay out held income or speak there. Without one, the Engine shows its own save
point notice, reworded through the `save.point_prompt` and `save.point_title`
Terms (a blank prompt is silent). The script id is validated as a stable id;
the Editor offers it from the project's event scripts in Configuration.
**Removed:** the hard-coded English save point alert text.

`SaveManager::getSaveSlots()` remains the numbered-only writable Save catalog.
Developer `config.php` options `save.autosave` (default false) and
`save.quicksave` (default true, preserving the existing Quick Save capability)
are validated booleans; either, both or neither may be enabled. They are project
capabilities, never player preferences or campaign fields. Disabled mechanisms
reject direct SaveManager writes, are excluded from Load and latest-loadable
Continue discovery, and Quick Save disappears from Controls and control hints.
Title Load stays enabled when enabled files exist, including invalid records
so their diagnostic remains reachable. Direct GameOver Continue requires an
enabled loadable checkpoint. Shared `getEnabledSaveFiles()` and
`hasEnabledSaveFiles()` provide that distinction.
Raw `getSaveFiles(true)` remains an inspection/order surface, including disabled
and legacy records; explicit path loading remains available for tools.

`getLoadSlots()` shows one logical **Autosave first** whenever enabled (empty
until a file exists), followed by numbered files, then an existing enabled
Quick Save. The Autosave resolves the newest loadable `auto.iedata` or legacy
`auto-NN.iedata` by persisted order, skipping corrupt/incompatible newer files.
If every candidate is invalid, the newest failure is visible and disabled.
Inspection never restores campaign/settings/playtime or writes a migration.
All legacy files remain byte-for-byte intact until explicitly managed by their
owner; new autosaves replace only `auto.iedata` atomically. No destructive
migration or copying of stale payloads occurs.

**Removed:** three-file rotating autosave writes and automatic map-transfer
checkpoint decisions. Engine provides `GameScene::autoSave()` as a developer
checkpoint request: requests during an unstable event defer until successful
completion and are discarded on failure. Moving maps alone never requests a
save. `SaveManager::autoSave()` enforces capability and stability directly.
**Removed:** hardcoded F5 field dispatch. `quick_save` is a semantic action in
`input.php`, included in player rebinding and live hints. For older projects,
Engine supplies F5 only if unclaimed; an explicit binding (even empty) wins.
The shared field dispatch and writer both honor the Quick Save capability.

`SaveSlot::getLabel()` shares File, Autosave and Quick Save vocabulary labels.
`SaveSlotPage` owns five records per ordinary page across both presentations,
including a populated five-record tail when the catalog contains at least five
records. `SaveSlotMenuLayout` uses compact three-row records at Terminal 80x24,
standard four-row records on taller surfaces, and fewer whole records when
height requires it. Supported Terminal minimum is 40x8; smaller capabilities
are refused explicitly rather than overflowing. Both scenes handle semantic
previous/next-page actions, normal row navigation, wraparound and resize.
Graphical cards divide their finite list into five records; unusually large
metrics reduce capacity explicitly, and a viewport unable to fit one complete
record is refused. **Removed:** variable-height wrapped graphical card rows.
Compact rows truncate display text; the full selected location, leader, duration
or compatibility error stays accessible in the existing paged Info panel via
`info`. Neither selector adds writable automatic/quick Save rows.

New SaveManager writes add optional positive integer `saveOrder` to the existing
schema-1 envelope root, beside `schemaVersion`, `contentVersion`, `projectId`
and `payload`. No container or content migration is required; existing readers
ignore that optional scalar, and the serialized SaveSlot/SavedGame contracts do
not change. `savedAt` remains the actual wall-clock timestamp used for metadata,
not an ordering counter.

This removes **filemtime-only ordering** from latest-file discovery. Each
successful write records the greater of current epoch microseconds and the greatest persisted order plus one, across all numbered,
quick and automatic files. Reconstructing the manager after restart therefore
retains monotonic ordering even within one filesystem second or after a clock
rollback. Ordering inspection disables PHP class instantiation and reads only
the scalar envelope field; it does not hydrate campaign objects or run project
compatibility callbacks. Files without a usable order, including legacy and
corrupt files, retain modification-time ordering in microsecond units. Exact
ties use ascending full path as a deterministic tie-break, not proof of which
legacy checkpoint was truly written last. Future legacy modification times can
advance the logical order; this does not change `savedAt`. Integer exhaustion
refuses a write before replacing any good file.

Atomic replacement and the active-event guard remain: failed snapshot,
encoding or storage writes leave the prior save/order intact. Ordering assumes
one runtime writer per player-data root; it provides neither a cross-process
save transaction nor power-loss fsync guarantees.

Synthetic `SaveLoadCatalogTest` covers same-timestamp repeated checkpoints,
restart ordering, ring gaps/rotation, legacy ties/future timestamps, latest
loadable fallback, class-free order inspection, read-only state/settings/time,
path confinement, failed replacements, viewport paging and semantic control
hints, and Title dispatch through the shared saved-load path. It also covers
30-slot defaults and last-slot writing, configurable capacity changes, unchanged
legacy files, Save navigation/wraparound, and all records in both graphical themes.

## Pre-WP1 payload inventory

The legacy root was:

```php
['slot' => SaveSlot, 'config' => GameConfig]
```

The nested representation was and remains mixed:

| Saved domain | Representation | Identity contract | Rename exposure |
| --- | --- | --- | --- |
| Slot metadata | `SaveSlot` object | slot number/path plus display summary | no content lookup |
| Map/position/facing | string plus `Vector2`, `Rect`, `MovementHeading` objects | path-like map ID | map rename |
| World state | plain `gameState` array | switch/variable names; story strings; map IDs; `mapId:marker` | maps, events, story strings |
| Quests | plain `questLog` array | quest IDs | quest rename |
| Party | serialized `Party`, `ItemList`, `Character`, and inventory objects | character names and order | actor rename |
| Inventory/equipment | live item/equipment objects | authored display name | item/equipment rename |
| Ability/spell books | plain arrays inside each serialized `Character` | skill display name | ability/spell rename |
| Summon assignments | string list inside each `Character` | summon ID | summon rename |
| Character states | omitted before WP1 | state ID plus remaining turns in WP1 | state rename |
| Achievements | plain array keyed by achievement ID | achievement ID | achievement rename |
| Bestiary | plain seen/defeated arrays keyed by enemy name | enemy display name | enemy rename |
| Play time and sprites | scalar/array fields on `GameConfig` | no catalog lookup | none |
| Optional project state | bounded plain `projectState` array on `GameConfig` | registered namespace, schema version and project-defined stable references | project policy owns migration/validation |

The concrete object graph is serialized directly. That preserves existing
saves and avoids a replacement format, but PHP invokes nested
`__unserialize()` methods before the outer envelope is available. During
`SaveManager::loadSaveFile()`, `SaveHydrationContext` therefore asks only
`Character` to retain its raw array until content aliases and tombstones have
been applied. The rest of the decoded `GameConfig` is migrated before it is
given to `GameScene::configure()`. This is the safe hydration boundary within
the existing format.

Direct PHP object serialization also means old class names and property
layouts remain compatibility constraints. WP1 does not add a strict class
allowlist or replace serialized objects. Inventory and equipment aliases
update their persisted identity while preserving their serialized runtime
data; a future format change would be required to rebuild every item from
current catalog definitions.

## Load order

`SaveCompatibilityPipeline::load()` performs the same order for numbered,
quick, and autosave files, and for readers used by Continue and tooling:

1. `SaveManager` verifies `IED1` and decodes gzip.
2. The pipeline safely unserializes the root, converting PHP warnings into a
   `CorruptSaveException`.
3. It detects legacy or versioned shape.
4. It rejects a declared wrong project where identity is available.
5. It runs adjacent engine schema migrations.
6. It verifies the migrated project identity.
7. It runs adjacent project content migrations registered by the manifest.
8. `SaveContentResolver` resolves aliases and rejects tombstones.
9. Deferred characters hydrate their current ability, magic, summon, item,
   equipment, and state references.
10. Content migrations that implement
    `PostResolutionContentMigrationInterface` receive the resolved
    `GameConfig`. This optional phase is for deterministic repairs that need
    hydrated, current-content actors; identity migration remains in the
    ordinary pre-resolution phase.
11. The pipeline bounds and detaches optional project state. When supplied a
    `ProjectStateRegistry`, it also prepares every registered project policy
    after content resolution, without changing the live campaign.
12. The pipeline validates the required `SaveSlot` and `GameConfig` objects
    and returns a `SavedGame`. `GameScene::configure()` restores project state
    before campaign consumers run; GameLoader uses the same validated reader.

Loading never writes the source path. A migrated legacy game is written in
the current envelope only when the player explicitly saves.

## Saved Campaign Activation

Reading a save is not gameplay activation. `SaveManager::loadSaveFile()`,
`loadSlot()`, `canLoadSaveFile()` and slot lists remain read-only, and
`GameLoader::loadSavedGame()` still returns a validated `GameConfig`.
Actual gameplay callers must pass that config to
`SceneManager::loadSavedGame(GameConfig): GameScene`. Title Continue and the
Continue command use this entry. An external caller already holding the current,
registered, started field can call `GameScene::configureSavedGame(GameConfig)`.
Plain `configure()` and `configureForLaunch()` deliberately do not signal a save
load; new-game launch remains separate.

Projects may declare `assets/Data/saved-game-resume.php`, returning a class string
implementing `Ichiloto\Engine\Scenes\Game\SavedGameResumeHandlerInterface`:

```php
return \Project\Progress\ResumeActivity::class;
```

The declaration is optional and is owned by that `Game` instance through
`getSavedGameResumeRegistry()`, not a process-global handler. The Engine rejects
unreadable or malformed declarations, missing/wrong-contract/abstract classes and
constructors requiring arguments at bootstrap. Reconfiguring the registry clears
the previous declaration first; changing it during a pending load is an explicit
failure, not permission to run a stale handler.

`resumeSavedGame(SavedGameResumeContext $context): void` is called once after
project state, campaign, map, player and field state have been restored and the
project-state restoration transaction has completed. `$context->scene` is the
exact live field owner. Its existing project-state registry and scene manager
remain the only authorities: the project decides whether saved progress requires
an activity, supplies the recorded identities/rules and may call
`beginSceneExcursion($context->scene, $target, $configuration)`. No continuation,
handler or excursion ownership is serialized by this hook.

The Engine waits for the current registered, started field, its active player and
field state, no active story session, no transition and no active stacked or
UI-presented modal. Entry triggers, field input and movement are withheld until this
one-shot handoff. If the handler opens an excursion, entry triggers remain
deferred until the field owns gameplay again. A lifecycle that is not yet stable
is retried only while this load is pending; it is not project polling on every
game update. Stop, navigation (including non-modal suspension) and
reconfiguration/new-game launch cancel pending ownership. Foreign, unregistered
or stopped direct callers are refused;
loading over an already-active story session is refused without overwriting it.

A handler returns normally when no activity is required or it has successfully
handed off. It must throw if a required activity cannot be restored. Failures
propagate visibly and latch the loaded field against input/events until it is
stopped or explicitly reconfigured; they are not silently treated as successful
field resumes. Hydration failures retain the existing project-state rollback;
handler work happens after that transaction and is not implicitly rolled back.
The active-story save guard remains unchanged. With no declaration, saved-game
activation retains the existing immediate field behavior.

Future schema/content versions and wrong-project saves fail with their
declared/current versions, project IDs, and save path. Missing sequential
migration steps, corrupt payloads, invalid manifests, tombstones, and
unrestorable persistent states use controlled `SaveCompatibilityException`
subtypes.

## Project extension point

Projects author `assets/Data/save-compatibility.php` in the existing PHP-data
style:

```php
return [
  'contentVersion' => 1,
  'migrations' => [[
    'from' => 0,
    'to' => 1,
    'class' => Game\Save\ContentVersion0To1::class,
  ]],
  'aliases' => [
    'maps' => [['from' => 'old-town', 'to' => 'town']],
  ],
  'tombstones' => [
    'quests' => ['removed-quest'],
  ],
];
```

Migration classes implement `ContentMigrationInterface` and transform the
existing decoded `payload` array. Migrations must be adjacent, deterministic,
and must not execute a callable stored in a save. To advance from version 1
to 2, add exactly one `1 -> 2` step, retain all older steps, then raise
`contentVersion`.

### Declared map shifts

A step may declare position edits, `mapShifts`, `mapRemovals`, `relocations`
and/or `mapRetirements`, instead of `class` (a class or declared edits, never both).
It records blank rows or columns inserted into a map, so no generated PHP
class is needed:

```php
[
  'from' => 1,
  'to' => 2,
  'mapShifts' => [
    ['map' => 'town', 'axis' => 'y', 'at' => 4, 'by' => 2],
    ['map' => 'town', 'axis' => 'x', 'at' => 7, 'by' => 3],
  ],
]
```

`map` is a non-empty map ID, `axis` is `'x'` or `'y'`, `at` is the 0-based
insertion line (an integer of at least 0) and `by` is the inserted count (an
integer of at least 1). The list must be non-empty; malformed entries make the
manifest invalid. The engine applies the shifts in order to the saved player
position: when the save's map is `map` and its coordinate on `axis` is at or
beyond `at`, that coordinate grows by `by`. Map aliases apply to both the save
and the entry, so a renamed map still matches. The Editor's row and column
insert command appends such a step and raises `contentVersion`.

### Declared map removals

Rows or columns removed from a map are declared as `mapRemovals`:

```php
[
  'from' => 2,
  'to' => 3,
  'mapRemovals' => [
    ['map' => 'town', 'axis' => 'x', 'at' => 4, 'count' => 2, 'to' => [1, 3]],
  ],
]
```

`map` and `axis` follow the shift contract. `at` is the first removed line
(a non-negative integer), `count` is a positive integer, and `to` is a two-component
`[x, y]` coordinate of non-negative integers in the map **after** removal.
The list must be non-empty. Positions before the range keep their coordinates;
positions on the removed lines move to `to`; later positions move back by
`count` on that axis. Map aliases apply to both saved and declared map IDs.
Each removal uses the coordinates resulting from previous edits in its step.

The author must confirm an open, unoccupied, reachable landing. Its numerical
coordinates may coincide with a removed line's old coordinates: they refer to
the new map, not the deleted cells. Runtime migration uses that frozen landing,
never a search of mutable map content. Keep older steps unchanged and increment
`contentVersion`; an already-migrated save must not run the removal again.

### Declared relocations

When authored content (an NPC, furniture, a wall) comes to occupy cells a
player could have saved on, a step declares `relocations` instead of a
project class:

```php
[
  'from' => 16,
  'to' => 17,
  'relocations' => [
    ['map' => 'town', 'cells' => [[34, 5]], 'to' => [35, 6]],
    ['map' => 'town', 'cells' => [[55, 8], [56, 8]], 'to' => [55, 6]],
  ],
]
```

`map` is a non-empty map ID, `cells` a non-empty list of distinct `[x, y]`
cells and `to` the `[x, y]` landing, all non-negative integers; the landing
may not be one of the cells. A saved player position exactly on a listed cell
moves to the landing; every other position, including a fractional one, keeps
its coordinates. Map aliases apply as for shifts. The cells and landing are
frozen in the manifest: choose a landing that is open floor, free of events and
NPCs and reachable, because loading a save never consults the mutable map.

### Declared map retirements

When a map is retired and its places now stand on another map, a step
declares `mapRetirements`, so saves standing on the retired map move to the
matching place on its successor without a project class:

```php
[
  'from' => 69,
  'to' => 70,
  'mapRetirements' => [
    [
      'map' => 'overworld',
      'into' => 'continent',
      'landings' => [
        ['x' => 0, 'y' => 0, 'width' => 60, 'height' => 61, 'to' => [12, 40]],
        ['x' => 60, 'y' => 0, 'width' => 40, 'height' => 61, 'to' => [88, 31]],
      ],
      'to' => [30, 30],
    ],
  ],
]
```

`map` is the retired map ID and `into` the map its saves move to: both
non-empty and different. `to` is the `[x, y]` landing on `into` for a save no
landing holds. `landings` is optional; when present it is a non-empty list of
rectangles in the retired map's coordinates, each with a non-negative `x` and
`y`, a positive `width` and `height`, and its own `to` landing on `into`. A
save whose map is `map` moves onto `into` at the `to` of the first rectangle
holding its cell, or else at the entry's `to`. Map aliases apply to the save
and to `map`, as for shifts. A retirement whose `map` and `into` name one map
through the aliases is invalid.

The landings are frozen in the manifest: choose open floor on `into`, free of
events and NPCs and reachable, because loading a save never consults the
mutable map. Later steps that edit `into` move landed saves as they move any
other save on it.

Retirement carries what belongs to the places: a save that visited the
retired map has visited `into`, wherever the save stands, so the region map
keeps showing where the player has been. The retired map's own entry stays.

A retirement does not alias the retired map to `into`. A map alias says two
IDs name one map, so every earlier step's edits on the retired map would also
move saves standing on `into`, and every one-shot event `map:marker` would
read as `into:marker`, completing whichever event of `into` shares the marker.
A one-shot event that moved to another map follows it through an explicit
`one_shot_events` alias, such as `overworld:Q` to `high-reach:Q`. Completed
events of the retired map that no alias names stay under the retired map and
match nothing.

A step may combine `mapShifts`, `mapRemovals`, `relocations` and
`mapRetirements`; shifts apply first, then removals, then relocations, then
retirements, each list in authored order regardless of PHP key order, so a
retirement reads the position its step has already moved. A move belongs to
the step that introduced it: never add edits to an earlier step, since saves
already past that version will not run it again.

`ContentReferenceCategory` is the shared runtime/editor vocabulary: maps,
one-shot events, quests, actors, items, equipment, abilities, spells,
summons, states, story events, enemies, achievements and knowledge subjects.
Alias entries use a list of `from`/`to` pairs so contradictory duplicate
sources remain visible to validation. Chains resolve deterministically;
self-aliases and cycles are invalid. No rename is inferred.

A `knowledge_subjects` alias retires a subject into its successor, for
instance when two field records turn out to describe one species. A save that
holds progress for both keeps one record: the deeper depth, every observation
and report, and the outcomes of both added together, since each counted
different encounters.

Actor definitions must declare a non-empty string `data.id`. Display-name and
filename lookup aliases have been removed: only IDs resolve definitions, so two
actors may share a display name and a name may match another actor's ID without
changing either identity. Existing case-insensitive ID lookup is retained.
For a legacy file with an **absent** ID, the runtime file loader temporarily uses
its current name as an in-memory provisional ID and logs the source filename and
migration instructions. It never writes project files. An empty or malformed
authored ID remains an error, not a fallback.
Released projects also used actor file stems in `startingParty`. Only that
startup path can resolve a missing-ID actor by file stem, with a warning. A
registered ID takes precedence; explicit modern actors gain no filename or
display-name aliases, and ambiguous legacy references are refused.

Before renaming legacy content, use the Editor's **Freeze current name as ID**
repair or confirm the CLI validation migration (`validate --migrate-actor-ids`).
Both use the same source-preserving service. A project plan freezes missing IDs
and updates filename/display-name references to stable IDs, including projects
whose IDs were frozen earlier without updating their references. It lists every
changed file before confirmation. Starting parties, skits, actor presentation
bindings and battle-entry-rule actor predicates/effects share this inventory.
NPC/staged scene identities, enemy references, ordinary dialogue names and
name-based summon wielder restrictions are separate contracts and stay untouched.
Unsupported dynamic source, ambiguous references and colliding keys/IDs are
refused before writing. Confirmed project repair writes the listed files as one
transaction; undo/redo checks source snapshots and uses the same transaction.
The TUI's actor-only freeze retains deferred save when no reference files need
repair. Validation reports unresolved actor references, including startingParty.
The frozen ID preserves existing name-based saves; changing the identity
instead requires an explicit content migration. The Editor assigns IDs to new
actors and keeps established IDs read-only. Renames change only `data.name`.
Skits, dialogue presentation, battle artwork, results portraits and current saves
use the explicit id; a display-name change requires no artwork rebinding.

A tombstone means removal was deliberate. If a loaded save still contains
that identity, loading fails and asks for a project content migration. A game
migration may replace, remove, compensate, or preserve an inert marker; the
generic engine does not choose.

For one-shot IDs, an explicit `one_shot_events` alias can change the full
identity. A `maps` alias also rewrites only the map portion of
`mapId:marker`, leaving the marker unchanged.

## Project-owned campaign state

`Core\ProjectState` extends the existing `GameConfig` snapshot and save envelope;
there is no second file, manager, global state singleton or game-specific schema.
An absent `projectState` field in an older save becomes `[]`. Explicit null or
malformed data is refused rather than treated as an older save. No container
schema increment is needed for this optional field.

```php
'projectState' => [
    'studio/progress' => ['version' => 2, 'data' => ['resourceId' => 'stable-id']],
],
```

A project bootstrap registers `ProjectStateParticipantInterface` policies on its
Game before the first restoration or mutation:

```php
$game->registerProjectStateParticipant(new ProgressSavePolicy($catalog));
$states = $game->getProjectStateRegistry();
$progress = $states->getState('studio/progress');
$states->replaceStates(['studio/progress' => $nextProgress]);
```

The participant exposes stable read-only `namespace` and `version` properties,
`migrateState(array $data, int $fromVersion): array`, and
`validateState(array $data): void`. Game owns their schema, sequential migration
policy, stable content references and reference validation. Namespace/version
must remain unchanged after registration. Duplicate namespaces, late
registration and future saved versions are refused. A different implementation
class in a later build may implement the same namespace; class names, display
names and artwork bytes are not saved identity.

Both callbacks must be pure: they may inspect current project definitions but
must not mutate runtime stores, read the previous campaign, perform IO, grant
starter progress or execute data stored in a save. Registry re-entry from these
callbacks is refused. The Engine cannot undo arbitrary project side effects,
so projects must not put them in these callbacks. `validateState([])` must accept
no recorded progress. Migration is not called when a section is absent. A
separately authored starter grant and exactly-once receipt are Game behavior,
not a default in this contract.

The registry is the single owner of detached plain-data project state. Projects
may build domain models from these values, but must commit their durable state
through `replaceStates()` rather than retain a second mutable save store.
`replaceStates()` validates the whole candidate before committing any namespace;
it supports multiple registered namespaces in one update. It does not transact
external inventory/currency or other mutable services. Game's ownership service
must validate and construct those complete operations before committing them.

`prepareSnapshot()` migrates and validates all participants without changing live
state. `restoreSnapshot()` swaps the complete prepared state once. During field
configuration, `runWithSnapshot()` restores before campaign consumers run and
rolls project state back if field configuration throws, including any project
updates made during that failed configuration. It is not a transaction over the
existing Engine map, party, UI or external services. Nested campaign restoration
is refused. Every successful configure replaces all namespaces, so loading a
different campaign or configuring a new game cannot retain old project progress.
`captureSnapshot()` feeds the real `GameScene::createSnapshot()` used by numbered,
quick and autosaves; captures do not retain PHP references to mutable input.

Unregistered sections are structurally validated and retained unchanged in the
same snapshot. `$states->diagnostics` names each unavailable provider and version;
such sections cannot be read as active progress or changed through the registry.
A future build must register the provider before loading if it wants to use that
state. Malformed unknown data fails loading instead of being silently deleted.

Generic admission limits apply to the entire extension: at most 64 namespaces,
100,000 data values, 32 nested levels below each payload root, and 1 MiB PHP
serialized size. Namespaces are lowercase ASCII vendor/name paths, at most 128
bytes; each section contains exactly a positive integer `version` and array
`data`. Data supports arrays with string/integer keys, strings, integers, finite
floats, booleans and null. Objects (including enums), resources, closures,
non-finite numbers and cycles are refused. These are safety limits, not suggested
project schema or artwork metadata. The enclosing legacy object serialization
retains the trusted-save/class-layout constraints described above.

`SaveCompatibilityPipeline` accepts the Game registry as its optional named
`projectStateRegistry` constructor argument. SaveManager supplies
`$game->getProjectStateRegistry()` so project policies cover writers, direct
readers and slot/Continue discovery. Envelope creation prepares a detached
configuration without mutating the caller or live campaign. Scene configuration
restores the prepared data before campaign consumers run. Tooling without
providers intentionally preserves structurally
valid namespaces rather than pretending it can validate project references.

The active-event save guard is unchanged. This extension does not serialize event
continuations, permit arbitrary mid-event saving, define checkpoints or invent
active-match resume. Game must explicitly agree supported save boundaries with
the scene/event lifecycle before persisting such a phase.

`ProjectStateTest` uses only synthetic policies/data to exercise absent old saves,
replaceable providers, isolation, all data bounds, detached references, migration
and validation failure, configuration rollback, unknown-provider round trips,
shared snapshot surfaces and the unchanged active-event guard.

## Persistent character states

`Character::toArray()` now serializes only `StateInstance`s whose authored
`State::$persistsAfterBattle` is true. The current model has two runtime
values worth preserving: state ID and `remainingTurns`. It has no stack,
potency, or source fields, so WP1 invents none. Loading resolves the state ID
through the compatibility manifest and then `StateRegistry`; missing states
fail instead of becoming arbitrary or silently disappearing. Non-persistent
battle states are never added to the saved character array.

## Roadmap reconciliation matrix

| WP1 requirement | Existing class or system | Existing phase | Current behavior before WP1 | Required extension | Regression risk | Planned/proving tests |
| --- | --- | --- | --- | --- | --- | --- |
| Schema versioning | `SaveManager` | Phase 1/6 | Unversioned `slot`/`config` root | Version-1 envelope and sequential schema registry | Every save reader | Envelope and future/missing-schema tests |
| Content versioning | project PHP data | Production hardening | No explicit version | Project manifest and content migration interface | Game rules leaking into engine | Real Last Legend `0 -> 1` test |
| Legacy detection | `SaveManager::loadSaveFile()` | Phase 1 | Shape assumed | Detect absent envelope as 0/0 | Existing saves rejected | Frozen WP0 binary |
| Old `events` migration | `GameScene::configure()` | Phase 1 | Ad hoc fold during scene configuration | Move once into schema `0 -> 1` | Lost/duplicated story flags | Migration-order and fixture assertions |
| `GameState` | `Core\GameState`, `GameConfig::$gameState` | Phase 1 | Plain arrays already persist | Resolve maps/events/story IDs inside the same array | World changes after load | Switch/variable/story/visited/one-shot integration assertions |
| `QuestLog` | `QuestLog`, `GameConfig::$questLog` | Phase 2 | Active progress/completed IDs persist | Resolve quest keys in place | Journal regression | Active/completed integration assertions |
| Party | `Party`, `ItemList` | Phase 1/3 | Direct object graph, ordered roster | Preserve graph and resolve persisted identities | Frontline/reserve change | Four roster/three frontline assertions |
| Character | `Character::__serialize()` | Phase 3/5 | Names/books/summons persist; content resolves immediately | Defer content-sensitive load inside save pipeline | Skills or role data dropped | Learned ability/spell/summon tests |
| Persistent states | `HasStates`, `StateRegistry` | Phase 3 | Battle persistence exists; save persistence missing | ID/remaining-turn serialization for persistent definitions only | Transient states leak | Character, party, full-save, alias/tombstone tests |
| Achievements | `AchievementManager`, `GameConfig::$achievements` | Phase 6 | ID/timestamp array persists | Apply achievement aliases/tombstones | Unlock records lost | Last Legend exact-array assertion |
| Bestiary | `Bestiary`, `GameConfig::$bestiary` | Phase 6 | Enemy-name counters persist | Apply enemy aliases/tombstones | Records reset | Seen/defeated count assertions |
| Quicksave | `SaveManager::quickSave()` | Phase 6 | Separate file, shared writer | Shared envelope/pipeline | Semantic binding or capability regression | Quick write/load and legacy quick tests |
| Autosave | `SaveManager::autoSave()` | Phase 6 | Single logical slot, atomic writer | Shared envelope/pipeline | Disabled capability or corrupt legacy selection | Repeated writes/one file, preserved legacy and option combinations |
| Title Continue | `TitleScene`, `ContinueGameCommand`, `getSaveSlots()` | Phase 6 | Discovers numbered slots/latest loadable files | Compatibility-aware shared loader and readable slot failures | Continue disabled/crash | Slot discovery/latest-loadable integration assertions |
| Project identity | `ichiloto.json`, workspace loader | Project metadata | Display name only in Last Legend | Stable canonical `id` in envelope | Cross-game load | Wrong-project rejection |
| Save metadata | `SaveSlot` | Phase 6 | Location/leader/playtime/path object | Preserve unchanged inside payload | Slot UI regression | Slot-summary and surface tests |
| Compatibility aliases | New resolver over existing state | Production hardening | Renames unsafe | Project-controlled exact mapping over persisted domains | Partial mapping silently loses data | Cycle/contradiction/map-event/state tests |
| Compatibility tombstones | New manifest rule over existing state | Production hardening | Removed references unresolved ad hoc | Controlled failure requiring game migration | Silent deletion | Runtime and editor tombstone tests |

No parallel save manager, world-state store, quest log, party, state
collection, achievement store, bestiary, quicksave, or autosave was created.
