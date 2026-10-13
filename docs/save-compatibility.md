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

A step may declare position edits, `mapShifts`, `mapRemovals` and/or `relocations`,
instead of `class` (a class or declared edits, never both).
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

A step may combine `mapShifts`, `mapRemovals` and `relocations`; shifts apply
first, then removals, then relocations, each list in authored order regardless
of PHP key order. A move belongs to the step that introduced it: never add edits
to an earlier step, since saves already past that version will not run it again.

`ContentReferenceCategory` is the shared runtime/editor vocabulary: maps,
one-shot events, quests, actors, items, equipment, abilities, spells,
summons, states, story events, enemies, and achievements. Alias entries use a
list of `from`/`to` pairs so contradictory duplicate sources remain visible
to validation. Chains resolve deterministically; self-aliases and cycles are
invalid. No rename is inferred.

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
| Quicksave | `SaveManager::quickSave()` | Phase 6 | Separate file, shared writer | Shared envelope/pipeline | F5 saves unreadable | Quick write/load and legacy quick tests |
| Autosave | `SaveManager::autoSave()` | Phase 6 | Three rotating files, shared writer | Shared envelope/pipeline | Ring or transfer regression | Four writes/three files and all-file loads |
| Title Continue | `TitleScene`, `ContinueGameCommand`, `getSaveSlots()` | Phase 6 | Discovers numbered slots/latest loadable files | Compatibility-aware shared loader and readable slot failures | Continue disabled/crash | Slot discovery/latest-loadable integration assertions |
| Project identity | `ichiloto.json`, workspace loader | Project metadata | Display name only in Last Legend | Stable canonical `id` in envelope | Cross-game load | Wrong-project rejection |
| Save metadata | `SaveSlot` | Phase 6 | Location/leader/playtime/path object | Preserve unchanged inside payload | Slot UI regression | Slot-summary and surface tests |
| Compatibility aliases | New resolver over existing state | Production hardening | Renames unsafe | Project-controlled exact mapping over persisted domains | Partial mapping silently loses data | Cycle/contradiction/map-event/state tests |
| Compatibility tombstones | New manifest rule over existing state | Production hardening | Removed references unresolved ad hoc | Controlled failure requiring game migration | Silent deletion | Runtime and editor tombstone tests |

No parallel save manager, world-state store, quest log, party, state
collection, achievement store, bestiary, quicksave, or autosave was created.
