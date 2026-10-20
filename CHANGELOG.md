# Changelog

## Unreleased

### Added

- `cueStyle` in `Data/Presentation/field.php` keeps cue marks readable on any
  ground: an optional plate behind every graphical mark, an optional gentle
  bob (still under reduced motion), the edge pointer distance, and a Terminal
  background cell and bold for every cue glyph (default black and bold).
- Edge markers are composed from the upright cue mark and a separate pointer
  that alone turns, on the outward side.

- Event speakers: a map event names the NPCs who speak it (`speakers`), and
  map validation (`event.speaker`) reports a speaker who is not on the map,
  is never present while the event can run, wanders freely or stands more
  than one cell from the event's area.
- World-object resource doorways: a resource declares the cells where its art
  paints an opening (`doorway`), and map validation (`object.doorway`)
  reports a placement whose doorway has no entrance under it, or an entrance
  behind an object's ground row inside its covered cells, where the art hides
  the player.
- `Core\WorldConditionExclusion`, the proof that two condition lists never
  hold together, moved from the Editor so the Engine's rules share it.
- Objective waymarks: quest objectives author `waymarks` (a map and one
  stable event, NPC, world object or landmark, with their own conditions for
  phases and free-order work). Off-screen waymarks hug the field view edge
  with an arrow in both renderers; on-screen ones draw the game's waymark
  symbol. The local map and the world map mark objectives with legend
  entries, and the next usable exit is marked for objectives on other maps
  through `Field\MapRouteGraph` (transfers and reusable scripted transports).
  The style is `waymarks` in `Data/Presentation/field.php`; map markers use
  the menu theme's new `objective` colour and `map.objective` icon roles.

- Held walking in the graphical field, following RPG Maker MZ: while a
  direction is held the player keeps walking one committed cell at a time at
  180 field pixels per second (8/60 s sideways, 16/60 s vertically), the most
  recently pressed held direction winning. The renderer slides characters
  between cells over each step and moves a following camera with the player.
  The terminal keeps its event-only stepping unchanged.
- The `key_transitions` renderer subscription (key presses with a stable
  control identity, releases and input resets), bounded held state in
  `InputManager` (`isButtonHeld()`, `wasButtonPressed()`,
  `getButtonPressOrder()`), and the `field_motion` drawing feature (sprite
  `motion` and viewport `follow`).

### Changed

- Removed: cue bindings' `edges` (one baked image rotated whole, which laid the
  `!` on its side) are refused; author `pointer` images instead. Terminal cue
  glyphs now draw bold on a black cell by default.
- Removed: story cues (`cue.kind` `story`) no longer draw their own edge
  arrows. Off-screen guidance now comes from quest objective waymarks, which
  use the same cue binding edge images. Cue kind remains authored data.
- The Game's GPUI session now requires `key_transitions`; install the updated
  renderer with this Engine.
- Field walking frames advance by distance travelled instead of one stride per
  step, so both axes animate at one pace and continuous walking no longer
  stands between steps. `CharacterWalkAnimation::STRIDE_SECONDS` is replaced
  by `STOP_SECONDS`, and `step()` takes the `CharacterStep` it presents.
- Under reduced motion the player and staged actors now show the standing
  frame, as NPCs already did; previously they kept animating.

- Standardized whole-image factories on `getFromPng`. Removed the former
  `CanvasNineSlice::fromPng` name; callers now use `CanvasNineSlice::getFromPng`,
  matching `BattlerArtwork::getFromPng`. Image loading behaviour is unchanged.

### Removed

- Automatic pause-on-unfocus for title animation and rolling credits was removed
  in `eb5e19e`. Removing `window_activation` from their capability requirements
  decoupled graphical presentation from renderer startup requirements, but also
  removed the automatic activation-event subscription. Normal launches therefore
  keep playback running when the window loses focus. Scene/modal/suspension
  pausing and explicitly configured activation subscriptions remain supported.
  This records the removal; it does not restore or newly approve that behaviour.

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

- PHP 8.4.1 or newer within the PHP 8 release line.
- Symfony Console 8.
- Pest 5.1 for the development test suite.
