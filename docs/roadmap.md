# Ichiloto 2.0 — State of the Engine & Development Roadmap

*Assessment date: August 2026. Compiled from a four-track survey of the field/world
layer, battle layer, progression/economy/persistence layer, and core/UI/tooling.*

## The one-paragraph diagnosis

Ichiloto's **presentation layers are genuinely mature** — the terminal core, map
rendering, menu suite, battle staging, summon cinematics, audio, save slots,
title flow, and the editor's map/event tooling are polished and largely
complete. What is missing is the **simulation and persistence core underneath
them**: battles look like a JRPG but have no status effects, elements, crits,
guard, escape, encounters, or real enemy AI; the world looks like a JRPG but
has no persistent flags, no quests, no NPCs, no conditions, and no cutscenes;
progression looks like a JRPG but classes are unwired and leveling up is
silent. A striking amount of this is **designed but never connected** — the
enemy-AI schema, the story-flag store, the class data, the encounter tile
type, and the guard/escape vocabulary all exist and have no consumer. The
fastest path to "full 2D JRPGs" is mostly *wiring what is already designed*.

## Maturity map

**Polished / complete**
- Terminal core: Game loop, crash-safe cleanup, Console cell buffer,
  width-stable glyph rendering, input, camera
- Field basics: MapManager (3-file map format, marker events, collision,
  scrolling), Player movement/sprites/interaction
- Menu suite: Main, Items, Equipment (incl. optimize), Abilities, Magic,
  Summons (codex + assignment), Status, Save, Shop UI, Config
- Battle presentation: command/target flow, ATB gauges (wait mode), cinematic
  turn staging, animation & summon-cutscene playback, result reveals, pacing
- Summon pipeline: timeline compiler/player, wielder policies, per-role vocab
- Audio: multi-backend playback, system sounds, volume handling, orphan-proof
  process lifecycle
- Save system: slots, compressed format, title continue menu
- Tooling: `new`/`play`/`edit`/`generate:figlet` CLI; editor map painting,
  event authoring (5 trigger types), 5 of 15 database categories, animation
  editor with live preview

**Partial**
- FieldState (ships debug hotkeys as gameplay; in-game map TODO)
- Rewards (drop-rate bug; exp granted silently, undivided)
- Ability/spell learning (model complete; two requirement types unsatisfiable)
- Shop model (no sell validation, key items sellable, infinite stock)
- Inventory (stack bugs, store aliasing)
- Vocab/messages (17 call sites; most scaffolded keys unread; no formatter)
- Options (two divergent settings managers)

**Stub / absent**
- CutsceneState, DialogueState, MapState, OverworldState (empty; never entered)
- Status ailments, buffs/debuffs, elements, crits, accuracy/evasion rolls,
  Guard, Escape, multi-target resolution, enemy AI, random encounters,
  boss mechanics, battle events
- Switches/variables/quest state (read-side only; `recordStoryEvent` uncalled)
- NPC actors, event conditions/pages, field cutscene scripting, skits
- Class wiring (`classes.php` never loaded — every character is 'Hero'),
  level-up event, learn-on-level
- Achievements (fake demo only; `AchievementEvent` class doesn't exist),
  bestiary, localization, credits, autosave (scaffold only), input rebinding
- CLI `battle` (stub), `generate:actor`/`generate:map` (broken — never write)

## Known defect list (fix regardless of roadmap)

| Area | Defect |
| --- | --- |
| Inventory | `removeItems()` checks the argument's quantity, not the stack's — deletes whole stacks; `addItems()` hardcodes `+= 1` |
| Accessory | `fromArray()` drops `parameterChanges` — all data-built accessories are stat-less |
| ItemStore / ChestOpeningAction | No `clone` — party inventory aliases catalog singletons |
| BattleRewards | Failed drop roll falls through to `array_rand` — enemies with drop lists always drop |
| Shop | Sell path has no ownership validation; key items and equipped gear sellable; float totals into int debit/credit |
| Skills | `HPDamageSkillEffect` lacks a damage floor — high defence heals the target; `eval()` in formulas |
| Party | `fromArray()` would feed a `location` entry to `Character::fromArray` |
| Core | `Game::syncScreenSize()` throttle vars aren't static — shell probe every frame |
| Events | `EventType` enum cases resolve `::class` against nonexistent classes (string landmine) |
| CLI | `generate:actor` throws (no `configure()`) and never writes; `generate:map` never writes and emits malformed PHP |
| Field | `MapTrigger` dead code with broken serialization |
| Input | `InputConfig::persist()` writes JSON into a PHP file |

## The plan

Ordered by dependency and player impact. Phase 1 is the keystone: nearly every
story feature depends on persistent game state existing.

### Phase 0 — Foundation repairs ✅ *shipped 2026-08*
> Status: all 13 defects in the table above are fixed with 16 new unit tests
> (inventory stacking, accessory stats, store cloning, drop rates, shop sell
> validation + key-item exclusion, party hydration, size-probe throttle,
> damage floors, EventType imports + a real AchievementEvent, both CLI
> generators now write correct files, MapTrigger round-trips and is marked
> deprecated, InputConfig persists real PHP with enum-case export). Remaining
> dead surface (`BattleManager`, `Battle/UI/States/PlayerActionState`,
> `TurnBasedEngine::$turnQueue`, `Skill::execute()` stubs) is quarantined for
> Phase 3's battle work.

### Phase 1 — The persistence spine (switches, variables, world state) ✅ *shipped 2026-08*
> Status: `Core\GameState` ships switches, variables, story events, and
> per-map/per-marker completion, serialized through `GameConfig::$gameState`
> into saves (legacy saves migrate their `events` list on load). Every
> `EventTrigger` accepts `conditions` (switch/event/variable/item tests, each
> negatable) and `sets` (switch/event/variable writes on completion), carries
> its map id + event-layer marker, and one-shot triggers persist their
> completion — verified live: a looted chest stays looted across a map
> round-trip and the party's gold reflects a single loot. `Player` gates
> trigger entry on availability so conditional events appear/disappear as
> state changes. 14 new unit tests in `GameStateTest`. See
> [persistence.md](persistence.md) for the authoring guide.

The single highest-leverage build. A `GameState` store carried by
`GameScene`/`GameConfig` and serialized into saves:
- **Switches** (named booleans) and **variables** (named ints/strings)
- **Story flags** — the existing `storyEvents` list, finally with writers
- **Self-state** — per-map, per-event persistent completion ("chest 3 on
  map home is looted")
- Trigger integration: every `EventTrigger` gains optional `conditions`
  (switch/flag/item/variable tests — RPG-Maker "event pages" lite) and
  optional `sets` (state written on completion)
- Chest/one-shot trigger persistence through save/load
- This immediately makes the authored-but-dead `requiredEvents` ability gates
  satisfiable and conditional world design possible.

### Phase 2 — Quest system ✅ *shipped 2026-08*
> Status: the `Quests` namespace ships `Quest`/`QuestObjective` definitions
> (loaded from `assets/Data/quests.php`), a serializable `QuestLog` riding
> `GameConfig::$questLog` into saves, and a `QuestManager` that advances
> objectives from real game moments: dialogue completion (talk-to; this also
> fixed `ShowDialogAction` never calling `complete()`, which had left every
> dialogue trigger's `sets` dead), inventory changes (collect, synced at the
> `Inventory` level so shop purchases, chest loot, drops, and item use all
> count), battle victory (defeat, per enemy name), map loads (reach-map),
> and `GameState` writes (flag). Quests are granted through trigger `sets`
> (`['type' => 'quest', ...]`), gated by prerequisites (including
> quest-status conditions), and completion grants gold/exp/item rewards,
> records `quest_completed:<id>`, and announces through the notification
> system's new QUEST channel. The journal (main-menu Quests entry, states
> `QuestMenuState`) shows active/completed tabs with per-objective progress.
> Verified live end-to-end in last-legend: Mom's dialogue grants Breakfast
> Duty → reach-town objective pings → buying the S-Mana completes the quest
> mid-shop with the 200 G reward (balance 400 → 580). 9 new unit tests.
> See [quests.md](quests.md). The editor's quests database category also
> shipped (list/objectives/rewards/preview panes, lossless round-trip,
> Save All + dirty markers + identity-pinned undo integration).

A pure consumer of Phase 1:
- `assets/Data/quests.php`: id, name, description, giver, steps/objectives
  (talk-to / collect / defeat / reach-map / flag), rewards, prerequisites
- `QuestLog` state on the party; progress hooks from the systems that already
  emit the relevant moments (item obtained, troop defeated, flag set, map
  entered)
- Journal screen in the main menu (list → detail, active/complete tabs),
  notifications on accept/progress/completion
- Editor: quests database category

### Phase 3 — Battle simulation depth ⏳ *in progress 2026-08: items 1–5 shipped*
> Status: **States** (`Entities\States`): `State` definitions from
> `assets/Data/states.php`, `HasStates` on characters and enemies with
> resist-table multipliers, `AddStateSkillEffect`/`RemoveStateSkillEffect`,
> round ticks with popups and expiry alerts in `TurnResolutionState`,
> prevents-action states consume turns, non-persistent states clear at
> battle end (demo: Poison + Stun, Venom Strike + Cleanse skills).
> **Buffs/debuffs** (`HasStatStages`): ±4 stages at 25% per stage,
> `ModifyStatStageSkillEffect`, composed through `BattlerBattleView` so
> damage formulas, basic attacks, and turn-order speed all see
> equipment-adjusted, stage-multiplied stats (this also fixed formulas
> ignoring equipment); stages reset at battle end; the dead per-round
> `resetBuffsAndDebuffs()` stub is gone (demo: War Cry). **Guard/Escape**:
> new top-level commands — Guard halves incoming damage until the
> character next acts; Escape rolls party-vs-troop speed (5–95%), ending
> the battle rewardless on success and costing the turn on failure.
> **Elements**: battlers carry affinity tables (2.0 weak, 0.5 resist, 0
> null, negative absorbs — an absorbing hit heals); `SkillEffect::$element`
> now scales final damage with WEAK!/RESIST/NULL/ABSORB popups; the four
> demo summons carry their elements and every demo enemy has affinities.
> **To-hit & crits**: skills with `SkillInvocation::$accuracy` > 0 roll to
> hit (accuracy + grace − evasion, clamped 5–95; 0 stays a guaranteed
> action so existing data is unaffected), basic attacks land at 95% before
> grace/evasion, crits roll at 5% + grace/10 for ×1.5 damage with a
> CRITICAL popup; the MISS popup fires on any whiff.
> Also this phase (user-reported): insufficient MP now blocks skill/summon
> selection immediately — dimmed submenu rows plus an alert — instead of
> fizzling after the announcement. And the three long-flaky `SkillTest`
> RNG cases are fixed at the root (they re-rolled `getValue()` after
> `apply()` against float bounds the engine floors to ints, and healed into
> the HP ceiling) — the suite is now fully green: 217 passed, 0 failed.
> Items 6–9 below remain.

Turn the polished stage into a real fight. Roughly in order:
1. **States system** — State entity, per-battler state list, durations/ticks,
   inflict chances on effects, resist tables, popups, cure effects (make the
   Antidote true), poison-on-field persistence
2. **Buffs/debuffs** — stat stages, the `resetBuffsAndDebuffs()` TODO
3. **Guard and Escape** commands (vocab + sounds already exist)
4. **Elements** — wire `ElementType` + `SkillEffect::$element` to affinity
   tables (weak/resist/null/absorb) with damage multipliers and popups
5. **To-hit and crits** — accuracy vs evasion rolls, crit rate/multiplier,
   MISS/CRITICAL popups (the popup layer is ready)
6. **Multi-target** — implement `ItemScopeNumber::ALL`, remove the
   first-target collapse, add all-target highlight in the targeting UI
7. **Enemy AI** — an evaluator for the already-modeled
   `ActionPattern`/`ActionCondition` schema (rating-weighted, condition-gated)
8. **Random encounters** — step accumulator, per-region/terrain troop tables,
   the `ENCOUNTER` tile type, preemptive/back-attack for the traditional
   engine, repel/lure hooks
9. **Level-up beat** — detect level deltas in battle results; announce levels,
   stat gains, and learned skills; consume `CharacterRole::$skillsToLearn`
10. **Boss support** — `isBoss`/no-escape flag, and an executor for the
    already-threaded `Troop::$events` (HP-threshold phases, mid-battle
    dialogue)
11. Honor the inert skill fields (invocation message templates, repeat,
    speed, accuracy, cooldown, required weapons); replace formula `eval()`

### Phase 4 — World & story presentation (cutscenes, NPCs, skits)
- **Event-command interpreter** — the generic cutscene engine `CutsceneState`
  was meant to host: a data-driven command list (show text, move actor, wait,
  fade, pan camera, play sound/music, set switch/variable, conditional
  branch, start battle, give item). This is deliberately *not* the summon
  timeline system — cutscenes are logic-driven, not frame-driven — but the
  summon player's transition/title machinery is reusable for staging.
- **NPC field actors** — a real NPC entity: sprite, facing, movement profile
  (fixed/wander/patrol route), talk trigger, per-page conditions from
  Phase 1. Party followers become possible on the same machinery.
- **Skits** — Tales-style optional conversations: authored beat lists
  (speaker, line, emote/color), skit points gated on map + flags, an
  availability notification + hotkey, and a compact overlay presentation
  (named, colored dialogue exchanges). Data: `assets/Data/Skits/*.php`;
  states: a lightweight overlay rather than a full scene.
- **DialogueTree wiring** — the branching dialogue model exists unused;
  connect choices to switches/variables so conversations can matter.

### Phase 5 — Progression wiring
- **Classes** — `ClassStore` over `classes.php`; actors reference a class by
  name; `Character::fromArray` resolves it into a real `CharacterRole` with
  the authored curves; recalculate on role change; class-change mechanics
- Learn-on-level from `SkillToLearn` (also consumed by Phase 3's level-up
  beat); a training mechanic for `trainingHours` or removal of the field;
  spell/story-flag requirement parity
- **Key items** — finish `ViewKeyItemsMode`, add a `has key item` trigger
  condition, exclude from shops
- Item-use quantity prompt; equipment class restrictions via the dead
  `WeaponType`/`ArmorType` enums; equipment stat-delta preview

### Phase 6 — Shipping polish
- **Achievements** — real registry, definitions file, unlock persistence in
  saves, list UI; create the missing `AchievementEvent`
- **Bestiary** — seen/defeated tracking over `EnemyStore`, codex UI (the
  summon codex is the pattern)
- **Autosave/quicksave** — the directory scaffolding exists; add the writer
  and slot handling
- Overworld & in-game map states; scene fade/wipe transitions; non-blocking
  modal/animation timers; SFX coverage completion (notifications, level-up,
  ESCAPE); unify the two settings managers; accessibility options (reduced
  motion, disable blink, high-contrast palettes); input rebinding UI (and fix
  `InputConfig::persist`); credits scene; localization foundation (message
  catalog + `%1` formatter + extraction of hardcoded literals — the
  scaffolded `messages.*` tree already anticipates this)

### Phase 7 — Tooling & documentation
- Editor: implement the 10 stub database categories (items, weapons, armors,
  enemies, troops, states, terms, common events, tilesets, types); quest and
  skit editors; undo/redo; playtest-from-editor
- CLI: implement `battle` as a battle-sim test harness (invaluable while
  building Phase 3); fix/finish the generators; add `validate`
- Website: pages for every shipped system (audio, animations, notifications,
  save, menus, config reference, editor, CLI reference) — currently 35% of
  the doc corpus is Summons

## Sequencing notes

- Phase 1 before everything story-shaped; Phases 2 and 3 can proceed in
  parallel once it lands (different subsystems, different files).
- Phase 3's encounter work (item 8) unlocks real playtesting of everything
  else — consider pulling it forward.
- The CLI `battle` harness (Phase 7) is worth building at the *start* of
  Phase 3, not the end: iterating on states/elements/AI without walking to an
  encounter every time pays for itself immediately.
- Test debt: `MapManager` (795 lines, untested) and the event trigger
  framework should gain tests when Phase 1 touches them.
