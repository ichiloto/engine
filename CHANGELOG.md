# Changelog

## 0.5.0

Ichiloto Engine 0.5.0 expands the runtime from a field-and-battle foundation
into a complete authoring target for story-driven terminal RPGs.

### Highlights

- Added cinematic and summon definitions, playback sessions, checkpoints,
  skip policies, finalizers, map triggers, party wielders, and the Summons
  menu.
- Added resumable story-event execution with typed command vocabulary,
  cooperative presentation lanes, conditional cues, and recovery after save
  and load.
- Completed the runtime identity and catalogue model for actors, classes,
  inventory, skills, enemies, knowledge entries, progression, equipment, and
  combat resolution.
- Expanded maps with regions, transitions, NPC state, random encounters,
  background music, camera scrolling, collision handling, and persistent world
  state.
- Added versioned save envelopes, engine schema migrations, project content
  migrations, aliases, tombstones, legacy-save detection, and compatibility
  diagnostics.
- Added battle simulation and reporting, escape policy, summon integrity,
  quest progression, achievements, shops, notifications, and audio lifecycle
  support.
- Hardened terminal rendering around partial writes, cursor placement, modal
  restoration, text wrapping, selection highlights, pause overlays, and the
  field HUD.

### Requirements

- PHP 8.4 or newer within the PHP 8 release line.
- Symfony Console 8.
- Pest 5.1 for the development test suite.
