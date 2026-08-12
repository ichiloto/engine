# Summons

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
  'characters' => ['Luna'],     // used when mode = characters
  'roles' => ['Summoner'],      // used when mode = roles
  'tenancy' => 'exclusive',     // 'shared' | 'exclusive'
],
```

Eligibility (`mode`):

- `all` — any party member may be assigned the summon.
- `roles` — only characters whose role name is listed in `roles`.
- `characters` — only the named characters. This is how you build an
  actor-signature summon or a named group of summoners.

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
| Character-signature summon | optional world gate | `characters` plus `exclusive` | start or script the named actor's assignment |
| Small named summoner group | optional world gate | `characters` plus either tenancy | assign within that authored group |

Omitting a policy is intentionally different from declaring a malformed one.
Omission retains the legacy open pool; malformed declared policies fail
closed and require authoring correction.

### Assigning summons

Grant starting summons in actor data:

```php
// assets/Data/Actors/Luna.php
'data' => [
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
