# Phobia filters - authoritative plan

This plan lets players opt out of known phobia triggers. A player who says
they are afraid of, for example, snakes never meets a snake in a random
encounter, and anywhere a tagged creature must still appear (a scripted
battle, a bestiary entry, a creature on a map, a line of dialogue) its art,
name and text are replaced by neutral ones. Nothing tagged reaches the
player, on any surface. Phobia
filters are an **engine capability**: every game built on Ichiloto gets
them, and each game declares which phobias it offers. Related docs:
[persistence.md](persistence.md), [quests.md](quests.md).

## Principles

1. **The player's answer is about the player, not the playthrough.** It is
   a player setting, stored with the other player settings, and applies to
   every save on the install.
2. **Tags are data, declared once.** An enemy declares the phobia tags that
   describe it. A troop contains a phobia when any of its enemies does; no
   troop, map or battle restates what its enemies already declare.
3. **The game owns the vocabulary; the engine owns the behaviour.** A game
   declares which phobias it offers and how they are worded. The engine
   asks, stores, filters and replaces.
4. **Never break progression.** Filtering never makes a story, quest,
   achievement or completion goal impossible, and never produces an error
   the player sees. A map whose every encounter is filtered simply has no
   encounters.
5. **Terminal-first.** Replacement covers the terminal art as well as the
   graphical art; the terminal never shows what GPUI hides.
6. **Replacement is presentation, never identity.** Ids, stats, rewards,
   knowledge progress and save data are unchanged; only what the player
   sees and reads is replaced.

## Current state

- Enemies are constructed in the game's `enemies.php`; troops in
  `troops.php` list enemies by name. There is no tagging of any kind.
- Random encounters come from a map's weighted troop table, rolled by
  `EncounterManager::pickTroopName`. Scripted battles start troops by name
  through the `start_battle` command.
- Bestiary entries live in `knowledge.php` and link to enemies through the
  enemy's `knowledgeSubjectId`.
- Player preferences have their own per-install store,
  `.data/player-settings.json`, beside the saves.

## The model

### Vocabulary

A game declares its offered phobias in `assets/Data/phobias.php`: a stable
id, the question shown to the player, and a short description. A game that
declares none offers no phobia questions, and nothing in this plan applies.

```php
return [
  ['id' => 'snakes', 'question' => 'Are you afraid of snakes?',
   'description' => 'Snakes and serpent-like creatures.'],
];
```

### Tags

An enemy declares its tags by vocabulary id, for example
`phobiaTags: ['snakes']`. Validation rejects a tag the vocabulary does not
declare. A troop's tags are the union of its enemies' tags, derived, never
authored.

### The setting

The player's answers are a set of vocabulary ids stored in player settings.
They are asked once, at the start of a new game, when the install has not
answered yet: one question per offered phobia, with a plain yes or no.
The same choices are always available afterwards in the Config menu, and a
change takes effect immediately. Save files are never touched.

### Behaviour when a phobia is active

- **Random encounters.** A troop carrying an active tag leaves the weighted
  roll; the remaining weights renormalise. A table with nothing left yields
  no encounter.
- **Scripted battles.** The battle still happens with the same troop, stats
  and outcome. Each tagged enemy's art is replaced: its battle image in
  GPUI and its terminal sprite in the terminal.
- **Bestiary.** A tagged creature's entry shows the replacement art in
  place of its own, in every presentation.
- **Names and text.** A tagged creature's display name and description
  are replaced wherever they appear: battle names and target lists,
  encounter and battle-log messages, the bestiary, and results. Skills
  that only tagged enemies use have their names and descriptions replaced
  in the same places. Text that names a tagged creature while a phobia is
  active never reaches the screen.
- **Replacement content.** A game declares, per phobia, replacement art (a
  terminal sprite and, optionally, a battle image) and neutral text (a
  creature name and description). An enemy may override its phobia's
  replacement with its own art, name and description, and a skill its own
  name and description. When a game declares nothing, the engine uses a
  neutral placeholder, never the tagged content. When one creature carries
  several active tags, the first matching replacement in vocabulary order
  applies.
- **Field creatures.** A creature placed on a map (an NPC or ambient
  creature) is tagged by linking it to the creature it represents, and so
  derives that creature's tags, or declares its own tags when it has no
  enemy counterpart. Its field sprite, in the terminal and in GPUI, and its
  name (including as a dialogue speaker) are replaced.
- **Dialogue and authored text.** Text refers to a creature by reference,
  never by typed name: `{creature:<creature id>}` resolves at display time
  to the creature's name, or to its neutral name while a phobia filters it.
  The engine never scans free text for words. The same reference mechanism
  serves actor names (`{actor:<actor id>}`), so displayed names always come
  from the registries. Validation warns when authored text contains a
  tagged creature's display name literally, and suggests the reference.
- **Objectives and completion.** While an active quest objective requires a
  tagged creature that is otherwise met only in random encounters, its
  troop stays in the roll with replaced art, so the objective remains
  achievable. Bestiary completion and achievements that count creatures
  exclude filtered creatures from what is required.

## Phases

### Phase 0 - Vocabulary, tags and the setting

1. The phobia vocabulary file, its loader and validation.
2. Enemy phobia tags, with validation against the vocabulary, and derived
   troop tags.
3. The player setting in player settings, the New Game questions, and the
   Config menu entry.

### Phase 1 - Encounter filtering

1. Filter tagged troops out of the weighted roll; an empty table yields no
   encounter.
2. The active-objective exception, so a required creature stays reachable.

### Phase 2 - Replacement art

1. Replacement resolution for art, names and text: per-enemy (or
   per-skill) override, then per-phobia, then the engine placeholder.
2. Scripted battles on both presentations: art, names, target lists,
   battle-log and results text, skill names.
3. The bestiary on both presentations: art, name and description.
4. Completion and achievement exclusions.

### Phase 3 - Field creatures and dialogue

1. Tags on field creatures, derived from a linked creature or declared.
2. Field sprite and name replacement on both presentations.
3. The `{creature:...}` and `{actor:...}` reference tokens in every text
   surface (dialogue, skits, cinematics, notifications, the bestiary),
   resolved at display time.
4. Validation of literal tagged names in authored text.

### Phase 4 - Editor authoring

1. A phobia tag picker on enemies, populated from the vocabulary; tags are
   selected, never typed.
2. Vocabulary and replacement editing: art through the shared asset
   picker, neutral names and descriptions as text fields.
3. Validator warnings when a tagged creature is required by content that
   the filter would otherwise make unreachable.
4. Inserting a creature or actor reference into text through a picker, so
   references are selected, never typed.

## Decisions already made (do not relitigate)

- The phobia answer is a per-install player setting, asked at New Game and
  changeable in Config; it never touches save data.
- Tagged troops are removed from random encounters.
- Scripted battles keep the battle and replace the tagged art.
- Bestiary entries hide tagged art behind replacement art.
- Names and text are replaced too: creature names and descriptions, and the
  names and descriptions of skills only tagged enemies use, everywhere they
  appear.
- Field creatures and dialogue fall under the same setting. Field creatures
  derive tags from a linked creature or declare their own; text refers to
  creatures (and actors) by reference token, never by typed name, and the
  engine never scans free text for words.
- Replacement covers the terminal as well as GPUI.
- Tags are declared on enemies, and on field creatures that have no enemy
  counterpart; troops and linked field creatures derive theirs.
