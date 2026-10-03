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

1. **Useful independent presentations.** Terminal glyphs and graphical assets
   are presentations, not each other's identities. Both consume one logical
   timeline and retain readable results. Graphical imagery may be richer
   without changing terminal glyphs, gameplay, collision or saves.
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

## Baseline audit (2026-09-20)

This records the behavior that motivated the phases below. The Phase 0 runtime
contract follows the phase list.

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
- **Tracks**: the existing types, made honest - `glyph` (multi-line terminal
  content), `text`, `flash` (screen or target flash with
  colour and duration; terminal renders a brief foreground-colour pulse, GPUI a
  sprite-alpha-masked tint, or a deliberate full-screen wash), `shake`, and new `image` (an asset path
  with optional sprite-sheet frame progression; GPUI-only fidelity, invisible
  in the terminal by design, like decoration layers).
- **Anchored coordinates** replace absolute ones: every keyframe position is
  an offset from an authored anchor - `target`, `caster`, `screen`, or a
  named slot - resolved at play time by the presentation (terminal battler
  positions, or GPUI shared-layout party slots and troop-owned enemy placements).
  Background scenes never own formation geometry. This fixes resolution dependence
  for both presentations at once. Existing absolute summon timelines keep
  playing through a compatibility anchor (`screen` at the legacy offset)
  until migrated. On the field, `cell` (a map cell) and `object` (a field
  character or object, followed as it moves) are anchors too; see
  [Field effects](#field-effects).
- **Playback**: `once` (cue-driven; the session ends after its last frame)
  or `loop` (an ambient effect that repeats until its owner ends it). Both
  are the same session; looping is a mode, not a second runtime. A loop may
  restart from a later frame (`loopFrom`), so the frames before it play
  once as an opening, as a balloon pops open and then idles.
- **Cues**: the existing vocabulary (`applyEffect`, `playSound`,
  `showMessage`, `flash`, `shake`), all honored at runtime.
- **References, not names**: skills, items and (later) states carry an
  explicit animation id, selected in the editor through a reference picker,
  never typed. Name-matching remains only as a deprecation-period fallback.

## Field effects

### First Field Slice (Local Implementation, October 2026)

The summon compiler and playhead now delegate to shared
`Animations/Timelines/EffectTimelineCompiler` and `EffectPlaybackSession`.
Summon source, cache format, Editor inspection and playback APIs remain
compatible. This is the field subset of Phase 1 items 1 and 6 and Phase 2
item 4, not completion of those phases.

`Animations/<id>/<id>.timeline.php` returns `fps` (1..120),
`lengthFrames`, `playback` (`once` or `loop`), optional zero-based
`restFrame` (default 0), and a list of `tracks`. Image tracks have a
stable `id`, `type => image`, asset-root-relative PNG `asset`, optional
`sheet => ['columns' => 8, 'rows' => 1]`, optional
`cells => ['width' => 2, 'height' => 2]` for its visual size in 48-pixel
field cells, `depth => behind|front`, and `keyframes`. A keyframe names its
zero-based `frame`, optional `duration` (default 1), `sourceFrame` (row-major
sheet index, default 0), and optional cell offset `position => ['x' => 0,
'y' => 0]`. Image dimensions come from the current file. Overlapping
keyframes, invalid crops and missing rest frames are rejected. Field timelines
also accept `glyph` and `text` tracks with the shared keyframe schema described
under the battle runtime below, plus presentation-only `playSound` cues.
Independent terminal/graphical sequences and per-track capability selection
apply to field playback too; unselected image and edge PNGs are not terminal
dependencies. The shared segment composition filters capabilities before
depth ordering and `clearBeforeDraw`, without clearing another session's art.

A map may declare `fieldEffects` as a list of `id`, `effect` (timeline id),
and `anchor => ['cell' => ['x' => 3, 'y' => 5]]` or
`anchor => ['object' => '<stable field sprite id>']`. Object anchors follow
the player's, NPC's or staged actor's presentation and end on removal.
Installed maps own all sessions and clear them on transfer, map clear and
shutdown. IDs remain stable across frame changes. PHP selects the frame;
the renderer receives ordinary retained sprites with source rectangles.

A non-connected tileset piece may name `effect => '<timeline id>'`. Its
nonzero graphical tiles identify stamped instances, including repeated save
points, independently of terminal glyphs and collision. The effect is
bottom-centred on the footprint's last row. No nonzero graphical tiles means
the declaration is refused; legacy glyph-only sites use explicit map effects.

Behind tracks use the shared ground-effect band, characters keep their
existing row order, and front tracks remain below above-character tile layers.
Reduced-motion field playback holds the authored rest frame. Loops stay still;
once effects retire after their authored lifetime. This follows Andrew's
October field brief and does not change summons' final-frame policy.
Missing art or capabilities keep the
original terminal glyph or static tile and log a diagnostic, not a gameplay
failure.

`Data/Presentation/field.php` owns `cues`, keyed by terminal color, each with
an `effect` id and optional `edges` keyed by eight compass directions.
Each edge names an asset-relative PNG and optional clockwise `quarterTurns`
(0..3). These pinned sprites are excluded from camera follow. Quarter-turn
support is an optional renderer drawing capability, not a native effect clock.
An event's explicit `cue.kind` is `story` or `route`. Only story cues produce
edge arrows; omitted kinds remain unclassified, physical-only, pending author
review. Color never implies kind. Existing cue conditions and terminal styling
remain authoritative and unchanged.
Unavailable edge images or quarter-turn support retain the cell's cue artwork
and log a note; they do not suppress an otherwise usable cue effect.

The initial field subset did not include battle playback; its current shared
runtime and outstanding acceptance are recorded below. Still outstanding for
the field: flash/shake tracks and message cues, general authored-record
retirement, connected-piece effects, fractional layer offsets for piece effect anchors,
and the general timeline authoring/preview surface. Claude owns source-preserving
Editor cue-kind, map-effect, piece-effect and binding authoring/validation;
that validation/binding work has landed, but standalone timeline authoring
remains outstanding. This runtime slice is not authoring completion.

The cinematic `field_animation` consumer now accepts an explicit `effect` id
alongside its unchanged `target` shape. Timelines own their fps; mixing that
reference with legacy `animation`/`id` or `secondsPerFrame` is refused by shared
runtime/authoring validation. Existing numeric/name Animation records compile
into this field session without changing their exact frame duration, colours,
offsets, blank frames or last-frame hold. One-shot commands reject loops;
map-owned ambient sessions retain loop ownership.

Last Legend's Listening Stone rite now references `field-healing-aura`:
25 fps, 18 ticks, three ticks per original frame, preserving its full 0.72
seconds independently of battle healing. No authored legacy `field_animation`
commands remain in that Game tree. Numeric records remain for battle/default
and compatibility consumers; this is not format retirement. Editor's effect
picker and source-preserving field references are locally integrated, including
exclusive legacy/timeline fields and shared loading/loop validation. Standalone
timeline editing/preview remains a separate unstarted authoring scope awaiting
Andrew's go.

The old manager-wide animation slot and reduced-motion effect skip are removed.
Each command has an independently owned session; finishing a timed overlay no
longer clears sibling effects or transition covers. Map and cinematic effects
are published through ordinary field sprite providers and retained text layers.
Cancellation, failure and host cleanup pause and remove owned sessions so they
cannot reappear or emit later sounds. Reduced-motion once effects hold their
rest art until their logical lifetime ends, preserving sound cue traversal.

The field is a consumer of the same timelines, not a separate effect system.
Effects there either play once, as a cinematic's `field_animation` does
today, or live with the map:

- **Map-owned ambient effects**: a save point's energy, torch and brazier
  flames, water sparkle, magic circles, and the planned graphical cues (the
  blue and yellow `!` markers and their screen-edge arrows, see the roadmap's
  graphical cues entry). They are declared in map data at a cell, or by a
  tileset piece, so stamping a save point piece brings its effect with it.
  They start when the map is shown and end with it: a transfer, a map clear
  or shutdown removes them, as layered geometry is cleared today.
- **Object-attached effects**: an aura, glow or status effect anchored to a
  field character or object, following it as it moves and ending when it
  leaves or is removed.
- **The action prompt**: while the player can act (an event offers an action, or
  the player faces a talkable NPC, beside it or across a counter), the game's bound effect
  (`actionPrompt` in `Data/Presentation/field.php`, beside the cue bindings)
  draws over the player, offset by its keyframes into the cell above, in
  place of the prompt glyph. It opens once each time the player comes to be
  able to act and idles while that lasts. Last Legend binds an RPG Maker
  balloon icon in RPG Maker's `Balloon.png` layout, which would also suit a
  show-balloon command for cinematics and events (not built). Without a
  usable binding, or on a renderer that cannot draw effects, the glyph
  remains.
- **Depth around characters**: each image track draws either behind the
  characters on the effect's cell (a light pool, a rune ring) or in front of
  them (rising motes, sparks), through the existing presentation layer
  policy. A character standing in an effect is drawn between its two
  layers.
- **Terminal truth**: an ambient effect decorates a glyph the map already
  shows (the save point's `?`, a torch's glyph), so that glyph is its
  terminal presentation; its timeline may add glyph or colour tracks, such
  as a flickering torch colour, but need not. Like decoration layers,
  graphical effects never change collision, events or saves.
- **Reduced motion**: both looping and once field effects show their rest
  frame (the first unless authored otherwise) without motion. Once effects
  retire after their authored lifetime. Summons keep their existing final-frame
  policy; the field policy follows Andrew's October field-effects brief.
- **Boundaries**: terrain that animates per tile (RPG Maker's A1 water and
  waterfalls) stays tile animation on `TileAnimation`'s counter, cheap
  across whole maps; objects and magical or lighting effects are field
  effects. Character and object sheets animate through the character walk
  animation (including standing in place), not effect timelines.
- **One slot becomes many**: the field presentation manager's single
  animation slot gives way to any number of sessions, each with a stable
  entity identity, so a cinematic's effect and a map's ambient effects play
  together.

## Battle command presentation sequence

The local G2 runtime now implements this sequence through the shared session
work in Phases 1 and 2 below. Production artwork bindings, native visual
acceptance and the remaining phase migrations are not complete. Existing pose
artwork alone does not implement this sequence.

Once a confirmed command actually begins execution, present it in this order:

1. Step the acting battler forward from its default formation position.
2. Select the command's presentation pose, such as Attack, Casting Magic or
   Using Item, through explicit project-owned role bindings.
3. Announce the command or item use through the existing message surface.
4. Play source effects, such as a caster's magic preparation.
5. Play target effects, such as hit sparks, at the action's impact cues.
6. Present each recipient's actual result: damage reaction pose/animation,
   damage tint and shake, healing tint/aura, or the appropriate miss, block,
   resistance, status or knockout feedback. Colour is not the only result cue.
7. Finish command feedback and release action-owned transient effects.
8. Step back to the default formation position and select the resulting
   resting state, respecting guard, affliction, enhancement and knockout rather than
   unconditionally forcing an idle pose.

Apply the same lifecycle to party and enemy actors, basic attacks, skills,
items and summons. Selection or target preview never starts execution. The
ordered stages may contain authored overlapping tracks; they are not eight
unconditional delays. Multi-target and multi-hit reactions follow the actual
combat result and its cues, not independently repeated action resolution.

State duration and damage ticks belong to the affected battler's own turn end,
including a consumed turn when sleep or another state prevents an action.
They do not tick after somebody else's command or once for the whole round.
Turn-end damage uses the same result/reaction lane without inventing an attack
or moving the battler forward. Damage numbers, knockout and cleanup finish
before victory or defeat resolution, including missing art and reduced motion.
The tick is recorded once before presentation, so a redraw failure or pause
cannot apply it again.

PHP owns sequence timing and exactly-once gameplay resolution at the authored
effect cue. Rendering, dropped frames and unavailable artwork never determine
damage, costs, targeting or completion. Reuse the shared playback session and
existing pacing controls, with named/configurable timings and slot-relative
motion rather than project-specific coordinates or another blocking loop.

Pose roles reference replaceable assets by stable actor identity, not inferred
filenames or display names. Single-pose images are a valid first treatment;
animated poses and layered attachments must fit the same lifecycle. Keep the
battle-art contract separate from the field's 48-pixel character sheets.
Missing optional poses use the existing available battler representation with
diagnostics; they do not cancel the command. Games supply pose choices, palette
and effects while Engine owns reusable sequencing and safe cleanup.
Unregistered non-idle roles are diagnosed once per battler and role instead of
silently looking like finished state artwork. A valid knockout pose is shown
at normal opacity with its authored ground anchor; only the base standing-art
fallback is dimmed. Revival selects the current resting role on the next frame,
without a renderer-owned health or revival state.

Terminal uses a deliberately simpler presentation: one step forward, an
announcement, small anchored source/target glyph effects and foreground colour,
readable results, then one step back. It does not animate battle character poses
or interpolate their movement. Graphical recoil, shaking effects, fullscreen
flashes, summon title cards and fades are removed from the terminal command
presenter, not from graphical presentation or the shared logical cue lane.
Reduced motion
suppresses translation, shake and flashing while preserving pose/state changes,
messages, logical cues and outcomes. On failure, interruption or battle exit,
release only the action's visual state and restore surviving battlers' valid
formation/resting state without replaying gameplay. Respect existing pause and
legal-skip policies; presentation does not grant a new right to skip a command.

Acceptance covers both battle engines, party and enemy actions, source/target
effects, damage/healing/miss/KO, multi-hit and multi-target actions, counters,
self-targeting, missing/replaced assets, reduced motion, pause and cleanup.
Verify cue order and exactly-once results through real callers as well as the
visible step/pose/feedback/return sequence. Share editor reference pickers and
source-preserving role bindings; do not require every game to write drawing code.

### Local Battle Runtime (October 2026)

`ActionExecutionState` starts a `BattleCommandRunner` instead of running a
blocking effect loop. Both Traditional and ATB advance its
`BattleCommandPlayback` from the PHP battle update. `BattleCommandTimeline`
owns advance, announcement, source, target, reaction, return and finish on
one 120 fps cue lane. Authored effect frames retain their original cadence;
summons retain their cue/frame/end impact policy, title and fade phases.
Effect intervals use the same half-open boundary conversion for their start
and end. At rates that do not divide 120, the first command tick at or after
the authored boundary owns the new frame; adjacent frames cannot overlap or
duplicate a retained entity. Impact and sound cues never fire before that
boundary. Cue durations use their absolute end boundary, not a separately
rounded duration added to a rounded start. Reduced-motion rest inspection uses
the same conversion. All rates from 1 to 120 fps are covered, with actual
48 fps source/target sheet composition, multiple recipients and reduced motion.
Crossed cues execute once even when an update crosses several phases.
Rendering and inspection never resolve combat. Pausing freezes this playhead;
stopping before impact abandons the command without executing it. Presentation
failures are diagnosed separately from gameplay failures.
Scene teardown stops only the engine owned by that scene's battle screen and
disposes its command; an obsolete screen cannot cancel a newer battle.

The existing numeric animation record may add `sourceEffect` and `targetEffect`
timeline identities. Skills/items still reference that record by `animationId`.
Timelines are loaded once per battle context from
`Animations/<id>/<id>.timeline.php`; failed loads retain the legacy treatment.
An image-only target timeline retains the numeric animation's glyph treatment
for terminal playback. An authored glyph/text track supersedes that fallback;
neither inspection path can fire extra cues or resolve combat again. The target
stage lasts long enough for both the graphical effect and any retained terminal
treatment at their own authored frame rates. A shorter image sequence must not
truncate later terminal strokes. Explicit target impact cues keep their authored
time; absent a cue, resolution occurs after the complete shared target stage.
Legacy cell-frame records are imported into the shared playhead without
rewriting their source. `AnimationPlaybackSession` now delegates traversal to
`EffectPlaybackSession`; its separate accumulation loop is removed. The
one-based compatibility API retains exact consumer-owned frame durations,
including 0.12 seconds, blank frame slots and the final frame's full hold.
Delayed updates still deliver every entered frame to existing cue hosts,
while pause and cancellation use the shared playhead. Blocking preview hosts
remain available, but no longer own a second traversal clock. This is clock
unification, not authored-format retirement. Field glyph/text/image playback
now uses the same session with independent ownership and sound cues, but its
flash/shake/message tracks remain outstanding. The numeric records are not
retired yet. Terminal battle entry now
uses the shared session as described below; graphical entry still needs silent
native acceptance. Orphaned explosion migration remains outstanding.

Battle timelines use the field image-track schema above, with an optional
`anchor => caster|target|screen`. Their default anchor is the source caster or
each target, depending on the stage. Battle loading additionally permits
glyph, text, flash and shake tracks, `cues`, and `effectTiming`. Non-image
keyframes accept `frame`, `duration`, integer cell-offset `position`,
`content`, `assetId`, `color`, `visible`, `zIndex` and `payload`. Cues use
`id`, `frame`, `type` and `payload`; impact timing names `end`, `frame`, or
`cue` with its `cueId`. Field loading continues to refuse gameplay cues.
Overlapping keyframes within a track are refused. Both battle presentations
apply segment depth ordering and `clearBeforeDraw` before drawing.
Named-slot anchors are still future work.

An effect identity may instead contain only `presentations`, with complete
`terminal` and `graphical` sequences, each using the same schema above. Each
owns its fps, length, rest frame, tracks and presentation cues; sequences are
not nested or merged. The action consumer selects by graphical capability,
not renderer brand. This preserves existing terminal cadence when graphical
art needs a different duration. Compilation caches each selection separately.
An image-only effect without terminal tracks or cues leaves the numeric
terminal presentation in place rather than extending it to the image's length.
The selected sequence still uses the same command lifecycle and resolves
combat once; presentation selection cannot change costs or outcomes.

Tracks may declare `presentation` as `all` (default), `terminal`, or
`graphical`. This survives summon track round trips and common compilation.
Filter before depth/clear composition, not by hiding all glyphs when any image
exists: narrative and unrelated text remain useful independent tracks.
Only terminal-applicable glyph/text tracks replace a numeric terminal fallback.
Images are inherently graphical and unselected PNGs are not dependencies of
terminal compilation. Invalid selected graphical resources remain diagnosed;
authoring validation must check both sequences rather than hiding invalid art.
Image rest validity is sequence-wide: at least one image must cover its rest
frame, while later independent tracks may be dormant. A second slash therefore
does not need fake early frames to satisfy reduced motion.

`Data/Presentation/battle.php` may supply `actorPoses` keyed by stable actor
identity and `enemyPoses` keyed by the existing enemy identity. Each set maps
`idle`, `attack`, `skill`, `magic`, `summon`, `petition`, `item`, `guard`, `damage`, `heal`,
`afflicted`, `enhanced` or `knockout` to a typed `BattlerPose`: asset-relative PNG `asset`,
sheet `columns`/`rows`, ordered `frames`, `fps`, `loop`, `restFrame` and
normalized `pivotX`/`pivotY`, collected in a `BattlePoseSet`. Omitted roles use
the existing available battler art.
`BattleCommandCatalog::getActionType` is the shared semantic owner used by
command selection and pose playback. An authored summon's linked action selects
`summon`, regardless of its display label. A separately authored `petition`
pose may be stored for a future distinct delayed command; current summon
playback never selects it by renaming Summon or inspecting an actor's name.
No command, capability or unlock is granted by any pose binding.

Persistent pose priority is knockout, guard, afflicted, enhanced, then idle.
All harmful states count, including poison that does not block actions, and
negative stat stages count as affliction. Beneficial states and positive stat
stages count as enhancement; mixed harmful/beneficial status stays afflicted.
State definitions may declare `disposition` as `harmful`, `beneficial` or
`neutral`; existing definitions retain their harmful/affliction meaning when
omitted. Names, descriptions and HP formulas are not classifiers. Cures, expiry,
revival and battle cleanup reselect from current combat state without a cached
renderer-owned status. Editor must expose the disposition as a constrained
choice and preserve it in safe round trips.
The catalog's `ui` owns shared canvas dimensions, party slots and feedback
safe area; each troop enemy's `graphicalPlacement` owns its slot. Arena scenes
own only a display name, background and optional skin. Effects follow the
actual combatant instance, not an arena-side troop override; see the
[formation contract and ownership audit](rendering/graphical-battle-g1.md#troop-owned-graphical-formation).
For artwork registered on matching frame cells, the set may specify
`displayWidth` in arena units. All roles then share that full-frame width and
their aspect ratio instead of individually fitting the old idle contain box.
This registration also places and preflights base artwork, including frames
that omit optional poses/effects after a composition failure. A registered base
does not revert to an independently scaled contain box. Unusable optional
registration that cannot fit the base image is diagnosed and retains a valid
contain placement; catalogs without a pose set retain historical contain sizing.
This is presentation intent, not a copy of PNG dimensions; replacing a file
still reads its current dimensions. Unregistered or unusable optional roles
retain available registered resting/idle artwork, preserving its scale and
pivot, before falling back to base artwork with diagnostics. Damage and heal
may use that resting image with actual-result tint/recoil when reaction art
has not been supplied; afflicted and enhanced are not reaction-art aliases.
Only a true knockout image is shown at full opacity for a defeated actor.
Dimensions and source crops come from the current file, not an art receipt
or frozen production hash. Missing/invalid optional art is diagnosed and
does not alter command outcomes.

`GraphicalBattleEffects` presents current image/glyph/text segments through
retained canvas layers, follows combatant-instance bounds and draws a screen
track once. Translation, recoil and authored shake are bounded and suppressed
under reduced motion. Reduced motion holds the effect's rest frame and keeps
actual-result feedback. Battler flash and damage/healing tints are masked to
the alpha of the current displayed pose and source frame through the shared
`CanvasImageTint` composer. The former rectangular battler washes are removed;
only deliberate full-screen fades/flashes cover the arena. Invisible or missing
battler art never receives a stand-in rectangle. Screen-scope tracks draw once
even when the command has several targets. Current PNG dimensions normalize
the crop, so replacement art does not leave a frozen mask. Tint overlays keep
the pose's motion, clipping and opacity and disappear with the command.
Concurrent flash/reaction tints share one owned surface per combatant instead
of multiplying composite count; combined raster and operation budgets still
apply to the complete frame.
Graphical battle now requires negotiated compositing, source rectangles and clip/opacity
support in addition to the canvas capability; less-capable backends retain
terminal battle rather than entering a partially supported graphical mode.
The complete frame checks the shared image/region and canvas collection
budgets, including UI, battlers and effects together. An unusable optional
pose/effect composition is diagnosed and omitted for that frame, retaining
the base battlefield and combat feedback. An invalid base frame still fails
clearly. This is not a total-resident CPU/GPU-memory guarantee.

Terminal battlers and effect/popup anchors share the same presented position:
the fixed advanced slot throughout command execution, then the formation slot
at return. Glyph/text tracks and target foreground-colour pulses inspect the
same lane; image tracks remain graphical-only. Command shake, fullscreen flash,
summon title cards and fades are removed from terminal command drawing. Controls
and the announcement remain visible during summon phases. Standalone legacy
summon preview helpers remain available pending their migration.
The shared `Console::updateFrame` composes partial screen changes atomically,
preserves unrelated cells and overlays, and emits only final differences. Battle
updates, field refreshes and message removal use that boundary so erase/redraw
does not expose a blank battlefield or resend unchanged frames. Sparse graphical
text composition keeps the same partial-update ownership. Action cleanup owns
feedback, message, focus, flash, shake and temporarily hidden graphical controls,
not combat state.

Terminal battle entry also imports the existing frame-file art into the shared
`EffectPlaybackSession`, retaining its normal 0.2-second duration. The blocking
intro waits, separate frame counter and physical per-frame screen clears are
removed. An owned transition overlay replaces only changed cells; completion
removes that cover and composes the incoming battle atomically. Suspension
pauses the session, resume excludes suspended time, resize redraws the same
frame, and exit/stop releases only the entry's overlay. Reduced motion removes
the intro's stepping and holds the final authored frame until the next update
hands off to combat. No graphical transition treatment is changed by this
compatibility import. Slow updates inspect the current frame rather than
blocking to replay visual frames that have already elapsed.

Headless tests cover real Traditional/ATB callers, party/enemy commands,
pause, pre-impact shutdown, healing/damage, exact high-rate cue boundaries,
multi-target instance identity, source/target/screen image anchors, replaceable
and missing sheets, reduced-motion rest art, audio precedence and invalid
definition boundaries. Actual multi-hit/multi-target skills, misses, knockout
and self-targeted guard commands retain exact costs and typed results. Combined
image/region and text-layer overflow keeps the base frame without repeating
impact. This does not yet establish native pixels, all counter combinations,
total-resident resource budgets,
Editor source-preserving authoring, or Linux/Windows support. Production
effect integration and a silent native sequence playtest remain acceptance work.
Andrew's 2 October KO screenshot exposed a missing end-to-end Game pose library,
not a KO-only defect. Andrew has approved all seven standing replacements;
Game now binds all eight approved ten-role sets through explicit actor-role
bindings in the canonical checkout. All 72 action/state poses and eight Idle
images are present; comparison originals remain preserved. Game owns these
bindings, not renderer filename inference. All-role
Engine regressions cover Traditional and ATB action selection, actual stat-stage
changes, harmful/beneficial/neutral states, revival and registered-scale fallback.
The complete macOS headless suite passes 3,993 tests with one skipped (84,165
assertions); static analysis reports no errors. These are not native pixel tests.
Real command regressions inspect separate source art followed by two full target
sheet passes, exact two-hit outcomes, single MP cost and return cleanup in both
battle engines and reduced motion. Compatibility coverage also retains longer
terminal strokes when graphical target art ends earlier, without moving an
explicit impact cue or adding a second resolution.
The current timing/track-focused regressions pass 288 tests / 26,140 assertions.
The shared `EffectPlaybackTiming` now owns authored fps/defaultSpeed pacing for
standalone and command playback, including duration, half-open image boundaries,
cues and terminal fallback sampling. Independent summon transition/title timing
is unchanged. Reduced motion inspects original rest segments even when fast
playback skips that frame, without seeking the cue playhead or moving impact.
Synthetic image and real summon-compiler coverage verifies this parity; ordinary
timeline source playback remains once/loop, not a new speed-authoring schema.
The first-party legacy PNG delivery
uses the established native approval, provenance and image checks, not the
unrelated Game Development Studio catalog-vendoring workflow. No applicable
integrity or authorization gate may be bypassed. Approved effect wiring and a
silent native sequence playtest remain unfinished, so shared-folder delivery
alone is not an in-game fix.
The trusted Game pose-preview factory accepts an explicit stable-actor selection
for bounded inspection of an active complete set. Its omitted/null selection
retains the all-eight acceptance gate, and the Engine driver refuses empty,
duplicate, oversized or unacknowledged selections. Game reports the default
all-eight CPU preview passing 3,301 frames in each motion policy: 80 roles,
40 resting views, advance/return, one resolution per inspected command and
unchanged combat baselines. All 377 artwork cases passed before removing
frozen registration-number assertions under Andrew's mutable-content test rule.
Game's broader pose/command/preview coverage passed 542 tests / 59,278 assertions;
its full pose snapshot suite passed 1,778 tests / 849,095 assertions. These results
precede final effect wiring and are not its effect-integrated acceptance run.
No native preview window has been launched for the complete accepted set;
silent visual inspection remains required.
The Last Legend graphical integration fixture now advertises the updated
compositing capability contract and retains explicit capability checks. Keep
missing-compositing fallback coverage; do not remove the runtime capability gate
to make old graphical expectations pass.

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

#### Phase 0 runtime contract

`ActionExecutionState` passes summon frame and cue callbacks into
`SummonCutscenePlayer`. `effectTiming.mode` selects exactly one gameplay
resolution: `cue` fires when the named `cueId` is crossed; `frame` (also
accepted as `explicit_frame`) fires at the start of that frame; `end` fires
after the last frame and before the outgoing transition. Frame-zero cues are
delivered once. Presentation cues such as sound, message, flash, shake and
restore are dispatched separately from combat resolution; an `applyEffect`
cue does not override a different authored timing mode.

If a frame renderer fails, the summon player traverses remaining frame and
cue callbacks logically, while the cell-animation player delivers remaining
cues; both rethrow the presentation failure. The battle state logs
that failure, clears transient visuals and still resolves gameplay once. If a
cue or frame cannot be reached because presentation setup fails, resolution
falls back to once at cleanup. Gameplay callback failures propagate; they are
not treated as render failures or retried. Missing explicit animation ids and
malformed optional assets are diagnosed without suppressing the battle action.

Under reduced motion, both players deliver cues in frame order and draw only
the final frame. Summon title cards and transitions, and visual flash/shake
motion are skipped. Cell-frame animations retain the old two-argument
`AnimationPlayer::play()` callback in ordinary playback; reduced-motion
callers must provide the separate cue callback when an earlier frame has a
cue, rather than silently losing it.

Terminal cell-animation flashes now recolour foreground glyphs for
`flashDurationFrames`; coloured background washes, including coloured empty
cells, are removed. The underlying field returns when the pulse ends. The
command presenter additionally omits fullscreen pulses. Standalone legacy
terminal summon segments draw in compiled
`zIndex` order, and `clearBeforeDraw` removes lower queued art before the
current segment draws. Graphical effect drawing remains a later phase; PHP cue
traversal and combat resolution continue even when that presenter draws no
effect art.

Animation and summon definitions, including compiled summon timelines, stay
stable for one battle. The scene releases the battle cache when it stops or
fails to enter; a later battle reads current assets. Editor/default library
instances continue to load live data. Malformed entries are warned about and
skipped individually, so valid neighbouring assets remain usable.

#### Explicit references

`Skill` (including Basic, Magic and Special) and `Item` accept an optional
`animationId: ?int`, identifying the numeric id in `Data/animations.php`.
The Editor's Animation picker displays the name and id, stores the id, and
supports clearing it. Skill Ctrl+G follows the explicit id even after a rename.
Saved inventory instances resolve the current item definition's animation;
the artwork binding is not frozen into a save.

A null skill reference now uses an explicitly declared semantic animation
default, not its display name. Animation records may declare `roles` as a
unique list of `attack`, `skill`, and `restorative`: basic AttackAction uses
`attack`; skills use `restorative` for restorative/buff magic and `skill`
otherwise. Each role must have exactly one record. Missing or duplicate role
bindings are diagnosed and omit only that default effect. Last Legend binds
id 3 to attack, id 1 to skill and id 2 to restorative. Generic Hit Spark is
removed as the attack default, but its existing record remains available for
compatibility and generic skills. The physical default is a stylized strike,
not a claim about a character's weapon or a creature's anatomy.
This is default presentation, not inference of a specific named attack's art;
Last Legend now explicitly binds its 24 battle-capable non-summon skills and 11
effect-bearing restorative items to distinct shared glyph timelines. These
separate single and dual strokes, magic preparation, magic burst, Burn and
healing. They do not change action formulas, hit counts, costs or availability.
Andrew has accepted BladeSlash, PhysicalImpact, MagicPreparation, Burn,
Healing and the separately reviewed MagicBurst target-impact sheet. All six
are locally integrated into independent graphical sequences, retaining original
terminal cadence. Game reports 73 effect tests / 4,475 assertions and full
`test:ci` at 1,807 passed / 852,747 assertions (battle simulations excluded),
with lint of 443 files and validation at zero errors / 30 existing warnings.
Silent native acceptance remains G2 work; these CPU checks are not a claim
that the final artwork has been reviewed in the native runtime.

October 3 acceptance checks against live Engine `5cdaf1f`: the full Engine
suite passed 4,167 tests with one skip and 84,998 assertions; full PHPStan
passed. Disposable production-presenter previews exercised all eight actors'
80 role views, 40 resting views and eight exactly-once inspection sequences,
returning to formation in normal and reduced-motion modes. Both silent macOS
native runs closed cleanly, with 745 and 602 presented acknowledgements.
The six-effect normal native run covered 80 image-frame views and closed with
680 presented acknowledgements; the reduced-motion native run held nine authored
rest segments and closed with 387 presented acknowledgements. Both CPU modes
also passed. These fixtures count presentation beats, not real combat
outcomes. Acknowledgements are not visual or timing acceptance: the inspection
tool selected an existing Game window rather than the separate preview. No
user session was closed or altered to obtain a screenshot. Native visual
acceptance, actual encounter acceptance and non-macOS platforms remain open.

After Claude separated historical layer-split proof from mutable live maps,
the October 3 full Game CI run has 1,840 passes and one failure across 1,841
tests, with 833,951 assertions, excluding battle simulations. The remaining
reachability failure reports three inaccessible NPCs in Apthia's Garden Route
Control, Happyville's inn and its shop. Do not weaken reachability to hide
these; the counter/service decision remains with Andrew. Validation reports
zero errors and 45 warnings. These results supersede the earlier Game totals
above, not the accepted-art review.

Art's source shadow audit inspected all 112 current battle PNGs at registered
display size, or an explicitly labelled reference size for unbound art. It
replaced 97 locally: all 80 party role images, Aeryn's Victory image and 16
enemy images. The other 15 retain readable existing shadows. The weak party
source shadow layer was retuned and recomposited once under the same body;
KO contact shadows were registered to the lying figure rather than its weapon.
Originals and editable shadow layers are preserved in Game's existing shared
`Graphics/Comparison/BattleShadowAudit-20261003/` tree. No bindings, pivots,
canvas dimensions, maps or gameplay changed, and rejected historical costumes
were not restored. Four glyph-only summons have no graphical battler raster
to audit. The coordinator verified all 112 current PNG dimensions and delivery
receipts, all 97 preserved originals, and the production-presenter CPU fixture's
80 role views, 40 resting views and eight exactly-once inspection sequences
after replacement. These are source/raster and CPU checks, not native visual
acceptance of the revised shadows. The corrected art remains uncommitted.

Runtime skill-name and hardcoded animation-name selection are removed. Legacy
items without a binding retain their existing no-animation behavior. A missing explicit id
reports a warning and never substitutes a default. No presentation reference
prevents the item's or skill's gameplay effect. Claude owns Editor role
round trips, pickers and duplicate-role/reference validation; its old
name-candidate diagnostic helper remains for migration only and must be
updated, not treated as runtime authority.

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
   Game's current read-only audit identifies two consumers of Healing Aura:
   its accepted battle-healing target timeline and the Listening Stone
   cinematic's six original cell frames at an explicit 0.12 seconds per frame.
   Hit Spark's battle rate is derived from turn pacing, not an authored
   constant. Preserve those consumer contracts during migration; do not replace
   the cinematic with the battle timeline or invent 10 fps from a helper default.
   The field consumer now accepts explicit timeline identities and preserves
   numeric/name Animation compatibility. Its glyph/text/image tracks, sound
   cues and independently owned sessions retain exact colours/offsets,
   pause/cancellation and an authored reduced-motion rest treatment. Migrate
   the authored records before retiring their format. The cinematic cadence is
   representable exactly
   as 25 fps with three ticks per original frame, without changing the battle
   healing timeline's six frames at 10 fps. Last Legend's sole authored field
   consumer has now migrated to the distinct `field-healing-aura` timeline at
   that exact cadence, verified through real event and cinematic callers in
   terminal/graphical and normal/reduced modes (41 targeted tests, 3,300
   assertions). Its legacy name/timing fields were removed; numeric records
   remain. The remaining record migration must establish timing ownership for
   paced compatibility effects and safe Editor standalone authoring, rather
   than treating it as a Game-only file replacement.
   The compatibility traversal now uses `EffectPlaybackSession` with an exact
   frame-duration override instead of rounding that duration to integer fps.
   This removes the legacy clock and field reduced-motion skip while leaving
   authored-record retirement and source-preserving migration outstanding.
4. Fold the battle-entry frame file into a timeline played by the same
   session. Local graphical entry now uses the shared retained outgoing
   composition and cover/readiness/handoff/reveal lifecycle, not translated
   text frames. Terminal frame-file compatibility now compiles the existing
   text into that same playback session; its manual blocking clock and
   clear/redraw loop are removed. Forty focused tests cover the text import,
   actual entry lifecycle, retained presentation and existing graphical
   handoff, including captured terminal output in both tracking modes.
   Silent native visual acceptance remains pending. See
   [the current boundary and acceptance](rendering/graphical-battle-g1.md#graphical-entry-handoff).
5. Migrate `assets/Data/Animations/explosion01/` (currently orphaned): each
   of its text files is one frame, becoming one glyph-track keyframe of a
   timeline. Game's source/history audit confirms seven orphaned frames, but
   no authored frame rate or durations. The coordinator has requested a
   once-only 10 fps treatment (0.7 seconds); until Andrew chooses the timing,
   no content is migrated, removed or bound to combat. Preserve the blank rows
   and the distinct frame slots even where frames 6 and 7 are identical.
6. Run the session on the field too: cinematic `field_animation` plays
   through it, and maps start and end their ambient effects (declared at
   cells or by tileset pieces) with the map, with `loop` playback and the
   `cell` and `object` anchors.

### Phase 2 - GPUI parity (feeds gates G2/G3)

1. A presentation adapter maps a playing session to the existing retained
   canvas contract: glyph/text tracks become `CanvasTextLayer` runs, image
   tracks become `CanvasImage`s with pose or sprite-sheet `sourceRect`
   progression, and shake becomes a bounded destination offset on affected
   entities (zero under reduced motion). Keep stable entity IDs and emit
   changed state through retained updates; remove action-owned entities on
   completion. Do not revive the old stateless transport. Flash/tint/aura
   treatments use available compositing with a readable lower-capability
   fallback, not an assumed native image-tint operation or rectangle draw
   primitive. Respect negotiated capabilities and composite/resource limits
   across multi-target effects. PHP drives time and cues; the native renderer
   does not infer poses, motion or combat outcomes.
2. Remove the five `usesGraphicalField()` early-returns by routing terminal
   and GPUI through the same session with two presenters.
3. Summons render graphically through the identical path - G3 is then a
   content and acceptance gate, not new machinery.
4. Field effects present through the same adapter as sprites in the field's
   retained world, behind or in front of characters by track, starting with
   the walk-on save point and the graphical cues.

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
5. Extend the Phase 0 skill/item reference picker and Ctrl+G navigation to
   the unified timeline library.

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
- Flash is a brief foreground-colour pulse of the target in terminal commands,
  and sprite-alpha-masked battler tint (or an explicitly authored full-screen
  wash) in GPUI, for the authored duration. Never a rectangular battler wash.
- The G2 and G3 roadmap gates take this plan as their scoped brief.
- `explosion01` migrates into the timeline library: each text file is one
  frame.
- Field effects are timelines anchored to cells or objects, never a second
  animation system: map-owned ambient effects loop with the map, object
  effects follow their owner, and each image track draws behind or in front
  of characters.
- Per-tile terrain animation (A1) stays on the tile animation counter;
  character and object sheets animate through the character walk animation.
