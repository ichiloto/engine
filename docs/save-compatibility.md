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
10. The pipeline validates the required `SaveSlot` and `GameConfig` objects
    and returns a `SavedGame`.

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
to 2, add exactly one `1 -> 2` class, retain all older steps, then raise
`contentVersion`.

`ContentReferenceCategory` is the shared runtime/editor vocabulary: maps,
one-shot events, quests, actors, items, equipment, abilities, spells,
summons, states, story events, enemies, and achievements. Alias entries use a
list of `from`/`to` pairs so contradictory duplicate sources remain visible
to validation. Chains resolve deterministically; self-aliases and cycles are
invalid. No rename is inferred.

A tombstone means removal was deliberate. If a loaded save still contains
that identity, loading fails and asks for a project content migration. A game
migration may replace, remove, compensate, or preserve an inert marker; the
generic engine does not choose.

For one-shot IDs, an explicit `one_shot_events` alias can change the full
identity. A `maps` alias also rewrites only the map portion of
`mapId:marker`, leaving the marker unchanged.

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
