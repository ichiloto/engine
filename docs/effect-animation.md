# Effect animation and authoring - authoritative plan

This plan unifies the engine's animation systems into one timeline model for
magic, skill, item and field effects, gives that model a graphical (GPUI)
presentation alongside its terminal one, and builds the authoring tooling in
the editor. It feeds the integration roadmap's G2 (battler animation and 
combat feedback) and G3 (graphical summon presentation) gates rather than 
replacing them. Related plans: [layered-tilemaps.md](layered-tilemaps.md); related contracts:
[summons.md](summons.md), [cinematics.md](cinematics.md),
[rendering/sprite-sheets.md](rendering/sprite-sheets.md).

The [fixed G2 acceptance checklist](rendering/integration-roadmap.md#g2-acceptance-checklist)
is the current verification record. Dated implementation reports below are
historical evidence, not a second list of pending decisions. Completed sequence,
counter and authoring checks are not reopened by their earlier partial reports.
Current-major compatibility support and separately scoped field/summon work do
not add new G2 release gates.

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
'y' => 0]`. Optional `fit => contain` preserves the current source-frame aspect
ratio inside the authored cells box; omission or `fit => stretch` preserves
existing independent width/height sizing. Containment uses the frame crop, not
the complete atlas, and retains the drawing anchor. Field transport rounds the
fitted dimensions once to whole pixels; this never changes ground occupancy.
Battle playback also contains inside the available canvas before applying its
pivot, direction and edge clipping. Image dimensions come from the current file. Overlapping
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
retirement, connected-piece effects and fractional layer offsets for piece effect anchors.
Claude owns
source-preserving Editor cue-kind, map-effect, piece-effect and binding
authoring/validation; that validation/binding work has landed. Standalone
timeline authoring and preview are integrated on local Editor develop `9fd749f`,
using the committed directional Engine contract described below. This does not
complete the remaining field presentation features.

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
exclusive legacy/timeline fields and shared loading/loop validation. Andrew
has authorized standalone timeline editing/preview; it is integrated on local
Editor develop `9fd749f` against Engine `7db56dc`.

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
work in Phases 1 and 2 below. Production artwork bindings are locally integrated;
the fixed checklist records completed native command/result checks and the
unobserved live Terminal and walking-triggered entry checks. Those limitations
are recorded without another delivery-blocking inspection loop under Andrew's
October 6 shipping instruction. Last Legend's record and
field-consumer migrations are complete; retiring the current-major compatibility
reader is not a G2 requirement.
Existing pose artwork alone does not implement this sequence.

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

Recipient identity is owned by each action's scope, not by its presentation.
Player selection, enemy AI and queued execution now share `BattleTargetPolicy`
for side, alive/dead/any status and one/all/random counts. Execution revalidates
the confirmed recipients without crossing sides or widening one recipient to
all opponents; valid random recipients retain their identities. Explicit item
scopes are preserved, with recovery compatibility only for default-NONE items.
The living-only execution filter, all-opponents fallback and AI self-target
fallback are removed. With no eligible recipient, the turn is consumed without
starting presentation or charging a command cost. Synthetic real-caller tests
cover revival and group healing in both battle engines, terminal/graphical and
normal/reduced modes, including pause, exactly-once impact/cost and cleanup.
Those CPU checks do not replace native visual acceptance.

HP and MP feedback belongs to the actual effect application, not to the net
change across an entire command. The shared skill executor and item action
record clamped resource losses and restorations separately for each recipient,
including the caster when a drain restores them. Command MP cost is excluded
from those effect results. Opposed effects remain visible even when the final
resource value is unchanged; typed HP hit results retain their internal drain,
critical and elemental outcomes. Both battle engines, terminal and graphical
presentations, and reduced motion use this same result contract. The command
runner captures only a result produced by its own resolution, so a reused
action that cannot execute does not replay historical damage, healing or
reactions. Gameplay amounts, scope and cost rules are unchanged.

HP damage feedback shows the full resolved damage, including overkill, rather
than the target's remaining HP. `CombatTargetResult::getResolvedHpDamage()`
sums actual hit loss plus overkill without counting the same resource
measurement twice. It uses measured loss when no typed HP hits exist. The
shared formatter consumes this value for both engines and renderers; it does
not use raw magnitude before defence, Critical, guard or elemental resolution.
Healing and MP feedback remain actual resource changes. Drain, defeat checks
and simulation statistics continue to consume actual HP loss, not overkill.
Queued attack, physical/magic skill, item, drain, repeated-hit and group-action
regressions preserve costs, reaction roles and cleanup in normal/reduced modes.

October 3 combined Engine verification after exact trigger-cell integration:
4,264 tests passed, one skipped, 86,800 assertions; full PHPStan passed.
The targeted selection, AI, command-execution and graphical-presentation run
passed 305 tests with 5,677 assertions. These results include the preserved
local UI work; they are not an isolated clean-commit or non-macOS native run.

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

Graphical battles omit KO captions and KO result text: the fallen pose identifies
the state. This removal applies to themed and unthemed presentation, normal and
reduced motion, attacks, counters and own-turn state ticks. The shared graphical
feedback projection filters the typed KO role without parsing localized text or
mutating the original results. Terminal KO feedback, damage numbers, gameplay
state, reaction duration and the hold before victory/defeat remain unchanged.

Terminal uses a deliberately simpler presentation: one step forward, an
announcement, small anchored source/target glyph effects and foreground colour,
readable results, then one step back. It does not animate battle character poses
or interpolate their movement. Graphical recoil, shaking effects, fullscreen
flashes, summon title cards and fades are removed from the terminal command
presenter, not from graphical presentation or the shared logical cue lane.
Concurrent terminal pulses cover every distinct caster/target instance in one
foreground-only overlay, not just the first recipient or last segment. The
shared live-buffer read can exclude that overlay during atomic composition;
completed-frame snapshot gates remain intact. Glyph effects are drawn before
the pulse reads its underlay, so they are not replaced by old battler text.
Styled gaps and whole wide glyphs are preserved, unchanged redraws emit no
terminal output, and changing/cancelling the command removes its pulse. Screen
pulses and invisible commands remain suppressed by this simpler presenter.
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

Andrew approved explicit opt-in counterattacks on October 5. Actor/enemy,
state and learned nonmagic-ability records may declare
`'counterAttack' => ['skill' => 'Catalogue Skill Name']`; null/omitted remains
off. `CounterAttackRule` validates the same project-bound skill catalog used
by runtime and Editor: a battle-usable basic/special action for one living
opponent, without summon or required-weapon conditions. Summon eligibility
comes from that project's authored cutscenes, not a battle-global registry or
the current working directory. No Last Legend counters are enabled by default.

`CounterAttackResolver` uses typed landed physical-damage results and active
combatant identity. Each distinct living, action-capable recipient can respond
once, after the original attacker's complete return. Innate, applied-state and
stable-name-ordered learned-ability rules select the first affordable response.
Life, membership, the granting rule, action blocking and resource affordability
are rechecked before execution and at impact. Both battle engines enqueue the
response through the ordinary command timeline, including poses, results,
reactions and return; response results never create counter chains. A counter
does not consume the responder's normal turn/gauge, clear guarding or tick its
state durations. Cancellation, exceptions and scene exit clear owned response
work. Simulation uses the same resolver and isolated battler clones, preserving
caller HP/MP, stat stages, guarding and live state durations on success/failure.
Synthetic normal/reduced/terminal, multi-hit/target, KO, affordability, invalid
catalog, cancellation and cleanup checks pass. Continuous native counter checks
are complete in normal and reduced motion for both engines, including original
return before response, lethal fallen art and cleanup. No rates or balance
defaults were invented.

### Local Battle Runtime (October 2026)

`ActionExecutionState` starts a `BattleCommandRunner` instead of running a
blocking effect loop. Both Traditional and ATB advance its
`BattleCommandPlayback` from the PHP battle update. `BattleCommandTimeline`
owns advance, announcement, source, target, reaction, return and finish on
one 120 fps cue lane. Effects retain their declared timing authority;
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
An image-only graphical target loads the explicit terminal sequence from the
same timeline identity; meaningful legacy cells remain its compatibility
fallback. An authored glyph/text track supersedes that fallback;
neither inspection path can fire extra cues or resolve combat again. The target
stage lasts long enough for both the graphical effect and any retained terminal
treatment at their own authored frame rates. A shorter image sequence must not
truncate later terminal strokes. Explicit target impact cues keep their authored
time; absent a cue, resolution occurs after the complete shared target stage.
Legacy cell-frame records are imported into the shared playhead without
rewriting their source. Timeline-only numeric bindings no longer manufacture an
empty one-frame compatibility delay. Authored blank multi-frame holds, populated
cells and cue-only compatibility records remain. `AnimationPlaybackSession` now delegates traversal to
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
native acceptance. The previously orphaned explosion has migrated to a shared
timeline, retaining its original source files and remaining unbound to combat.

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

Battle image tracks also accept `attachment => center|head|ground` and
`pivot => ['x' => .5, 'y' => .5]`, both at track level, not per keyframe.
`EffectImageAttachment::cases()` is the shared authoring option inventory.
Omission preserves the image-centre pivot and current artwork-centre attachment.
The pivot is a finite normalized point in the selected sheet cell (0..1 on
each axis), independent of the PNG's current resolution. `ground` attaches
that point to the subject's stable ground pivot, following actual advance,
recoil and shake, rather than the current posed rectangle or its transparency.
`head` uses the current artwork's top centre. Explicit attachment is refused
on screen anchors; the image pivot remains usable there. Mirroring reflects
the pivot too. Viewport boundaries crop source pixels at unchanged density
instead of translating the image away from its attachment.

Last Legend's approved blue preparation and green healing sheets use their
ring centres as pivots and `ground` attachment on caster and target respectively.
No art or terminal sequence was changed. Field image tracks now accept the same
finite normalized `pivot` point, with bottom-centre `{x:.5,y:1}` when omitted.
The selected image point meets the target cell's ground anchor after fit/crop,
movement and camera projection. Gameplay cell positions, collision and ground-Y
ordering stay independent of that image point. Field tracks still refuse battle
`attachment` and `facing` semantics. Fractional gameplay cell offsets are not a
substitute for image placement.

Protocol 2 negotiates field sprite pivots through optional `sprite_pivot`.
Supported renderers receive the point with the sprite; older renderers keep
bottom-centre placement with a once-per-session diagnostic for other pivots.
Omitted points retain existing wire data. The GUI's shared image picker edits
the authored point through source-preserving records and undo; field and battle
defaults differ without being written into the source.

Battle sequences may declare `cadence => fixed|battle_phase`, independently
in each presentation variant. Omission means `fixed`: authored fps/defaultSpeed
continues to own timing. `battle_phase` spreads the sequence's `lengthFrames`
over the consumer's source `BattleTurnTimings::actionAnimation` or target
`effectAnimation` budget, honoring defaultSpeed. Battle pacing already applies
the selected battle speed. The existing compatibility floor for a zero budget
is 0.01 seconds for the complete beat, not a new fixed frame rate. This choice
is valid only for battle, once-only playback; field loading and loops refuse it.
Paced source omits `fps`; supplying it is refused because it would introduce a
second, irrelevant timing authority. The compiled nominal rate is internal,
not an authored rate. Fixed source still requires its explicit `fps`.
`EffectCadence::cases()` supplies constrained authoring choices.
`EffectPlaybackTiming::createForBattlePhase` supplies the owning phase budget.
Standalone `EffectPlaybackSession` inspection of a paced sequence must supply
positive `phaseDurationSeconds`; absent timing is refused, not inferred from
fps. Preview speed scales that supplied budget without rewriting the source.
`BattlePacing::fromBattleUiConfig` and `getAnimationPace` share project pacing
resolution with standalone Editor previews without mutating global configuration.
Glyph tracks anchored to `target` default to its centre, preserving legacy
centre offsets without embedding legacy geometry in the new source.

Battle image and glyph tracks may declare `facing => east|west`: the direction
for which their art, glyphs and horizontal offsets were authored. Playback
mirrors a directional track toward each actual recipient using current caster
and recipient ground placement, including command advance, recoil and shake.
The posed-battler presenter supplies those ground anchors explicitly: transparent
padding, asymmetric pose canvases and extended weapons cannot decide facing.
Rectangle-only hosts retain their rectangle-centre placement contract and
bottom-centre ground point. Image attachment is independent of direction;
legacy glyph anchors retain their current artwork head, centre or feet geometry.
Caster-anchored tracks face the first distinct target;
self-targets and coincident horizontal positions preserve authored orientation.
Screen anchors and non-directional track types refuse `facing`. Radial impacts,
healing and other undirected effects omit it. No actor-name or artwork-path
inference is involved. Terminal playback mirrors its small glyph strokes and
offsets, not graphical poses or full-screen flourishes.

Battle image keyframes additionally accept boolean `flipX` and `flipY`. These
mirror the selected source cell, not the entire sheet or its destination. The
automatic horizontal mirror combines with the authored `flipX` (two mirrors
cancel), allowing a second slash to reverse the first while both adapt to the
attack direction. Frame order, holds, crop selection and combat cues do not
change. Field tracks currently reject these fields rather than silently
ignoring unsupported transforms. GPUI negotiates `canvas_image_flip` under
protocol v2 with `graphical_canvas`; its bounded image-variant cache preserves
alpha and shares brightness preparation. A backend without that capability
diagnoses and omits only an unsupported mirrored image, retaining the
battlefield, poses, command/result feedback and combat lifecycle.

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
Editable projects instead declare `Data/Presentation/battlers.php` as literal
data shared by actors and enemies. `BattlerBindings` validates explicit
identities, base `artwork`, named `poses`, normalized `pivot` values, animated
sheet intent and the same `scale` profiles/reference described below. It
constructs the existing typed values, not a second sizing model. Image paths
are asset-root relative and dimensions are read from current files. Code-owned
and data-owned bindings for one identity, or two scale-reference owners, are
refused instead of silently overriding each other. `BattlePresentationCatalog::load`
combines them; `loadCode` performs the same path/type validation but lets Editor
bind proposed unsaved data before preview/save. Last Legend has moved all eight
actors, fifteen enemies and eighty party roles into this file, with a one-off
value-equivalence check confirming unchanged artwork, pivots, scale and UI.
This mechanical migration does not accept or integrate pending art revisions.
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
### Battlefield Condition Badges And Effects

Current state/stat-stage identity stays on each battler. Badge bindings and
geometry belong to the theme in `Data/Presentation/battle.php`; optional animated
effect bindings remain in `config.php`. Neither is another gameplay state
catalog, per-character drawing code or saved artwork. Persistent graphical
condition badges and optional animated effects are independent consumers of
`BattlerConditions::getEntries`; `HasStates` and `HasStatStages` remain the only
gameplay owners. Missing animation must never turn a buff into white field text
or hide its identity, polarity or magnitude.

The battle skin's existing `MenuIconRegistry` accepts dedicated semantic keys:

```php
'status.stat.attack.positive' => 'Icons/attack-up.png',
'status.stat.attack.negative' => 'Icons/attack-down.png',
'status.state.poison' => 'Icons/poison.png',
'status.state.stun' => 'Icons/stun.png',
```

All seven stat identities (`attack`, `defence`, `magicAttack`, `magicDefence`,
`speed`, `grace`, `evasion`) have independent `positive` and `negative` keys.
States use `status.state.<State::id>`, never display names, glyphs or directories;
poison and stun therefore retain their dedicated identity after a rename.
Procedural badges include an up/down arrow (or neutral marker); dedicated
positive/negative artwork owns its own polarity treatment. A readable signed
stage number remains outside every stat icon. State polarity comes from authored
`StateDisposition`, not a name or formula. Stat footers show only the signed
stage (for example `+2` or `-3`), leaving the pictogram to identify the stat.
State artwork has no redundant footer; procedural states retain PSN/STN for
poison/stun, or a compact authored name for other states.
The existing Info action retains full condition names and descriptions.

Icons are optional asset-root-relative bindings. Exact keys are required;
the registry's unrelated `unknown` icon is never used for a known condition.
Current PNG dimensions are contained inside the icon viewport, with no authored
hash, duplicated size, stretched icon or gameplay identity change. Missing or
invalid bound art is diagnosed through shared preflight and replaced by a
procedural pictogram, not accepted as production artwork. Unbound art has a
useful default: distinct sword, shield, wand, ward, boot, sparkle and eye
pictograms, plus poison skull and stun stars, on opaque framed badges. Bound
semantic art is a complete icon, including its own frame and color-independent
polarity symbol; the Engine does not paint a second frame or arrow over it.
Signed magnitude remains visible beneath the artwork, never over its symbol.
Color is supplementary, not the only distinction. Procedural drawing uses
existing canvas background spans, needs no composite capability or raster cache,
and batches primitive fills and footer font lattices to respect the existing
canvas layer budget.

`BattleUiSkin::conditionBadges` defaults to `BattleConditionBadgeStyle(pixelSize: 2,
gap: 4, maxColumns: 3)`: a restrained 32px-square icon, not a half-width
pictogram slot. Artwork is contained across the full 32px viewport without
extra Engine decoration. The procedural pictogram has a 28px content area
inside its 32px frame, with its polarity marker in the upper corner.
The opaque signed-stage footer sits below the icon in a separate 12px row;
the default stack cell is therefore 32px wide by 44px high. State art needs no
footer, but shares this cell geometry so stacks remain aligned and protected.
Nine concurrent conditions therefore form a compact 3x3 stack, without
shrinking the individual icons to fit.
Footer cells are at least 6px wide and 12px high rather than shrinking the font
along with the procedural pixels. Each tile is 16 logical pixels square at the
configured pixel size; pixel size supports 2..8, gap 0..32 and columns 1..8.
The project catalogue loads typed PHP objects directly, including the trailing
named `conditionBadges: new BattleConditionBadgeStyle(...)` argument on its
`BattleUiSkin`; it is not an array-only or constructor-only setting. Palette
comes from the skin's existing text/ink/healing/damage/focus roles. Badge groups wrap at the configured
column limit, stay attached to the currently presented combatant instance, and
use shared battlefield placement around battler, UI, cursor and badge obstacles.
Their actual bounds protect them from notification and feedback overlays.
Insufficient space uses the shared least-overlap placement policy without
dropping badges; geometry that cannot fit the canvas is an explicit error.
These are battlefield badges, not extra icons in the cramped status HUD.

Badges project live state on every frame, including reduced-motion and
lower-capability frames. Optional animation gaps, missing variants, failures,
pause-clock phase and pose changes do not control badge visibility. Cure, expiry,
zero-stage reset, KO, revival and reserve replacement use current gameplay and
roster identity; no second status store or renderer-owned expiry timer exists.
KO badges are not drawn and no KO label is restored. Authored artwork remains
separate Art work, not generated or admitted by this Engine implementation.

Optional animation bindings remain:

```php
'ui' => ['battle' => ['conditions' => [
  'states' => ['state-id' => 'state-aura'],
  'statStages' => ['speed' => ['positive' => 'speed-up', 'negative' => 'speed-down']],
]]],
```

State keys are existing authored `State::id` values, independent of display name;
stat keys are `HasStatStages::buffableStats()` and roles are positive/negative.
Effect values use the existing `EffectTimelineLibrary` stable IDs and
`Animations/<id>/<id>.timeline.php` resources. Unknown configuration keys,
invalid groups/roles and unsafe timeline references are refused by the shared
binding boundary. Editor/GUI must select states, stat roles and timelines from
their catalogs, preserve unrelated source and refuse unsupported edits before
writing. This Engine slice does not implement those editors or admit assets.

A condition timeline uses fixed authored fps, `playback => 'loop'`, optional
`loopFrom`, a visible static `restFrame`, and battler-attached image/glyph/text
tracks. Existing target/caster anchors refer to the affected battler, with image
head/center/feet/ground attachments and pivots unchanged. Screen/stage anchors,
flash, shake and all cues (including audio and applyEffect) are rejected for
persistent conditions. This prevents repeated gameplay/audio and flashing at
rest. Multiple conditions compose independently, including clear-before-draw
inside each condition, rather than erasing another condition's layers.

The field's existing pause-aware pose clock supplies elapsed time. Conditions
join its current loop phase when applied; sampling does not restart a private
clock on application or redraw. Sampling uses
`EffectPlaybackTiming` and bounded modulo arithmetic, not frame-by-frame loop
traversal, restaging or a renderer timer. Reduced motion samples only the authored
rest frame. Removal, expiry, KO and stage reset immediately re-project current
gameplay; no renderer-owned condition cache must be synchronized. Owned discard
releases the field binding without curing persistent gameplay states.

Terminal and graphical variants use the existing `presentations` contract and
compile independently. Terminal never loads an unselected graphical PNG. Omitted
bindings, missing variants or diagnosed invalid art retain useful terminal
state glyph/name or signed stat-stage text. Graphical animation-owned white-text
fallbacks are removed; static semantic badges remain independently visible.
Explicit gaps in an otherwise valid authored loop remain animation gaps, never
badge gaps. No status-panel icon is added. Missing graphical resources are
diagnosed, not treated as completed production art. PNG dimensions/crops are read
through the existing shared asset preflight, not duplicated in state definitions.
Atomic and rapid in-place same-path replacements use the same shared freshness
check: current 24-byte header plus filesystem facts. Colliding inode, encoded
size and second-resolution timestamps cannot retain old dimensions. This
bounded internal cache check is not an authored asset identity or import hash;
compressed-pixel validation remains the native decoder's responsibility.

Info exposes current authored names/descriptions and signed stat stages through
the existing battle selection action. These contracts and the bounded enemy
defeat ceremony are independent of G3; headless API acceptance is not native
visual/audio or production-art acceptance. See
[graphical-battle-g1.md](rendering/graphical-battle-g1.md#battlefield-conditions-and-enemy-defeat).

The catalog's `ui` owns shared canvas dimensions, party slots and feedback
safe area; each troop enemy's `graphicalPlacement` owns its slot. Arena scenes
own only a display name, background and optional skin. Effects follow the
actual combatant instance, not an arena-side troop override; see the
[formation contract and ownership audit](rendering/graphical-battle-g1.md#troop-owned-graphical-formation).
The catalog may declare one `BattleScale(referenceActorId, referenceHeight,
actors, enemies)`. Identity-keyed `BattlerScale(relativeSize, sourceSpan,
horizontal: false)` profiles express a body unit relative to that reference.
For Last Legend, Kaelion's approved standing body is the unit. Party profiles
use standing height; creatures may use shoulder height, torso length or wingspan.
Formation slots own position, movement space and optional perspective depth,
not the underlying body proportions in reference mode.
The reference remains valid when its actor is absent from the active party.

`sourceSpan` is an authored normalized calibration of that standing/body unit
against the artwork frame's height (or width with `horizontal: true`). It is
not the selected pose's opaque height: weapons, hair, shadows, lying bodies and
transparent padding never automatically redefine anatomy. Rendering reads the
current PNG/cell dimensions and uniformly scales both axes so the calibrated
unit occupies `referenceHeight * relativeSize` canvas units. A resolution-only
replacement therefore needs no copied dimensions, hash or profile update.
Pose frames with a different source composition/density may explicitly declare
`BattlerPose::scaleSpan`; cropped frames may require a span greater than one.
Changing source anatomy still requires an Art correction, not nonuniform scaling.
Artists retain ground pivots and consistent body proportions across poses.

`BattlerSlot::displayScale` defaults to 1.0 and uniformly applies authored
slot depth exactly once after that calibration, preserving the ground point.
The displayed body unit is `referenceHeight * relativeSize * displayScale`.
This belongs to the current slot, not a character profile: reordering the party
or explicitly replacing a frontline member carries the treatment to the new
occupant. Base art, every static/animated pose and formation previews share the
placement path; legacy width/contain placements use the same last depth step.
`placeAtScale` receives pre-slot pixel density. Missing pixels still retain the
unscaled name anchor. Ground-attached effects use the unchanged authored ground
point plus actual movement, never a depth-scaled transparent image edge.
The October 5 front-party-slot treatment is authored as 1.05 in Last Legend;
the Engine does not infer a front slot or bake in that game's 5% choice.

Reference catalogs require a profile for every registered battler and reject
competing `BattlePoseSet::displayWidth` registrations. They do not silently
shrink invalid placements into formation boxes. Catalogs without `scale` retain
legacy base `displayWidth`/contain behavior for compatibility; Last Legend has
removed those individual-width authorities, including empty enemy pose sets.
This same reference contract applies to resting/command roles, animated cells,
base-only degradation, previews and reduced motion. Gameplay, terminal geometry,
target identity and effect timing do not depend on it. Unregistered or unusable optional roles
retain available registered resting/idle artwork, preserving its scale and
pivot, before falling back to base artwork with diagnostics. Damage and heal
may use that resting image with actual-result tint/recoil when reaction art
has not been supplied; afflicted and enhanced are not reaction-art aliases.
Only a true knockout image is shown at full opacity for a defeated actor.
Dimensions and source crops come from the current file, not an art receipt
or frozen production hash. Missing/invalid optional art is diagnosed and
does not alter command outcomes.

Unavailable base pixels have no source calibration. The shared
`BattleFormationLayout::getBasePlacement` receives null and retains only a
1x1 name-fallback anchor; it never scales that anchor against a real image's
body profile or legacy display width. A valid pose-only image still uses its
real profile, or contain/display-width fit in an uncalibrated catalog. Runtime
and Editor preview use this same path. File loss/replacement remains local to
the artwork and does not freeze availability or corrupt combat state.
The name-only footprint stays within the canvas even at an edge anchor;
authored ground coordinates remain unchanged. Selection and queued feedback
use text when pixels are absent, rather than attaching image-based cursors or
focus textures to that one-pixel metadata footprint.

`BattleCanvasLayout` may explicitly supply `battlerArea`, `enemyArea` and
`partyArea`; none is inferred from canvas halves or repurposed from the feedback
region. Party and enemy regions can be authored on either side. Cursor checks
respect both the common field and the battler's side region.
`BattleFormationLayout::getClearanceDiagnostics(assetRoot)` reports member-order
party/enemy diagnostics through shared `BattleFormationClearance`. The check
reads current visible pixel envelopes, respects crops/flips/clips, checks
field/side containment, pairwise overlap and cursor clearance, and never moves,
clamps or resizes artwork. Runtime preparation logs the same diagnostics once
when regions are authored; authoring/validation can expose them before play.
`PngAssetBounds` uses optional GD PNG inspection with a bounded cache invalidated
by current content, not an import-integrity identity gate. Missing/corrupt art
and unavailable inspection support are explicit diagnostics, not a falsely
successful pixel check. GD is not a new required runtime dependency; native
decoding remains rendering authority. Regions and warnings are graphical only
and never change terminal geometry, collision, gameplay or saves.

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
Concurrent flash/reaction tints share one surface across opaque subjects at
the same depth, with each subject's current alpha crop, placement and clip
retained. This removes per-recipient opaque raster allocation, which exceeded
the eight-surface limit in larger multi-target commands. Faded subjects remain
separate so overlapping tint layers preserve group-opacity semantics. Combined
raster and operation budgets still apply unchanged to the complete frame;
overlay protection covers subject bounds, not transparent gaps in the union.
The mandatory graphical-battle compositing requirement is removed. Canvas,
source rectangles and clip/opacity remain required, as do glyph effects for a
skinned UI. Negotiated compositing is optional for battler tints: unsupported
or over-budget tinting is diagnosed and omitted without dropping valid poses,
effect images, result feedback or gameplay. The protocol still refuses
unnegotiated composite packets; screen handoffs retain their own compositing
requirement.
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
impact. Those original headless checks alone did not establish native pixels,
total-resident resource budgets, complete Editor source-preserving authoring
or Linux/Windows support. They are not the current completion state.
The fixed G2 checklist now records completed native command/effect/counter
sequences, real-scene victory/defeat ordering and source-preserving authoring
checks. Native normal/reduced real-scene runs also show victory/level-up pages
and graphical Game Over; the intermediate defeat message's terminal styling is
a separate shared UI coverage gap. Live Terminal and ordinary walking-triggered
entry observations remain explicit testing limitations, not claimed passes or
an indefinite delivery-blocking loop. Total-resident resource
budgets and non-macOS qualification are not established by those checks.
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
integrity or authorization gate may be bypassed. Approved effect wiring is now
integrated locally and its normal/reduced native sequence checks are complete;
shared-folder delivery alone was not its acceptance.
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
Native previews for the complete accepted set have since closed cleanly,
and the fixed checklist records native observation of all 80 roles, 40 resting
views and eight complete attacks. That current static-art acceptance is not a
claim of future animated character artwork or remaining entry/results checks.
The Last Legend graphical integration fixture advertises optional compositing
and checks the remaining mandatory capabilities explicitly. Engine coverage
now exercises real Traditional/ATB scenes with and without compositing in
normal and reduced motion: missing tint support retains the graphical battle,
poses, actual-result feedback and exactly-once outcomes, with a diagnostic.
This corrects the former whole-battle fallback requirement; it does not weaken
the composite protocol gate, screen-handoff requirements or resource budgets.

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
   editor picker). Runtime name-based selection has since been removed in
   favor of semantic role defaults; migration diagnostics are not runtime fallback.

#### Phase 0 runtime contract

The live action state now consumes Phase 0 through `BattleCommandRunner` and
the shared command session described above, not through `AnimationPlayer` or
`SummonCutscenePlayer`. The unused protected blocking battle methods
`resolvePresentedAction`, `playActionAnimation`, `playSummonCutscene`,
`displaySummonTitleCard` and `playSummonTransition` are removed. Standalone
preview players remain available and use the shared effect traversal.

`effectTiming.mode` selects exactly one gameplay resolution: `cue` fires when
the named `cueId` is crossed; `frame` (also accepted as `explicit_frame`) fires
at the start of that frame; `end` fires after the last frame and before the
outgoing transition. Frame-zero cues are delivered once. Presentation cues are
separate from combat resolution; `applyEffect` does not override a different
authored timing mode. Render failures, including renderer type errors, are
diagnosed without stopping the logical cue clock or losing the command. The
removed blocking path's renderer-type-error abort policy is not retained in
live combat. Gameplay failures propagate without retry; failing cleanup cannot
replace the original gameplay exception. Missing explicit ids and malformed
optional assets are diagnosed without suppressing gameplay.

Logical command retirement is independent of output success. Normal completion,
cancellation and failures detach playback after Console's atomic frame either
succeeds or rolls back, so rollback cannot resurrect a retired command's flash.
The field releases owned state before any erase writes; cleanup continues through
individual presentation errors. Non-painting overlay removal retires only the
command's overlay without using a failed or handed-back terminal sink. Ordinary
overlay updates retain atomic repaint and rollback. An obsolete command never
clears presentation owned by its replacement.

Reduced motion holds the authored rest frame for the complete effect stage,
while logical cues retain their authored times. A legacy cell import defaults
to its final frame. Flash, shake, translation, summon title and fade motion are
suppressed by the presenter rather than changing combat timing. Fresh action-
state checks exercise cue/frame/end timing in graphical and terminal modes,
rest-frame holds, renderer failure and cleanup: 37 passed / 245 assertions.

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

A null skill reference uses an explicitly declared semantic animation default,
not its display name. `ActionAnimationResolver::getSupportedRoles()` supplies
the closed role list for validation and Editor selectors: `attack`, `skill`,
`restorative`, `attack-unarmed`, and `attack-<type>` for every `WeaponType`.
Both AttackAction and BasicSkill without an explicit id use the actor's current
equipped weapon type, otherwise the actor's authored `attackStyle`, otherwise
`attack-unarmed`. Actor data may declare `attackStyle` as a `WeaponType` or its
string value (case-insensitive); invalid values are rejected with source context.
This describes the character's basic weapon, not an inventory item or a stat
bonus. It is rebuilt from current actor data when loading saves, never serialized
as mutable state. Equipping a different weapon type overrides it; removing that
upgrade restores the base style. Unclassified equipped weapons and non-equipment
creatures use neutral `attack`, not an inferred blade. No class/name inference.
Other skills retain restorative/buff-magic or generic-skill defaults. Explicit
skill/item ids remain authoritative. Missing or duplicate role bindings are
diagnosed and omit only that effect; they never silently substitute a slash.

Runtime and Editor inspection now share `ActionAnimationResolver::resolveForAction`
and `findEffectBindings`. The latter receives actual candidate commands on the
ordinary animation route and returns their source/target effect bindings; resolved
summons retain their own choreography. No match means unbound, not necessarily
field-only. `AnimationLibrary::createFromAnimations` accepts an explicit snapshot
of unsaved records without reading another running project's assets. Editor
battle-effect previews keep the selected animation's paired effects, action pose,
target policy and pacing, and use the production command's authored-frame mapping
for seek and tab changes. They grant no abilities and execute no combat or audio.

#### Temporary Cinematic Stage (Local G3 Foundation, October 6)

Graphical summons may own a temporary presentation space rather than stretching
an effect around a battler. It is not a map, combatant, target or global camera.
`CinematicStage` validates the space; `CinematicStageFrame` projects all layers
and attachments through one camera; `CinematicStagePresentation` composes retained
images/covers through existing canvas primitives. Production battle and seekable
preview consume the same frame. Claude's local Editor/GUI stage authoring is
implemented; native authoring and battle acceptance remain open.

In a paired timeline, `stage` belongs only to `presentations.graphical`. The
Terminal sequence retains its own timing, glyphs and contact cue and acquires
no graphical assets. A legacy single sequence may contain `stage`; Terminal
does not parse or draw it. New authoring should use the paired shape. The shared
timeline-field schema includes `restFrame`, `presentations` and `stage`, so new
fields are not accidentally written to the definition data file.
Editor consumers read `SummonCutsceneDefinition::PAIRED_TIMELINE_FIELDS` for
the paired wrapper and `CinematicStage::EASINGS` for camera/cover options;
validation and authoring must not maintain competing vocabularies.

Stage fields use one shape each, with defaults only when a field is absent:

| Field | Contract |
| --- | --- |
| `canvas` | Required `{width, height}` integer stage units, each 1..4096. |
| `startFrame`, `restoreFrame` | Required authored frames, `0 <= startFrame < restoreFrame < lengthFrames`. Stage replacement is active from start inclusively to restore exclusively. |
| `background` | Optional `black`, `white` or `#RRGGBB`; absent means black. |
| `subjects` | Optional list, absent means empty, at most 32 records: `{id, position:{x,y}, size:{width,height}, pivot?, attachments?}`. Size is a registered presentation box, not duplicate PNG dimensions. |
| Subject `pivot` | Normalized `{x,y}` in 0..1; absent means `{x:.5,y:1}`. |
| Subject `attachments` | Optional list of at most 32 unique `{id,x,y}` normalized points in that same registered cell; absent means empty. Use existing image-point pickers for ground/chest anchors. |
| `camera` | Required ordered key list, first key at frame 0: `{id, frame, focus:{x,y}, zoom, easing?}`. Zoom is .125..4. |
| `covers` | Optional ordered key list, absent means none: `{id,frame,color,opacity,easing?}`. Opacity is 0..1. Nonempty covers start at frame 0 and end at `lengthFrames-1` with opacity 0. |

Camera/cover lists allow at most 10,000 keys, unique stable IDs and strictly
increasing frames. Optional easing defaults to `linear`; `hold` and `smoothstep`
are also supported. The outgoing key owns interpolation. Cover color changes at
the next key; opacity interpolates. Points in stage units are finite numbers in
-32768..32768; presentation sizes are 1..4096. Explicit null is not absence.

Stage image tracks reuse `asset`, `sheet`, `fit`, normalized `pivot`, flips and
keyframe crops. Set `anchor: stage` and a `placement` map. Placement has optional
`position:{x,y}` (absent means zero offset), `subject` and `attachment` references,
and `size:{width,height}`. Without a subject, size is required; with one, absent
size inherits its registered box. An attachment requires its subject. References
must use selectors, not typed names. Named points remain stable across body poses.
Track `zIndex` defaults to 0 and is bounded to -500..500. Keyframe `position`
defaults to zero and means stage-unit offset; `opacity` defaults to 1. Ordinary
battler `cells`, `attachment` and `facing` are not stage geometry. The image pivot
retains its existing absent center default. Read current PNG dimensions at draw
time; replacing supported art does not change stage identity or require hashes.

The existing sequence `restFrame` is also the reduced-motion stage view, not a
second frame setting. For summons its absent default remains the final sequence
frame; stage authors must select a rest frame inside the active interval with
image coverage. Reduced motion holds that view and its camera, suppresses covers,
and restores at the same logical boundary. The stage requires fixed cadence.

Stage covers replace the legacy summon-in/title/out presentation phases for
stage-owned summons only; ordinary summons keep them. Nonzero legacy transitions
with stage covers are rejected as conflicting ownership, not silently ignored.
On restoration the original arena, formation and current battler state return;
foreground image tracks may continue for their independently authored duration
over that live arena, and the white cover can continue fading. This permits a
cut from a close cinematic view to side-profile battle at release without
baking the current arena/enemies into artwork or erasing the beam on the cut.
End the scenery track at restoration, author the release view and camera for
that shot, and retain only the intended foreground layers. Reduced motion
returns directly to the arena without those continuing stage layers.
Combat resolves once at its authored cue,
but graphical damage/reaction feedback starts after the target sequence clears.
Terminal feedback keeps its own ordinary cue timing. Cleanup/cancellation release
the stage without mutating the world camera or replaying combat. Notifications
retain their higher application-owned priority above stage images and covers.

#### Caller-Owned Rest Stages (Local G4 Foundation, October 7)

`EffectTimelineLibrary::loadStage` and `compileStage` admit standalone graphical
stages independently of battle/summon profiles. They require fixed, once-only
playback, stage-image tracks, no cues and a safe reduced-motion rest frame.
`CinematicStageSession` takes an explicit asset root and consumer duration;
`advanceTo` accepts monotonic elapsed time, while `getCanvas` is read-only.
Backward preview seek requires a fresh session or pure stage projection.

An inn may select a stable timeline ID through `InnOffer::presentation`; absent
or null uses the optional `graphics.inn.presentation` project setting. The
shared asset picker must select references, not infer filenames or duplicate
artwork metadata. Timelines remain `Animations/<id>/<id>.timeline.php`.

`PartyStageSelection` also admits a project-owned descriptor with a required,
explicit treatment. The shared project default can serve every inn:

```php
// Intentional single-leader shot, not a complete-party montage.
['treatment' => 'leader', 'leaders' => [
  'actor-id' => 'leader-rest-stage',
  'another-actor-id' => 'another-leader-rest-stage',
]]

// Optional exact-membership scenes, only for the compositions actually authored.
['treatment' => 'party', 'parties' => [
  ['actors' => ['actor-id', 'another-actor-id'], 'timeline' => 'pair-rest-stage'],
]]
```

The API is `new PartyStageSelection(string $treatment, array $leaders = [],
array $parties = [])`, `fromArray(array): self`, `toArray(): array` and
`selectTimeline(Party): ?string`; constants `LEADER` and `PARTY` name the two
treatments. `toArray` returns `treatment`, `leaders` and `parties`, with the
unused binding collection empty. `getTimelineIds(): list<string>` enumerates all
distinct bound stage identities without a live party for reference validation.
`InnOffer::fromData` accepts these descriptors
or the readonly selection object, as does `InnRestPresentation` for the project
default. Unknown keys/treatments, mixed nonempty binding sets, invalid IDs,
repeated actors/compositions and oversized collections are refused. Collections
are bounded to 1024 leaders, compositions and actors per composition.

Only current `Party::leader->actorId` selects a leader shot. Exact scenes match
the entire current `Party::members` set, including reserves, independent of
ordering, battle slots, display names or artwork. No bound leader/exact lineup
means no optional stage with a diagnostic; it never substitutes a different
guest. Removed from the interrupted draft: implicit exact-party-to-leader
fallback. Leader treatment must be an intentional Game-authored choice. It
requires only per-actor shots, not a combinatorial scene library. Exact scenes
are optional explicitly authored compositions, not a requirement to cover every
possible lineup. Game owns all timeline, subject/role and art bindings and must
depict only the selected leader or declared party. Legacy string references
retain their previous caller-selected stage semantics, without inferred guests.

Selection refreshes against the actual party during the rest. A changed lineup
or leader replaces only this rest's handle and seeks to the current elapsed
time on the same full caller duration. Reordering an exact lineup retains its
handle. Unavailable selections can acquire a later valid binding; a missing
timeline is attempted once per selected identity, without per-frame retries or
repeated warnings. Monotonic finite progress is enforced even during graphical
absence. Cleanup goes through the originating presentation manager once per
owned handle, preserves sibling stages/covers and never restarts gameplay time.

The generic `inn` command declares optional `presentation` through
`ScriptCommandReference::STAGE_TIMELINE` (`stage_timeline`). Project commands
can reuse the same resource kind without acquiring summon semantics. Editor's
Sleep-event and project-default pickers admit stage timelines, preserve an
unset key's absence and support clear/undo/source round trips. The corresponding
script-command reference-category mapping and actual Game artwork binding remain
outstanding; the Engine declaration requires no release before local integration.

The registered `inn` command and any project-declared `STAGE_TIMELINE` reference
accept the same descriptor through shared `ScriptCommandField` validation, as
well as scalar/null values and the validated selection object. Invalid
descriptors report the command field path; other resource reference kinds retain
their existing scalar validation. Nested cinematic commands use that same
contract. No gameplay or Terminal behavior is introduced by reference validation.

Structured authoring is a separate, outstanding contract. Existing Editor config
validation and scalar reference paths do not safely author descriptors;
configuration leaf flattening
must not turn actor/timeline bindings into ordinary text or allow a scalar edit
to overwrite a descriptor. The Editor owner must preserve the source structure,
validate through `PartyStageSelection`, enumerate `leaders` values and
`parties[].timeline` as stage references and `parties[].actors` as actor references,
and use shared resource pickers with clear/undo/source round trips. Unsupported
source edits must be refused before writing, not flattened. Richer authoring
belongs to the GUI; no graphical TUI controls are required. Runtime support and
DTO round trips are not claims that those Editor capabilities are complete.

`InnStay` owns payment, recovery, audio, wake and the existing five Terminal Z
beats. Its waiting callbacks advance the graphical stage over exactly that
existing rest duration; a blocked gameplay update cannot freeze it. The
presentation manager owns independent handles and composes them through the
existing overlay path. Releasing one rest preserves sibling stages and parent
covers. Transfer, same-map teardown or shutdown invalidates the originating
handle and prevents stale recovery/wake. Removed behavior: interrupted stays no
longer restore originating music over the destination scene. Already accepted
payment remains debited; interruption does not report a completed recovery.
Invalid optional assets/capabilities retain Terminal presentation with diagnostics.

Earlier foundation receipts were 44 inn tests / 523 assertions, 875 related
regressions / 136,789 assertions and 90 optional-command/lifecycle tests / 615
assertions. After explicit party treatment and registered-union implementation,
the focused selection, inn, sleep/scene audio, stage and command-registry run
passes 233 tests / 1,442 assertions; focused changed-source PHPStan at level 2
reports no errors. All fixtures/analysis data are on secondary-drive scratch.
The whole-Engine 6,538 / 440,122 receipt predates these edits and was not rerun.
These checks are not Editor source-round-trip completion, artwork acceptance,
native visual proof or Linux/WSLg validation. Sleep proposals remain unadmitted
until approval is established.

The compiler version is 3. Invalid optional artwork/capability failures produce
diagnostics while preserving gameplay; they are not claims that the cinematic
was shown. Path-specific validation identifies stable subject/camera/cover rows
and image-placement/keyframe fields for the existing Editor record panes.
Paired and flat summon sequences both normalize glyph positions and cues
through the existing summon DTOs before shared compilation; separating a source
preserves its authored `[x,y]` positions without an Editor-side rewrite. Only
the selected sequence is normalized/validated, so the Terminal path never
parses an unselected graphical image sequence.
Typed image-keyframe reads and construction no longer truncate fractional
stage offsets or drop opacity/atlas-flip fields. `fromImageArray` preserves
image values, while the legacy glyph/text `fromArray` keeps integer cell
normalization. Tracks initialize image storage before adding typed frames;
raw source remains uncoerced until shared validation. Synthetic read/edit/add
checks include zero, partial and full opacity and compile through the stage
boundary. A read-only round trip of Art's proposed D'jin sequence also produces
identical runtime segments, defaults and cues without admitting any assets.

October 6 actual-engine checks cover staged summons in Traditional and ATB,
Graphical and Terminal, normal and reduced motion, and lethal/nonlethal casts.
A lethal outcome does not end execution before the restoration, whiteout and
recipient-feedback intervals finish. Graphical feedback begins after the
sequence clears; the Terminal sequence retains its independent contact timing
without a PNG. Pause freezes the presentation before contact. Interruption
before contact spends nothing; interruption afterward preserves completed
combat without replaying costs, damage or pending feedback. These are headless
production-engine checks, not native D'jin motion acceptance. The queued-command
suite passes 150 tests / 3,039 assertions. A fresh full Engine Unit run covering
these cases and the latest stage/parser/vocabulary/image-record changes passes
5,940 tests / 390,822 assertions with one existing skip. The four focused
summon/stage/real-engine suites pass 239 tests / 3,325 assertions; scoped source
static analysis is clean.

Command ownership is independent of the skill catalogue and artwork. Actor
definitions may select `attackSkill` by catalogue reference: it must be a
battle-usable `BasicSkill`. The actor's current definition owns that inherent
attack, including after restoring an old save; it is not mutable save data.
Absence uses the Engine's built-in `AttackAction`, not an arbitrary catalogue
entry or a name-based guess. Additional attacks must be learned BasicSkills.
The Skill menu contains only learned, battle-usable special abilities; summon
actions stay in Summon/Petition, and spells in Magic. Defining a skill never
grants it to actors. Enemy action patterns and explicit sandbox loadouts retain
their own grants. The old all-catalogue Attack and empty-book Skill grants have
been removed for both terminal and graphical battle menus.

Last Legend removes the universal Blade Slash id from generic Attack. It binds
sword, dagger and axe attacks to id 3 Blade Slash; staff, flail, glove, unarmed,
neutral attack and generic skill to id 1 PhysicalImpact; restorative to id 2.
The blade's two presentations declare west-facing source art. Dual Slash has
two independently timed strokes; the second graphical stroke reverses the
first with `flipX`, matching the existing crossing terminal strokes. Enemy
Burn explicitly uses its existing fire effect, not a physical attack default.
Unused thrust, ranged, lash, claw and wand weapon roles remain unbound art
gaps, not aliases to the sword. No formulas, hit counts or availability change.
This is default presentation, not inference of a specific named attack's art;
Last Legend explicitly binds battle-capable named skills and effect-bearing
restorative items to distinct shared glyph timelines. These
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

After exact trigger-cell integration and the shared targeting correction,
the October 3 Game CI run after the Home fixture correction has 1,841 passes
and one failure across 1,842 tests, with 833,992 assertions, excluding battle
simulations. The
reachability failure reports three inaccessible NPCs in Apthia's Garden Route
Control, Happyville's inn and its shop. Do not weaken reachability to hide
these; the counter/service decision remains with Andrew. The two Home tests
now use frozen historical-save fixtures and current-content invariants, not
immutable live-content fingerprints or fixed transfer coordinates. No current
map was reverted to satisfy them.
The prior validation run reported zero errors and 45 warnings; validation was
not rerun for this CI result. These results supersede the earlier Game totals
above, not the accepted-art review.

After direction-aware weapon bindings, the October 3 Engine suite passed
4,284 tests with one skip and 86,934 assertions, with PHPStan clean. GPUI's
headless macOS suite passed 196 tests with four existing ignored native/platform
checks. Full Game CI passed 1,846 of 1,847 tests (834,029 assertions); the sole
failure remains the three unreachable NPCs above, with no new warnings.
Synthetic checks cover every weapon role, explicit skill overrides, missing
role diagnostics, independently oriented recipients, two complete strokes,
reduced-motion rest, exactly-once resolution and retained flip replacement.
The verified local native package is installed with its predecessor backed up
by the normal Console installer. Both CPU effect inspections pass, covering
80 image-frame views normally and nine reduced-motion rest segments. A silent
normal macOS native inspection closed cleanly with 696 presented acknowledgements;
the reduced-motion inspection closed cleanly with 438. Both verified muted
music/SFX, presentation cleanup and unchanged isolated combat state.
The Mac was locked, preventing visual inspection; acknowledgements still are
not native visual acceptance, actual encounter acceptance or non-macOS coverage.

The queued-command regression pass exercises the actual Traditional and
ATB action states with disposable authored resources, rather than manually
changing HP in an inspection callback. Its 128 combinations cover sword,
staff, flail, glove, unarmed and an explicitly bound double slash overriding
the weapon default, plus enemy neutral-default and explicit double-slash
commands; both attack directions; terminal/graphical presentation;
and normal/reduced motion. Source and target tracks, directional crops/glyphs,
both visible strokes in normal motion, pause before impact, actual one/two-hit
damage, one MP charge, reaction and return/cleanup all pass. The six-file
regression group passes 348 tests with 85,339 assertions and no warnings.
The updated full Engine suite, including base-attack-style regressions, passes
4,468 tests with one existing skip and 174,833 assertions, using CI's 1 GB PHP
memory limit; full PHPStan passes (single-process mode where sandbox loopback
prevents worker startup).
This follows correction of a nondeterministic critical-hit test and an existing expectation of the
old rounded legacy duration; neither test was removed or renamed. Subsequent
shared Party/Troop fixture extraction passes the targeting/preview group with
179 tests and 87,855 assertions. The earlier local run at 128 MB exhausted its
memory allowance and is not counted as a pass.
This strengthens real-caller CPU evidence; it does not replace a native
visual playtest of an actual encounter or complete Editor authoring. The
native preview driver now queues real commands through both production
engines, with an owned cleanup callback that restores fixture state and fails
the driver on cleanup failure. Its supplied Party/Troop seam lets Game test
current actors, equipment, commands and troop formation without duplicating
combat setup or manually simulating results. The Game-owned actual-content
fixture can be inspected through `tools/gpui-battle-preview.php --inspect`.
Inspection starts paused; stdin accepts `play`, `pause`, `step SECONDS` and
`quit`. The configured simulation duration is still bounded to 1..60 seconds;
the owned native window also accepts Space to play/pause, Right to step one
second, Down to step one tenth of a second, and Escape to quit unsuccessfully.
These are developer-inspector controls, not gameplay bindings. They use the
same PHP-owned clock and allow native observation without stdin round trips.
Inspection has a separate thirty-minute wall limit and owns window cleanup.
Steps traverse every simulation frame, while paused redraws retain the existing
canvas without calling the fixture again. Only reaching the full configured
duration runs verification; quit, incomplete closed input and wall expiry are
failures, not acceptance. This test-driver clock does not change battle timing
or move gameplay into rendering. Held native pixels prove the inspected states,
not uninterrupted animation cadence. The Game-owned actual-content
fixture is now ready and independently verified on CPU: twelve queued
commands cover both engines, weapon attack, an MP skill, offensive magic,
enemy attack/party knockout, inventory-backed revival and healing. That initial
fixture manually equipped a starter sword; it did not test the empty upgrade
slots of the normal new-game party. The October 3 slash report exposed the
missing distinction between a character's basic weapon and stat-bearing
equipment. The shared actor-owned `attackStyle` contract above removes the
assumption that an empty upgrade slot always means unarmed. Game now authors
the four established base styles without granting items or bonuses. Read-only
loading of both a new game and the latest autosave confirms sword/dagger
slash and staff/flail impact selection, with empty equipment preserved. The
Game-owned queued fixture now removes its artificial sword loadout in the
Traditional case and tests a different-type equipped override in ATB. Both
normal and reduced-motion CPU runs pass all twelve commands in 54.58 seconds;
the Game binding, equipment/save and acceptance regressions pass 109 tests
with 27,550 assertions. Menu command data and an
authored graphical troop supply the data; combat outcomes are never simulated
by the preview. Costs, actual outcomes, effect assets, reaction roles, return
and cleanup passed in the initial fixture. A fully muted 60-second macOS native run completed the same
commands and closed cleanly, but the locked Mac prevented pixel inspection.
No user game, save, audio preference or lock setting was changed. This is
real-command verification, not native visual acceptance or a complete campaign
encounter playtest. Game reports 1,855 CI passes and the same one reachability
failure; its three new acceptance tests pass. Battle simulations remain excluded.

Fresh October 3 checks after the blocking-path removal and equipment type-icon
changes: full live Engine suite 4,538 passed / one skipped / 176,247 assertions;
full Engine static analysis reports no errors. Both normal and reduced-motion
current-content CPU previews verify
all twelve queued commands; the reduced run completes them in 54.58 seconds.
The normal muted 60-second macOS native run closes cleanly with the same command
verification, but selecting its exact preview app timed out after bundle-id
selection found multiple installed copies. No pixels were inspected in that run,
and no further acknowledgement-only launches were repeated. No test window is
left open; no game settings or saves were written. Native visual and ordinary
encounter acceptance, non-macOS qualification and authored-format retirement
remain outstanding.

The shared weapon-role API is committed locally as `bf2f0d6` under Andrew's
scoped approval. Editor's role picker is integrated on local develop
`27703f6`. Its standalone effect timeline authoring, including sequence
preservation and directional preview, is integrated on local Editor develop
`9fd749f` against the committed Engine contract. Claude reports 1,433 Editor
tests passed, 55 skipped and clean static analysis. Andrew approved the
second scoped local-only commit on October 3: "Regarding the two decisions,
both recommended steps are approved." The eight-file compiler/direction/canvas-
flip contract and its tests are committed on Engine develop as `7db56dc`;
51 focused tests pass. Untyped orientation payloads are no longer accepted:
facing belongs to the typed track and image flips to typed keyframes. That
one-time commit approval is consumed; it does not authorize committing other
G2 work. Nothing is pushed or published.
The actor base-attack-style contract remains uncommitted in the live Engine.
Claude's source-preserving Actor Attack Style selector (`b17aaaf`) is now
integrated on local Editor develop `ab2dd9c`, together with the equipment
type-icon schema. It does not wait on an Engine commit or release. Attack Style
is an optional actor field, not graphical authoring in the TUI; equipment
permissions and gameplay values remain independent. Equipment-specific icons
are no longer editable in the database: weapons/armor retain their old icon as
read-only legacy data and consumables retain editable icons. Read-only rows
keep their field key so GUI identity consumers can still name them; edit
services still refuse writes. Claude reports 1,447 Editor tests passed and 55
skipped against the live Engine, with no attack-style, icon or shadow validation
issue and no Game writes. The coordinator verified those integrated commits
and a clean Editor checkout; this is not a native Editor playtest or permission
to publish.
On resumption at 08:55 CAT, the Mac was available again, but the inspection
tool selected Andrew's running Last Legend battle rather than a separate
preview. A read-only screenshot visibly confirms the current three active
party standing replacements and their ground shadows in that battle. It does
not verify other poses, command effects or the complete sequence. No controls
were pressed and no user game session was closed or changed. The separate
silent previews have already closed themselves; their remaining visual check
requires the inspection tool to select the preview instead of the user game.

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

Andrew watched the October 3 muted queued-command native run and surfaced
inconsistent battler proportions, so visual acceptance remains open. On his
explicit approval, the existing Art producer audited 112 current battle PNGs,
preserving accepted designs, opaque chest coverage and the repaired shadows.
Game's catalog carries provisional per-identity display widths for all eight
bound actors and twelve bound creature/human-enemy source identities. These
offline Art proposals were applied before native visual acceptance; they are
not accepted canonical heights or proof of consistent party anatomy. Andrew's
October 3 screenshots showed tiny bats and inconsistent party poses. The
coordinator removed Regular Bat's erroneous 65px whole-image override,
restoring authored encounter containment sizing without changing formations.
Art rechecked all 80 party roles: Kaelion KO has an approximately 20% longer
collar-to-belt proxy than Idle but nearly the same facial scale. Its body volume
cannot be faithfully corrected by a verified uniform resize. Attack's compact
lunge is not evidence of an independent renderer scale change. The new
technical package supplies evidence, preserved originals and zero ready
replacements; its 0.90 KO diagnostic must not enter runtime. Andrew clarified
on October 4 that his existing instructions already authorize source-proportion
corrections, including repaint where uniform resizing is insufficient. The
coordinator's repeated permission request was unnecessary and is withdrawn.
The existing Art owner is producing the needed party corrections using each
approved Idle as authority, without costume/weapon/identity redesign, starting
with Kaelion KO. Replacements and comparisons are not yet delivered.
Equal renderer pixel scale cannot repair inconsistent source anatomy, and
uniform resizing must not disguise incompatible anatomical ratios. The initial
package supplied one correction, Aeryn's skill pose: uniform 0.85 scaling around
its existing ground pivot, including its repaired shadow. The other 111 PNGs
were unchanged; before-images and receipts are preserved in the Art package.
Regular Bat's pivot now uses its projected ground shadow instead of its flying
body. No troop formation or terminal geometry was changed. Unbound creature
size proposals were not added to encounters. Aeryn's unbound Victory image
has conflicting face/torso proportions and a low neckline inconsistent with
Andrew's coverage rule. It is rejected design material, not costume authority,
and needs a separate author-directed correction using her covered Idle design;
it is not one of the ten current runtime roles. Andrew authorized the separate
corrective proposal on October 3: "Yes, produce the corrected proposal".
Art delivered an inspected 800x800 transparent corrective proposal, with
same-source-scale Idle/Victory comparisons and preserved generated masters,
in its isolated `aeryn-victory-corrected-proposal-20261003` package. The
coordinator checked its costume coverage, anatomy comparison, dimensions
and transfer receipt. It awaits Andrew's review; the rejected unused source
is unchanged and no new battle role, runtime replacement or publication is
authorized by producing the proposal.
The October 3 change removed independent per-pose contain/full-width fitting.
The October 4 reference-scale contract above supersedes its individual base
width registrations: all registered party and creature profiles now share
Kaelion's standing reference. Poses/cells use normalized body-unit calibration
and their own ground pivots, not their selected silhouette or formation extent.
The Game inspection fixture and placement checks consume the same catalog scale.
Synthetic coverage checks reference presence/absence, different formation boxes,
animated cell calibration, normal/reduced motion and resolution replacements.
All roles need coherent source anatomy; uniform rendering cannot repair it.
The following October 3 counts are historical evidence, not a full-suite claim
for the later reference-scale change.
The focused Engine checks pass 166 tests/2,791 assertions; the full live Engine
suite passes 4,552 tests, one skipped/177,009 assertions. Current Game
presentation and queued-command checks pass 494 tests/86,993 assertions;
focused static analysis is clean. Visible-overlap checks read the current
image's alpha extent; they no longer treat transparent canvas padding as a
UI collision or freeze authored bat formation coordinates. Alpha measurements
never drive anatomical scale, gameplay collision or runtime fitting.
The calibrated muted macOS queued preview completed all twelve commands in
54.67 seconds, with 960 presented-frame acknowledgements, and closed cleanly.
The screen-inspection tool timed out, so this is execution evidence, not native
visual acceptance. Fresh visual and actual-encounter acceptance remain open;
Linux/WSLg were not tested. Nothing in this correction changes terminal
geometry, collision, gameplay, saves or the accepted costume designs.

The subsequent multi-target tint correction passes 228 focused Engine tests /
6,917 assertions and the full live Engine suite: 4,572 passed, one skipped /
177,230 assertions. Full PHPStan passes using its sequential debug mode after
the sandbox refused the parallel worker's loopback socket. The Game owner
updated only the two affected presentation test files and verified 494 battle
tests / 87,377 assertions; this is not full Game CI. Ten native CPU compositor
tests pass, with two intentionally ignored by that run. The existing ignored
packet-replay test was then run explicitly and passed with an Engine-composed
twelve-subject tint packet: one surface, 24 operations, current alpha crops,
per-subject clips and transparent gaps. Its offscreen raster was inspected;
no game window or audio was launched. This verifies packet/raster compatibility,
not campaign visual acceptance or Windows/Linux/WSLg behavior. The remaining
visual checks and Phases 0-2 migrations are still open. Nothing was staged,
committed or published in this correction.

After Andrew reported "The Mac is unlocked. Proceed", two further fully muted
60-second macOS previews completed and closed cleanly. The real queued fixture
verified twelve commands across Traditional/ATB with 919 native presented-frame
acknowledgements; the six-effect inspection fixture verified 80 image-frame
views with 877 acknowledgements. Exact-app selection initially found the silent
preview window, but screenshots timed out; a second exact-app selection also
timed out. Unlocking did not resolve the inspection-service failure. Neither
run establishes native visual or campaign acceptance; no user session was
closed, and game settings/saves were not written.

The follow-up terminal correction removes first-recipient-only pulse handling,
concurrent-pulse overwriting and use of completed snapshots inside atomic battle
composition. Ten new synthetic cases cover tracked/untracked live underlay
reads, simultaneous recipients, retained redraws, pause/resume, cancellation,
reduced motion, a full shared overlay budget, current glyph ordering and wide
Unicode boundaries. Fresh focused Console/battle checks pass 294 tests / 22,389
assertions; full live Engine passes 4,582 tests, one existing skip / 177,329
assertions. Full sequential PHPStan reports no errors. The current ten Game
battle/presentation test files pass 686 tests / 107,449 assertions; this is not
full Game CI. No graphical animation flair was added to terminal battles, and
no gameplay, geometry, art, bindings or resource limits changed. Visual/campaign
acceptance, remaining migrations and non-macOS qualification remain open.
Nothing was staged, committed or published in this follow-up.

The subsequent motion/direction correction honors shake keyframe visibility
without suppressing impact cues, and removes pose-canvas centres as the source
of graphical attack facing. Fifteen red-first synthetic cases cover caster,
target and screen shakes, independent presentation selection, paused/reduced
playback, asymmetric animated poses on either side and authored second-stroke
mirrors. Fresh checks pass 462 focused tests / 29,853 assertions; full Engine
passes 4,597 tests, one existing skip / 178,094 assertions. Full sequential
PHPStan is clean and the ten Game battle files pass 686 tests / 107,449
assertions. No art, formation, gameplay or terminal geometry changed. Native
visual acceptance and the migration/permission gaps above remain open; nothing
was staged, committed or published.

Current verification (4 October, after shared ground attachment and pose frame
boundaries): full Engine passes 5,123 tests, one existing skip / 350,990 assertions.
Animated poses now use the timeline's stateless half-open boundary calculation,
so floating-point rounding does not leave them on a previous cell at an exact
playhead boundary. Synthetic checks cover all supported rates (1-120 fps) against
independent integer expectations, including samples before the boundary, looping,
final holds and reduced-motion rest cells. Authored cadence and session traversal
are unchanged. Ten focused pose/effect/terminal/field/summon files pass 938 tests /
207,003 assertions, and full sequential source analysis is clean.
Four focused Game battle files pass 550 tests / 71,407
assertions, including authored sheet frames, normalized pivots, independent
subject ground points and unchanged exactly-once outcomes/cleanup. Sixteen
real queued-command cases additionally hold lethal own-turn poison and KO before
resolution in both battle engines and both presentation/motion policies.
Full Game CI now completes after migrating the simulator subclass
to the shared roster contract: 1,856 passed, one failed / 856,042 assertions,
simulations excluded. Its remaining current-content reachability check reports
the Garden Route Controller, Happyville Innkeeper and Happyville Shopkeeper
cannot be spoken to, the same outstanding trio reported before this G2 slice.
The Game owner confirms the shared counter lookup works, but Game maps no glyph
to COUNTER, leaving its counter boxes solid. Their content authoring/decision
remains separate; no positions are restored or checks weakened. Full Game CI
is not green.
Normal and reduced
CPU queued fixtures each finish twelve real Traditional/ATB commands, three
defeats, exact costs/outcomes and cleanup at 52.95 seconds after removing the
empty compatibility delay. Before that timing change, fresh muted native runs
completed twelve commands in both policies with 884/404 presented-frame
acknowledgements; those acknowledgements do not establish pixel cadence.
Inspected pixels include Seraphis's real queued ATB knockout and the supplied
single slash. A corrected temporary helper selects exact command track IDs,
not shared PNG paths, to hold unchanged production canvases: the first dual
stroke and Drazek's attack pose were captured, as was Burn with Liora's casting
pose in the preceding held run. Later captures timed out. The second mirrored
stroke, continuous effect cadence, complete pose sequence and ordinary field
encounter entry remain unaccepted. Held pixels are not runtime timing evidence.
Previews are muted, auto-close, and never write game saves/settings. No claim of
Linux/WSLg or Windows validation follows from these macOS checks.

After the ground-attachment correction, desktop inventory and a read-only
screenshot of Andrew's running game became available again. No controls were
sent to that game. A default-sandbox native attempt failed desktop-service
initialization before ready; it is not a successful preview. Two subsequent
fully muted, disposable macOS command runs completed all twelve commands and
closed cleanly, with 964/962 presented-frame acknowledgements. The inspector
selected the user game instead of the raw preview; selecting a temporary test
app identity pointing to the same binary then hung and returned no pixels.
The ring placement, continuous cadence and ordinary handoff remain visually
unaccepted. The temporary wrapper is removed after its renderer has closed;
there is no copied runtime or installed-game change. No further
acknowledgement-only run is planned.

Three synthetic entry cases each repeat three encounter/return cycles under
normal transitions, transitions off and reduced motion. They protect fresh
battle/result state, reveal before combat updates, owned transition cleanup
and resuming the same field composition. The entry suite passes 10 tests / 207
assertions; this is not native or real Game encounter acceptance. Separate
independent Game CPU runs now drive actual field input through EncounterManager,
SceneManager, BattleScene and menus without assigning results or replacing the
outgoing field composition. Normal motion completes two actual encounters in
58.69 seconds / 1,775 frames, reduced motion in 35.63 seconds / 1,161 frames.
Each battle returns the same GameScene and cell, with zero saves written and
unchanged player settings. Maps, routes and troops come from current authored
data rather than pinned layouts. Without a renderer both runs use a direct cut;
they do not establish native pixels or graphical handoff timing. No-PTY runs
emitted terminal-size probe warnings on stderr, recorded separately from their
passing lifecycle checks.

`tools/gpui-scene-preview.php` is the shared real-scene driver. Its trusted
factory accepts the asset root, reduced-motion flag and optional subject IDs,
and returns a Game, RendererGridConfig, start/advance/isComplete/verify/dispose
callbacks, declared capabilities and an optional `realtime` policy. It attaches
the production RendererRuntime before starting any scene, presents the actual
scene/world and notifications, and leaves focus/transition policy with Game.
Effective ProjectConfig and PlaySettings music/sfx mute and zero master volume
are required before process creation and every step. Sessions are bounded to
1..300 seconds, close automatically, and report success only after scene,
runtime and isolated fixture cleanup all succeed. Fixtures using the production
wall clock declare `realtime => true` for CPU runs as well. The Game factory
owns temporary saves, a no-backend audio manager, refusal of project-config
writes, its own Game autoload registration and restoration of process state.
With a renderer it additionally requires negotiated capabilities, actual battle
canvases and handoff activity appropriate to the effective transition setting.
Native startup uses Game's logical viewport after runtime attachment rather than
the fixture's earlier terminal grid. The optional fourth factory argument is
`project`, `on` or `off`: `--transitions` requests this isolated policy and the
fixture must acknowledge it before any scene/native launch. Default project mode
does not require legacy three-argument fixtures to implement a new option.
Driver and related runtime/entry checks pass 113 tests / 779 assertions;
four Game configuration-isolation checks pass / 31 assertions. Scoped sequential
driver/fixture static analysis is clean.

Use `php tools/gpui-scene-preview.php --asset-root=PATH --fixture=PHP
--subjects=field-encounter --duration=180 --transitions=on --no-launch` for the Game CPU fixture;
use `--transitions=off` to check direct cuts, or `--reduced-motion` with on to
check the accessibility policy. Omitting transitions preserves the project's
effective preference. For native verification omit
`--no-launch` and supply `--renderer=PATH` to the installed executable. Never
launch before effective mute verification, or substitute a fake outgoing canvas.
The canvas-only battle preview uses a separate client and cannot capture the
real field handoff. The corrected CPU fixture completed two real encounters;
three silent macOS native runs completed six victories in total and returned to
the same field/cell. On observed 20 handoff frames per battle; off and reduced
motion observed zero, and every battle presented a graphical canvas. Saves and
settings remained unchanged; the owned previews closed cleanly. These functional
results do not prove pixel appearance or complete pose/effect-sequence acceptance:
CUA app selection timed out during the off run. No locked-Mac diagnosis follows
from that timeout. The ring correction still has no new native pixel acceptance.

Andrew removed the acting marker on October 4: the command's step forward and
action pose already identify who acts. Shared graphical composition no longer
draws an underline, a textured marker or an "Acting" fallback label in either
skinned or primitive presentation. The obsolete `acting` BattleUiSkin role and
Game binding are removed, not replaced by another indicator. Existing artwork
files are preserved. Ten red-first cases cover both sides, skins, motion policies,
advance/return and missing artwork; target selection and combat identity remain.
Focused removal/regression verification passes 547 Engine tests /32,896
assertions and 96 Game graphical-battle/results tests /40,850 assertions.
Analysis of the changed renderer and skin is clean. These are headless checks;
no new native visual acceptance, full Game CI pass or publication is claimed.

The 4 October 10:15-10:17 screenshots reopen battle contact-shadow acceptance:
Drazek's rear sole still visibly separates from its shadow, and Andrew reports
the same problem across other battlers. Art has both exact examples and the
full-set correction. Protected body pixels, pivots and scale are necessary
preservation checks, not proof of convincing contact. The runtime draws this
idle's entire PNG without a crop; each intended grounded sole needs visible
source contact at its own screen-space depth, not a universal lowest-foot line.
That historical shadow package was superseded by the completed October 5
revision, which Andrew approved and Game integrated. Live sequence inspection
remains separate from artwork approval and headless contact checks.

Desktop app selection responded again during this turn. The current native
source was built and installed through Console's verified source updater;
the real queued-command CPU preflight and muted 60-second native run passed.
Both native screenshot calls failed with `timeoutReached`, so the complete
sequence remains visually unaccepted. The disposable preview closed cleanly;
no further acknowledgement-only launch is planned. No user game, save or normal
audio settings were changed, and no publishing action occurred.

Runtime skill-name and hardcoded animation-name selection are removed. Legacy
items without a binding retain their existing no-animation behavior. A missing explicit id
reports a warning and never substitutes a default. No presentation reference
prevents the item's or skill's gameplay effect. Claude owns Editor role
round trips, pickers and duplicate-role/reference validation; its old
name-candidate diagnostic helper remains for migration only and must be
updated, not treated as runtime authority.

### Compatibility Policy (Approved October 5, 2026)

Andrew approved retaining the existing cell-animation compatibility reader in
the current major version, documenting deprecation and migration now, and
removing that reader only in the next major version. His exact answer was
"Approve that compatibility policy". The deprecated part is the cell-based
authoring format, not numeric animation identities or role/effect bindings.
New effects use the shared timeline; supported existing projects continue to
load their authored cells through that same playback session.

Reader removal belongs to a deliberate next-major migration, not G2 acceptance.
Last Legend's current records and field consumer have already migrated. Other
projects are not automatically rewritten, and native conversion usability
still needs acceptance. No release date is set, and this policy grants no
commit, push, branch, tag or release permission.

Migration uses the existing shared converter and transactional Editor workflow:

1. Inventory each numeric record's battle and field consumers. Preserve their
   actual timing, offsets, colours, sound cues, flash policy and rest treatment;
   consumers with different contracts need distinct timelines.
2. Select the actual cadence, explicit fixed rate where applicable, tick
   multiplier and rest frame. Review the complete proposed sources before
   confirming; do not infer a rate from a helper default.
3. Apply the held source-preserving conversion plan. Numeric IDs, unknown data
   and source comments remain; stale files or changed choices invalidate the
   plan before writing. Use the existing grouped undo/redo transaction.
4. Repoint field consumers explicitly through their effect picker; conversion
   inventories them but does not silently change their references.
5. Save and reopen, then verify battle and field consumers in terminal,
   graphical and reduced-motion presentations. Reader removal must not strand
   supported projects or change their gameplay.

### Phase 1 - One runtime

1. Generalize the summon compiler/session into the effect-timeline library
   (`assets/Animations/`), with anchored coordinates.
2. Replace the blocking `AnimationPlayer` path with a non-blocking session
   driven from the battle update loop; turn pacing waits on session
   completion or the effect cue instead of squeezing frames into a fixed
   turn slice. Authored fps is honored everywhere, as summons already do.
3. Migrate the two cell-frame animations to timelines (a cell frame is a
   one-glyph content grid); retain the compatibility reader in the current
   major and remove it only in the next major under the approved policy above,
   not as a G2 gate. The editor migrates its bespoke animation database to
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
   remain. Battle-only `cadence => battle_phase` and explicit standalone preview
   budgets now establish timing ownership for paced compatibility effects.
   The runtime also loads the matching authored terminal sequence when GPUI
   selects an image-only target. Game's Hit Spark now declares independent
   terminal and graphical timelines: its terminal timeline is battle-paced,
   omits fps and preserves the five original cell states and flash. Both
   Hit Spark and Healing Aura have removed their legacy record cells/cues;
   their numeric records now only bind roles and effect timelines. Healing
   Aura's accepted fixed-rate terminal treatment remains unchanged, with no
   added flash. Both numeric IDs and role/effect bindings remain. All seven
   current numeric animations load with no legacy presentation, and their
   declared effects compile for both terminal and graphical presentation.
   This finishes Last Legend's record migration, not retirement of the reader
   still needed by other supported projects. Historical field-equivalence tests
   now read a labelled, frozen six-cell fixture, not mutable live animation
   records. Independent
   affected Game verification passes 187 tests / 50,717 assertions.
   Constrained Editor authoring, source-preserving round trips and preview
   pacing are integrated on local Editor develop `57e21b1`, tested against the
   shared live Engine tree. Andrew rejected delaying development on another
   repository's commit or release. The timing-API commit request is withdrawn
   as a dependency gate; no Engine commit or publication is implied. Format
   retirement is deliberately deferred to the next major, not unfinished G2 work.
   The standalone compatibility traversal now uses `EffectPlaybackSession` with an exact
   frame-duration override instead of rounding that duration to integer fps.
   This removes the legacy clock and field reduced-motion skip while leaving
   the current-major compatibility reader supported. Its removal requires
   deliberate existing-project migration in the next major.
   Editor's animation-record migration is now integrated on local develop
   `a152ac5`: animations use the shared record schema, effect pickers and
   supported role list. The bespoke `ProjectAnimationDatabase`, terminal
   frame-grid painter and frame/cue preview workflow were removed, not merely
   hidden. Legacy frames/cues remain preserved and read-only; numeric IDs,
   effect references, unknown fields and interior source comments survive edits,
   with no invented frame rate. New effect authoring uses timelines. The
   coordinator's bounded second review found numeric allocation and
   duplicate-record role-uniqueness gaps. Editor corrected both in `a0c6d7a`:
   numeric creation/copy uses the next maximum ID, including IDs retired in
   the session; structural creation/copy omits exclusive members already held
   by another record, rather than duplicating runtime role ownership. The
   independent gap probe and eight role/round-trip tests pass (51 assertions).
   Removing the Engine compatibility importer in the current major would break
   supported projects and violate the approved policy, not complete G2.
   Numeric battle imports now project their consumer-owned frame duration
   through `EffectPlaybackTiming` onto the 120 FPS command lane, removing
   whole-number FPS rounding. Cells, blank slots, sound cues and flash tails
   retain half-open boundaries, with at most one output tick of quantization.
   New authored effect fps/defaultSpeed and Editor source format are unchanged.
   Existing-project conversion now has a pure shared source API:
   `LegacyAnimationMigration::getTimelineData(id, animation, cadence,
   ticksPerFrame, restFrame, fps, includeFlash)`. Callers must select the actual
   consumer cadence; fixed timing requires an explicit rate, battle-phase
   timing omits it. An integer tick multiplier preserves exact intervals such
   as 0.12 seconds (three ticks at 25 fps). The rest frame is an explicit
   zero-based original frame, including any flash tail. Original cell paint
   order, moving offsets, colours, blank frames, sounds and overlapping flash
   tails survive conversion. Field callers explicitly omit flashes, preserving
   their existing consumer policy. Oversized or invalid conversions fail
   before writes rather than discarding source data. The converter neither
   rewrites files nor chooses record references: transactional, source-preserving
   migration and undo belong to the Editor/application service, including
   distinguishing consumers that need different timelines for one old record.
   Fourteen synthetic conversion tests pass; 513 related runtime tests / 115,299
   assertions pass on each of PHP 8.4 and 8.5, with scoped source analysis clean.
   No compatibility reader was removed, no existing project was automatically
   rewritten, and no release/deprecation date is inferred from these checks.
   Editor `b06ef53` now exposes terminal conversion choices and complete
   proposed-file review through shared scrolling. Exact held-plan writes,
   stale-file refusal, changed-choice invalidation and grouped undo pass
   independent checks. Editor `e6591e1` also corrects shared wrapping when source
   indentation exceeds a narrow pane; all non-whitespace source content stays
   visible without changing the held source bytes. The affected checks now pass
   42 tests / 345 assertions at three terminal sizes. Native interactive
   usability remains open. The approved compatibility policy settles the
   deprecation/removal boundary; G2 does not wait for a future release.
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
5. Migrate `assets/Data/Animations/explosion01/` (previously orphaned): each
   of its text files is one frame, becoming one glyph-track keyframe of a
   timeline. Game's source/history audit confirms seven orphaned frames, but
   no authored frame rate or durations. The coordinator has requested a
   once-only 10 fps treatment (0.7 seconds), which Andrew approved on October 3
   together with the scoped local directional contract commit. Game owns the
   conversion and delivered `Animations/explosion01/explosion01.timeline.php`
   locally, uncommitted and unbound to combat. Independent shared-library and
   session checks pass for battle/field and terminal/graphical: all seven
   source frames match byte-for-byte, blank rows and duplicate frame slots are
   preserved, the final frame holds fully, and completion is exactly 0.7 seconds
   without looping. Rest frame 3 shows the full burst. The originals are unchanged.
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
3. Summons must render graphically through the identical path. The October 6
   G3 audit found that the earlier summon DTO/compiler dropped image-track
   fields; common playback alone did not establish image parity. Summon tracks
   now pass through EffectTimelineValidator, retaining image geometry,
   battler/screen anchors, pivots, direction and rest coverage, while keeping
   legacy glyph/text behavior. BattleCommandPreview consumes real playback
   and production graphical/Terminal composition without combat or audio
   side effects. GUI consumption, moving-art acceptance and integrated D'jin
   battle observation remain in the [G3 checklist](rendering/integration-roadmap.md#g3-acceptance-checklist).
4. Field effects present through the same adapter as sprites in the field's
   retained world, behind or in front of characters by track, starting with
   the walk-on save point and the graphical cues.

### Phase 3 - Authoring in the editor

The summon timeline surface generalizes into the animation editor:

Current boundary, October 6: items 1 and 5, flash/cue fields, independent
presentation sequences, direction fields and shared preview controls are
implemented. Items 2 and 3 and per-track mute/solo in item 4 remain planned:
the current terminal preview uses relative caster/target markers, not real
arena silhouettes, and glyph content is edited as keyframe data rather than
through an onion-skinned painter. The legacy cell-frame painter was removed
during record migration; it is not completion of item 2. G2's original roadmap
scope is Phases 0-2 plus the battle command lifecycle; its completed record
does not claim all authoring enhancements in this later phase are delivered.

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
- New effect authoring uses timelines. The deprecated cell-frame format keeps
  its supported compatibility reader throughout the current major; removal
  follows deliberate migration only in the next major, not as a G2 gate.
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
