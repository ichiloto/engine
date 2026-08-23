# Battle-entry rules

Projects can apply temporary, actor-specific stat stages at the beginning of
a battle by defining `assets/Data/battle-entry-rules.php`. The rules use the
same world-condition and world-write vocabularies as events, quests, NPCs and
story scripts; they do not require a `BattleScene` subclass.

If the file does not exist, battle startup is unchanged.

## Battle classification

A troop may declare its classification in `assets/Data/troops.php`:

```php
[
  'id' => 'training-formation',
  'name' => 'Training Formation',
  'classification' => 'ordinary',
  // existing troop fields...
]
```

The allowed values are `ordinary` and `boss`. Omitting the field means
`ordinary`, preserving existing project data. Classification is explicit: the
engine never guesses it from names, enemies, music, rewards, escape policy or
other settings. An unsupported value stops loading with a diagnostic that
names the troop source and `classification` field.

## Rule file

The file returns a `rules` list:

```php
<?php

return [
  'rules' => [
    [
      'id' => 'formation.entry-speed',
      'priority' => 20,
      'classification' => 'ordinary',
      'actors' => [
        ['actor' => 'actor.scout', 'presence' => 'active'],
        ['actor' => 'actor.medic', 'presence' => 'reserve'],
      ],
      'conditions' => [
        ['type' => 'switch', 'name' => 'formation_ready', 'value' => true],
      ],
      'effects' => [
        [
          'type' => 'stat_stage',
          'actor' => 'actor.scout',
          'stat' => 'speed',
          'delta' => 1,
        ],
        [
          'type' => 'stat_stage',
          'actor' => 'actor.medic',
          'stat' => 'grace',
          'delta' => -1,
        ],
      ],
      'writes' => [
        ['type' => 'variable', 'name' => 'formation_step', 'op' => 'add', 'value' => 1],
      ],
    ],
  ],
];
```

### Fields

| Field | Required | Shape and meaning |
|---|---:|---|
| `id` | yes | Non-empty stable rule ID, unique within the file. |
| `priority` | no | Integer; defaults to `0`. Lower priorities run first. Equal priorities retain declaration order. |
| `classification` | no | `ordinary` or `boss`; defaults to `ordinary`. |
| `actors` | yes | Non-empty list of actor-presence predicates. Every predicate must match. |
| `conditions` | no | List using the shared world-condition vocabulary; defaults to an empty list. |
| `effects` | yes | Non-empty list of typed temporary effects. |
| `writes` | no | List of reversible durable writes; defaults to an empty list. |

An actor predicate has a stable `actor` ID from `actors.php` and a `presence`
of `active`, `reserve`, or `any`. Presence is captured when the battle begins;
later party changes cannot change whether the predicate matched.

The first effect type is `stat_stage`. It requires a stable `actor`, one of
the canonical stage-capable stats (`attack`, `defence`, `magicAttack`,
`magicDefence`, `speed`, `grace`, or `evasion`), and a signed integer `delta`.
The normal stage bounds of -4 through +4 still apply.

`conditions` accept the shared `switch`, `event`, `variable`, `item`,
`key_item`, and `quest` shapes documented by the event and quest systems.
`writes` accept `switch`, `event`, and `variable`. Quest acceptance is not
available here: its confirmation/session side effects cannot be rolled back,
so the atomic rule boundary rejects it and directs authors to a reversible
world flag instead.

## Lifecycle and atomicity

The engine captures one immutable entry context and evaluates rules after the
battle exists but before either battle engine begins turn/ATB calculation,
action resolution, or player input. Random encounters and scripted
`start_battle` encounters use the same path, as do active-time and traditional
battles.

Each matching rule is one transaction. The engine resolves conditions and
entry actors, validates all effects and writes, applies temporary effects,
then commits writes. If an effect or write fails, that rule's earlier stage
changes and world writes are restored. A successful rule is recorded against
the battle execution before later rules run, so lifecycle re-entry cannot
apply it twice.

Stat stages use the existing character battle-stage layer. They affect the
first action and are cleared from active and reserve members by ordinary
battle cleanup on victory, defeat or retreat. They are not serialized as
permanent growth. Durable world writes continue through normal save/load.

Malformed rules fail closed during project data loading. Diagnostics identify
the project file, rule ID (or source position), invalid field and offending
actor, stat, classification or write.

## Editor authoring handoff

An editor surface should write the schema above without adding a parallel
runtime model. It needs:

- a stable rule-ID field with uniqueness validation;
- an optional integer priority field and optional classification picker
  (`ordinary`, `boss`);
- repeatable actor predicates with an actor-store picker and presence picker
  (`active`, `reserve`, `any`);
- the existing shared world-condition editor;
- repeatable typed effects, initially `stat_stage`, with actor and canonical
  stage-capable stat pickers plus a signed integer delta;
- the existing world-write editor restricted to reversible `switch`, `event`
  and `variable` writes for this surface;
- deterministic display in priority then declaration order; and
- validation messages that preserve file, rule and field context.

`actors` and `effects` are required and non-empty. `priority`,
`classification`, `conditions`, and `writes` are optional with the defaults
described above. The editor should surface invalid references before save,
while runtime validation remains authoritative and fail-closed.
