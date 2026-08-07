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
  'transitionIn' => ['type' => 'fadeToBlack', 'durationMs' => 450, 'color' => 'red'],
  'transitionOut' => ['type' => 'fadeFromBlack', 'durationMs' => 300, 'color' => 'red'],
  'effectTiming' => ['mode' => 'cue', 'cueId' => 'apply_ifrit_damage'],
  'targetPresentation' => ['mode' => 'full_screen', 'showCasterNameBanner' => true],
];
```

- `name` is the summon's identity (the "[ IFRIT ]" title card).
- `moveName` is what the battle log announces when the summon acts. Without
  it, the summon name is announced instead.

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
  FFX-style game where only one character can summon.

Tenancy:

- `shared` — any number of eligible members may hold the summon at once.
- `exclusive` — only one member may hold it at a time; it must be
  unassigned before another member can take it.

As soon as a summon declares `wielders`, it becomes **assignment-based**: a
character must both satisfy the eligibility rules *and* currently hold the
summon for it to appear in their battle menu.

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

if ($party->assignSummon($definition, $character)) {
  // assigned — appears in this character's battle menu
}

$party->unassignSummon('ifrit', $character);
$party->getSummonHolders('ifrit');          // Character[]
$party->canAssignSummon($definition, $character);
```

Assignments serialize with the character, so they survive saving and loading.

### The in-game Summons menu

Any project with at least one authored summon gets a summon-management entry
in the in-game main menu (labelled from the project's summon vocabulary —
"Summons", "Petitions", …). Players pick a character and see every summon
with its move name, wielder rules, and status:

- `[Open to all]` — no wielder policy; the whole party can call it.
- `[Assigned]` — held by this character; confirm releases it.
- `[Available]` — eligible; confirm assigns it.
- `[Held by <name>]` — an exclusive summon bound to another member; it must
  be released by its holder first.
- `[Not eligible]` — the character fails the summon's role or name rules.

Tab cycles through party members. The screen is the natural home for future
summon mechanics (growth, junctioned skills and magic attributes), so it is
always available — it only disappears when the project has no summons at
all.

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
