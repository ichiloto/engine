# Persistence: switches, variables, and world state

The engine carries a `GameState` store (`Ichiloto\Engine\Core\GameState`) on
every `GameScene`. It is the single home for everything the world needs to
remember — and it is serialized into save files automatically, so anything
written to it survives save/load with no extra work.

It tracks five kinds of state:

| Kind | Shape | Typical use |
|---|---|---|
| **Switches** | named booleans, default `false` | "drawbridge lowered", "shop unlocked" |
| **Variables** | named ints/floats/strings, default `0` | donation totals, reputation, titles |
| **Story events** | an append-only list of names | "met_the_king", ability `requiredEvents` gates |
| **Event completion** | per-map, per-marker flags | "chest B on happyville/home is looted" |
| **Visited maps** | map-ID keys | region-map discovery and revisited places |

## Reading and writing from code

```php
$state = $gameScene->gameState;

$state->setSwitch('drawbridge');            // true
$state->getSwitch('drawbridge');            // false when never set

$state->setVariable('donations', 40);
$state->addToVariable('donations', 10);     // 50
$state->getVariable('title', 'none');       // default when never set

$state->recordStoryEvent('met_the_king');   // idempotent
$state->hasStoryEvent('met_the_king');
```

`GameScene::recordStoryEvent()` / `hasStoryEvent()` delegate to the store, so
existing call sites keep working. Old save files that carried a plain
`events` list migrate automatically: the schema-0-to-schema-1 migration folds
the list into the store's story events before scene configuration. There is
no second legacy-events path in `GameScene`.

## Save versions and compatibility

`GameState` remains the single world-state store. The versioned save work
wraps the existing `GameConfig` snapshot; it does not introduce another
state model. Numbered slots, quicksave, autosave, title Continue, quests,
achievements, and bestiary all use the same `SaveManager` compatibility
pipeline.

See [Versioned save compatibility](save-compatibility.md) for the `IED1`
envelope, legacy version-0 detection, engine versus project versions,
migration order, aliases, tombstones, persistent character states, and the
PHP-object-serialization constraints.

## Save safety during story events

Resumable story-event sessions are intentionally not serialized. While an
`EventInterpreter` session is active, the shared `SaveManager` writer rejects
numbered/manual saves and quicksaves with a clear
`ActiveEventSaveException`. A map transfer inside the script still uses the
normal transfer path, but any transfer-triggered autosave is deferred until
the entire script and its trigger completion writes succeed. Failed scripts do
not flush that deferred autosave.

Consequently, terminating the process during a story event leaves the most
recent stable save untouched. Loading starts from that stable snapshot rather
than a partly applied command sequence. This production-hardening extension
does not add active event sessions to the WP1 envelope. See
[Story events and resumable cutscenes](story-events.md) for the lifecycle,
movement-route, transfer, and battle-continuation contracts.

## Conditional events (`conditions`)

Every event in a map's `*.data.php` may declare `conditions`. The trigger only
activates while **all** of them pass — walk onto it while a condition fails
and nothing happens; flip the state and the same tile comes alive. This is
RPG-Maker "event pages" lite.

```php
'C' => [
  'class' => 'Ichiloto\\Engine\\Events\\Triggers\\DialogueEventTrigger',
  'conditions' => [
    ['type' => 'switch',   'name' => 'gate_open'],
    ['type' => 'event',    'name' => 'met_the_king'],
    ['type' => 'variable', 'name' => 'donations', 'op' => '>=', 'value' => 100],
    ['type' => 'item',     'name' => 'Rusty Key'],
  ],
  'data' => [ /* ... */ ],
],
```

- `switch` — passes when the named switch is on.
- `event` — passes when the named story event has been recorded.
- `variable` — compares with `op` (`==`, `!=`, `>`, `>=`, `<`, `<=`)
  against `value`. `op` defaults to `==`.
- `item` — passes when the party holds the named item.
- Any condition takes `'negate' => true` to invert it ("only while the
  bridge is NOT destroyed").

## Writing state on completion (`sets`)

Declare `sets` and the trigger writes them the moment it completes:

```php
'B' => [
  'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ChestEventTrigger',
  'sets' => [
    ['type' => 'switch',   'name' => 'chest_opened'],
    ['type' => 'event',    'name' => 'first_loot'],
    ['type' => 'variable', 'name' => 'chests_opened', 'op' => 'add', 'value' => 1],
  ],
  'data' => ['lootType' => 'gold', 'quantity' => 1000, 'reusable' => false],
],
```

Variable writes take `op` `set` (default) or `add`.

## One-shot persistence

A trigger whose `data` says `'reusable' => false` completes **permanently**.
Its identity is its map id (the map's asset path, e.g. `happyville/home`)
plus its letter marker in the `*.event.php` overlay — both wired up by the
engine automatically. When the map is re-entered (or a save is loaded), the
trigger is restored as already-complete without re-running its `sets`: the
chest stays looted, the gold is only granted once.

Reusable triggers (`'reusable' => true`) never persist completion and fire
every time their conditions pass.

## What this unlocks

- Chests, one-time pickups, and one-time cutscenes that stay done.
- Doors/NPCs/dialogue that appear, change, or vanish as flags flip.
- The `requiredEvents` gates on abilities are now satisfiable — record the
  story event from any trigger's `sets` and the ability unlocks.
- The quest system (roadmap Phase 2) consumes exactly this state.
