# Effect animation and authoring - authoritative plan

This plan unifies the engine's animation systems into one timeline model for
magic, skill, item and field effects, gives that model a graphical (GPUI)
presentation alongside its terminal one, and builds the authoring tooling in
the editor. It feeds the integration roadmap's G2 (battler animation and 
combat feedback) and G3 (graphical summon presentation) gates rather than 
replacing them. Related plans: [layered-tilemaps.md](layered-tilemaps.md); related contracts:
[summons.md](summons.md), [cinematics.md](cinematics.md),
[rendering/sprite-sheets.md](rendering/sprite-sheets.md).

## Principles

1. **The terminal always represents the whole effect.** Every animation has a
   glyph presentation that is the authoring truth; GPUI renders the same
   timeline with richer fidelity (styled runs, images, opacity) as sugar.
   Layer + glyph = identity, colour = attention, crop/asset = fidelity, as
   established by the layered-tilemaps plan.
2. **One playback model.** The summon timeline (fps, tracks, keyframes, cues,
   compiled segments, a non-blocking session that separates elapsed time and
   cue traversal from rendering) is the engine's single effect-animation
   runtime. Spectacles are data, never bespoke runtimes.
3. **Presentation never changes combat identity or outcomes.** An effect that
   cannot render, on any presentation, still applies its gameplay result at
   its authored moment. Reduced motion always has a counterpart: result,
   final frame, cues, no motion.
4. **Assets are the developer's; the engine advises.** Timelines may reference
   images (sprite sheets, single stills). The engine publishes preferred
   shapes and dimensions, loads what exists, fails an asset only when
   missing, wrong type or corrupt, and renders best-effort otherwise.
   Authored metadata never restates what a file knows about itself.
5. **Preview is the runtime.** The editor previews through the engine's own
   playback session, as the summon preview already does. Anything the preview
   fires, battle fires; anything battle honors, the preview shows.

## Current state (audited 2026-09-20)

Two authored animation systems exist, plus one orphan:

- **Cell-frame animations** (`assets/Data/animations.php`): id, name, anchor
  position (center/head/feet/screen), frames of positioned single glyphs,
  cues (sound, flash). Played by a blocking `AnimationPlayer` squeezed into a
  fixed fraction of the battle turn. Bound to skills by name equality with a
  two-animation fallback; skills carry no animation reference. The shipping
  game has exactly two entries.
- **Summon timelines** (`assets/Cutscenes/Summons/<id>/`): formatVersion,
  authored fps, lengthFrames, typed tracks (glyph/text/flash/shake) of
  keyframes (frame, duration, position, multi-line ASCII content, assetId,
  colour, visibility, zIndex, blendMode, easing, payload), cues, a compiler
  producing sorted playback segments with a source hash, and
  `SummonPlaybackSession` for non-blocking traversal. The editor authors and
  previews these end to end through the engine session.
- **Battle-entry frames** (`Graphics/Animations/battle-transition.txt`):
  a separate frame-file path used only by `BattleStartState`.

Defects the plan corrects (with their locations):

1. No GPUI path for any battle animation or summon: five
   `usesGraphicalField()` early-returns in `BattleFieldWindow` silently drop
   them. The one precedent for motion reaching GPUI is
   `GraphicalBattleFeedback`: PHP re-emits per-frame canvas state.
2. `effectTiming` is validated, authored and previewed but inert in battle:
   `ActionExecutionState::playSummonCutscene()` omits the player's `$onCue`
   callback, so damage always lands after playback regardless of mode.
3. Flash cues (`flashColor`, `flashDurationFrames`) have no consumer
   anywhere; they are authored in both shipping animations and editable in
   the editor.
4. Skills and items carry no animation reference; binding is name equality.
5. Compiled `zIndex` and `clearBeforeDraw` are ignored by the terminal
   renderer; `blendMode` and `easing` are stored and never consumed.
6. Reduced motion is honored at fifteen sites but not by the two loudest
   motion sources: action animations and summon cutscenes.
7. Animation and summon libraries re-read their files inside the turn loop.
8. Timeline positions are absolute coordinates against a ~133x28 terminal,
   blocking resolution independence.
9. Orphan asset: `assets/Data/Animations/explosion01/` has no loader.

## The unified model: effect timelines

One authored format, the summon timeline generalized:

- **Library**: `assets/Animations/<id>/<id>.timeline.php` (+ optional
  `<id>.data.php` for identity/metadata), compiled and cached exactly as
  summons are today. Summon timelines remain where they are; they become
  consumers of the same model rather than a special case.
- **Tracks**: the existing types, made honest - `glyph` (multi-line ASCII
  content, the terminal truth), `text`, `flash` (screen or target flash with
  colour and duration; terminal renders a brief recolour pulse, GPUI a
  translucent canvas rectangle), `shake`, and new `image` (an asset path
  with optional sprite-sheet frame progression; GPUI-only fidelity, invisible
  in the terminal by design, like decoration layers).
- **Anchored coordinates** replace absolute ones: every keyframe position is
  an offset from an authored anchor - `target`, `caster`, `screen`, or a
  named slot - resolved at play time by the presentation (terminal battler
  positions, or GPUI arena slot geometry). This fixes resolution dependence
  for both presentations at once. Existing absolute summon timelines keep
  playing through a compatibility anchor (`screen` at the legacy offset)
  until migrated.
- **Cues**: the existing vocabulary (`applyEffect`, `playSound`,
  `showMessage`, `flash`, `shake`), all honored at runtime.
- **References, not names**: skills, items and (later) states carry an
  explicit animation id, selected in the editor through a reference picker,
  never typed. Name-matching remains only as a deprecation-period fallback.

## Phases

### Phase 0 - Make what exists honest

No new formats; the authored data that already exists starts meaning what it
says.

1. Fire summon cues in battle: pass the cue callback, honor `effectTiming`
   (`end`/`cue`/`frame`), land the gameplay effect at its authored moment.
   Preview and runtime stop disagreeing.
2. Implement flash cues in the terminal (target/screen recolour pulse for
   the authored duration) so the two shipping animations' cues render.
3. Honor reduced motion in both players: skip to the final frame, fire every
   cue in order, apply the effect, no motion.
4. Honor compiled `zIndex` and `clearBeforeDraw` in the terminal summon draw.
5. Cache animation and summon libraries for the battle's lifetime; stop
   re-reading files per action.
6. Add the explicit animation reference to skills and items (engine schema +
   editor picker), keeping the name fallback with a validator notice.

### Phase 1 - One runtime

1. Generalize the summon compiler/session into the effect-timeline library
   (`assets/Animations/`), with anchored coordinates.
2. Replace the blocking `AnimationPlayer` path with a non-blocking session
   driven from the battle update loop; turn pacing waits on session
   completion or the effect cue instead of squeezing frames into a fixed
   turn slice. Authored fps is honored everywhere, as summons already do.
3. Migrate the two cell-frame animations to timelines (a cell frame is a
   one-glyph content grid); retire the cell-frame runtime after a
   deprecation window. The editor migrates its bespoke animation database to
   the schema-driven record path the summons already use.
4. Fold the battle-entry frame file into a timeline played by the same
   session.
5. Migrate `assets/Data/Animations/explosion01/` (currently orphaned): each
   of its text files is one frame, becoming one glyph-track keyframe of a
   timeline.

### Phase 2 - GPUI parity (feeds gates G2/G3)

1. A presentation adapter renders a playing session's frame to canvas
   primitives, following the `GraphicalBattleFeedback` precedent of per-frame
   re-emission: glyph/text tracks become `CanvasTextLayer` runs (with
   glyph effects and opacity available), flash tracks become translucent
   `CanvasRectangle` fills, image tracks become `CanvasImage`s with
   sprite-sheet `sourceRect` progression, shake becomes a bounded offset on
   the affected layers (zero under reduced motion). No renderer or protocol
   changes: the canvas is already a stateless per-frame description.
2. Remove the five `usesGraphicalField()` early-returns by routing terminal
   and GPUI through the same session with two presenters.
3. Summons render graphically through the identical path - G3 is then a
   content and acceptance gate, not new machinery.

### Phase 3 - Authoring in the editor

The summon timeline surface generalizes into the animation editor:

1. Effect timelines join the Cutscenes-style authoring path: track list,
   keyframe fields, cue lane, and the existing preview session controls
   (play/pause/step/seek/boundaries/speed/loop) - all already built for
   summons and reused, not duplicated.
2. Frame painting uses the canvas the editor already has: modal Paint mode,
   the brush colour system, and the character map author each keyframe's
   glyph content in place, with onion-skin ghosts of the previous and next
   keyframes and the existing target silhouette for anchor preview.
3. A battle-context preview stage: battler silhouettes at real arena
   anchors, so an effect is authored against the geometry it will play on.
4. Cue authoring gains the pieces Phase 0 made real: flash parameters,
   effect timing against the cue lane, per-track mute/solo for isolating a
   layer while authoring.
5. Skill and item forms gain the animation reference picker, and Ctrl+G
   follows the reference instead of the name.

### Phase 4 - The rich 2D editor (direction, scoped separately)

The long-term destination is a graphical authoring surface for the 2D
presentation. The staged route that reuses what exists:

1. First, a **GPUI preview window from within the TUI editor**: the editor
   launches the installed renderer exactly as the game does and presents the
   playing timeline through the Phase 2 adapter. Authoring stays in the TUI;
   the artist sees the true graphical result live. This is dogfooding the
   engine's own renderer protocol, and it is small once Phase 2 exists.
2. Then, informed by that experience, the graphical editing surface itself -
   selection, dragging keyframe positions on the canvas, scrubbing with
   rendered frames. Its scope, toolkit and relationship to the TUI editor
   (which remains the always-available surface) are decided when Phase 4 is
   scoped, not preempted here.

## Implementation dependencies

This plan's Phase 0 and the layered-tilemaps Phase 0 run simultaneously.
After both Phase 0s, the layered-tilemaps implementation proceeds first;
this plan's Phases 1-4 follow it.

## Technical constraints

- The summon timeline model is the single effect-animation runtime;
  spectacles are data, not bespoke runtimes.
- Every effect has a terminal presentation as its authoring truth; image
  tracks are GPUI-only fidelity, mirroring decoration layers.
- Coordinates are anchor-relative, never absolute terminal positions.
- References are selected, never typed; name-binding is a deprecated
  fallback.
- Preview goes through the engine's own session: preview/runtime parity is
  a structural property, not a testing goal.
- Reduced motion, skip, and no-render paths always apply the gameplay
  result; presentation never changes combat identity or outcomes.
- The cell-frame animation format retires after migration; the timeline is
  the only authored format.
- Flash is a brief recolour pulse of the target or screen in the terminal,
  and a translucent canvas fill in GPUI, for the authored duration.
- The G2 and G3 roadmap gates take this plan as their scoped brief.
- `explosion01` migrates into the timeline library: each text file is one
  frame.
