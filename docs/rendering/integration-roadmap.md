# Dual-Presentation Integration

This roadmap tracks reusable terminal and graphical presentation capabilities.
Game-specific production decisions, asset admission and author acceptance belong
in private game documentation. The contracts below describe runtime behaviour
and remaining engineering work, not release status.

| Entry | Scope | Current boundary |
| --- | --- | --- |
| T1 | Retained-cell terminal composition and scrolling | Implemented; see [validation](t1-retained-cells-validation.md) for the bounded fixture and measurement method. |
| G1 | Graphical arena, static battlers and battle UI | Implemented; see [battle contract](graphical-battle-g1.md). Artwork coverage is project-owned. |
| G2 | Animated battlers and combat effects | Planned in [effect animation](../effect-animation.md), Phases 0-2. Static images do not complete this work. |
| G3 | Graphical summon presentation | Planned through the same effect session, with terminal parity and outcome-timing checks. |
| G4 | Field actors, objects and environment | Partial; needs complete asset-role coverage and [stateful input](#controller-ready-input-and-normalized-movement). |
| G5 | Game-wide interface coverage | Shared adapters described below are implemented; complete screen coverage and Editor authoring remain outstanding. |
| G6 | Packaging and supported platforms | Console checks source-development renderer updates before normal play without building. Installation through the verified installer requires an explicit update choice or `renderer:update`. Prebuilt player distribution, installation dependencies and platform-specific validation remain unfinished. Native Windows also needs compatible process transport. |

Headless PHP checks and macOS rendering checks do not establish Linux/WSLg or
Windows support. Merging implementation does not create a package release.

<a id="approved-battle-results-integration"></a>
## Battle Results integration

The shared award boundary captures immutable EXP, level, stat, learned-skill,
rolled-drop and actual inventory/gold-retention facts. Playback owns only reveal
timing, pages and completion: repainting, re-entry and skipping never grant
rewards. The existing full per-travelling-member EXP policy includes reserves.

An optional Results skin projects Primary, Level Up, Learned Ability and
explicit Special Reward events over the final battlefield. It does not infer
rarity. Terminal results expose the same facts and order with explicit overflow
pages. Menu portraits and dialogue busts are separate role bindings; missing
optional art has a local neutral fallback.

Shared image preflight distinguishes battle/HUD, at most four consecutive
Primary portraits and one event bust. Budget actual concurrent families, not
the catalogue union. Crossfades must budget both stages. Source and prepared-
region pools are independently bounded; clipping and opacity do not remove
source cost. Cache eviction does not release references owned by queued frames.

Confirmation labels are independently centred. Buttons use steady focus fill
and borders without list cursors. See [Results](graphical-battle-g1.md#battle-results-integration)
for playback, input guards and resource contracts.

<a id="approved-pause-upgrade"></a>
## Pause

The existing pause state owns Resume, Config, To Title and Exit. Resume starts
focused. The suspended scene remains visible without a full-screen dimmer.
Opening/closing input is consumed; menu motion continues while ATB, actions
and scene progression remain suspended.

Config reuses its normal settings owner with a return context; Back restores
Pause with Config focused. Both destructive choices open Cancel-default
confirmations through the existing title/quit paths, without autosave. Root
Pause resumes through the pause action. Redundant confirmation hint strips
are omitted; visible choices and semantic input remain available.

Resume restores the running state, never battle entry or engine start.
Resize repaints without re-entry or focus reset. Feedback deadlines retain
their remaining duration; cleanup removes only entered-state resources on
return, abandonment, partial entry or repeated disposal. Terminal and native
paths share these lifecycle constraints.

## Shared menu presentation

Engine owns components, layout and navigation adapters. Games supply replaceable
artwork, palette and spacing through `assets/Data/Presentation/menus.php`.
They need not restate Engine defaults or provide drawing callbacks.
`PresentationCanvas` owns default canvas dimensions, `MenuLayout` owns the
centred maximum menu envelope, and theme metrics own typography and spacing.
The same envelope applies to Main Menu, Config and other full menus.

Main Menu, Equipment and Status project existing state. Character focus covers
the entire card interior without a per-name cursor. Order's source mark remains
distinct from destination focus. Location and help share a measured bottom-row
height, growing together for long content without gaps below the last card.

Frame `borderWidths` describe straight-edge backing independently from corner
`cuts`. Opaque edge backing lies below selection; decorative edges lie above
it. Artwork owns the interior and alpha silhouette, including chamfered corners.
Only themes without frame art use a solid fallback. Bounds reconcile against
current dimensions and density, not image hashes.

Shared Alert, Confirm, Select and quantity modals retain supported graphical
menus beneath them. Existing controllers retain outcomes and input. Unsupported
text entry, dialogue or oversized non-Info content uses diagnosed terminal
fallback. Single-confirmation alerts centre the message and modest-width button.
Choice lists keep their own layout. List items may oscillate; buttons do not.

`showInputHints` defaults to true; false omits graphical helper strips without
changing bindings or removing useful descriptions. Controls remains the full
binding lookup. Button labels are centred independently of icons and hints.

### Items and descriptions

Items uses the existing command, inventory, target and quantity owners.
Terminal paging slices actual inner capacity; clamped selection and horizontal
navigation apply to Use, Discard and Key Items. The graphical list follows the
same selection. Equipment and quest-critical key items stay out of the ordinary
item list after sorting, discarding or depletion. Empty Key Items remains a
reachable read-only view.

Use and Discard share the bounded quantity picker and Confirm workflow.
A singleton skips the amount picker, not confirmation. The amount and Continue
button are independently centred; up/down chevrons sit immediately right of
the amount. Fine/coarse axes are shared with Shop. Cancellation does not mutate
inventory; the owner revalidates the stack and target before applying once.

Items, Equipment, Abilities, Magic and Config share a PHP-owned two-line Info
reader. Semantic Info advances whole pages and wraps; selection/source changes
reset reading. Rendering never advances it. Complete descriptions and status
remain reachable. Terminal ranges use Window help; graphical ranges use frame
padding or Config's Description heading, not additional prose rows. Word-aware
wrapping shares measurement and drawing budgets.

### Config, abilities and magic

Main Menu and Pause reuse `ConfigMenu` and the same graphical composition.
Settings retain existing ordering, descriptions and immediate persistence,
without invented categories, Apply or Reset actions.

Abilities and Magic retain tabs, learned/ready counts, learning requirements,
sorting and field-spell target selection. Magic requirements use the same story
flags as learning. Graphical detail omits Source without discarding the authored
data. Casting, learning charges and target ownership do not belong to rendering.

Navigation roles `navigation.previous/next/up/down` are chevrons.
`comparison.*` roles are stemmed arrows for numeric/stat comparisons. Neither
uses the other's art or the Unknown item icon. Themes with old comparison art
under navigation roles must migrate those bindings.

Slider track/fill/thumb and scrollbar rail/track/minimum-thumb/arrow-gap metrics
are shared and theme-owned. The fill stays inside its track; the thumb centres
on its endpoint and paints above it. Scroll extents come from the PHP row/reading
owner. The display control does not mutate selection or scroll position.

### Quests, Records and Controls

Quest navigation remains visible beside separately styled identity,
Description, Objectives and Rewards sections. Typed semantic sections supply
text and icon roles; painters do not infer meaning from formatted strings.
Objective ring/check fallbacks do not misuse Unknown artwork. Records retains
an Info panel and dedicated reading focus. Both keep PHP-owned text positions
and measured pages for complete terminal/native scrolling; shortened previews
retain full detail text. Back restores selection. Secret and undiscovered
entries are filtered before presentation.

Terminal journals use the shared horizontal tabs and Window border/help
pagination, never fake right padding or content rows. Native text and markers
are batched, and tab headers are measured after wrapping.

Controls exposes the primary control and all keyboard aliases, supports existing
rebind/default/cancel behaviour, and distinguishes failed persistence from a
successful session-only binding. Consumed edges cannot bind or navigate again.
Replaceable display glyphs are not physical-controller detection.

Remaining UI gaps include Main Menu Quit confirmation parity with Pause and
Editor TUI authoring of themes and shared artwork-role bindings. Runtime
composition does not establish authoring support.

## Title presentation

Optional `Data/Presentation/title.php` uses schema `ichiloto.title/1`: menu
theme, day/night backgrounds, finite atlas subjects, declarative effects,
logo/gleam and optional layout overrides. PNG dimensions come from current files.
The catalogue supplies named defaults; games override only intentional choices.

The PHP clock chooses local day at 06:00 inclusive to 18:00 exclusive, with at
most one ordinary boundary check per second. Crossfade uses smoothstep over
one second (0.16 seconds under reduced motion); the initial 0.28-second entry
survives destination returns. Suspension pauses elapsed decorative time without
catch-up. Reduced motion retains scenery and frame zero but omits travelling
birds. Stop releases presentation ownership.

`LocalClock` resolves TZ, OS zoneinfo/timezone files, then optional ICU host
detection without shelling out or changing PHP timezone state. Failure is
diagnosed before falling back to PHP's zone; `get_local_timezone()` shares it.

The adapter projects existing TitleMenu commands, availability and selection.
Options and Config share `SettingsMenuPresentation`; title retains seven
options and Back. Save and Load share `SaveLoadMenuPresentation`, existing
slot metadata, full-card focus, wrapped descriptions and empty/incompatible
states. Save participates in GameScene canvas/modal delegation. Status colours
distinguish success/failure; re-entry clears stale status without changing saves.

Credits retains authored sections through `CreditsContent`. GPUI rolls centred,
clipped text above the title backdrop with a steady Back button; a capable
renderer without title art uses the default theme. `CreditsPlayback` advances
only in PHP, pauses behind modals/suspension, and restores title
selection on completion or semantic dismissal. Re-entry starts fresh. Terminal
and reduced-motion presentations use bounded centred pages.

Automatic title-animation and credits pause-on-unfocus was removed in `eb5e19e`
when activation stopped being required by those surfaces. Explicit activation
subscriptions still support focus pausing, but normal launches do not subscribe.
Optional advertised-then-requested restoration is a proposal, not implemented;
see [the removal record and proposal](runtime.md#optional-graphical-surfaces).

Narration and speech use left-aligned text independently from top-centre box
placement; explicit caller placement wins. Cinematic title cards remain
middle-centred with centred text. These rules do not change prose or timing.

V2 `canvas_compositing` provides bounded image/fill/stroke operations, blending,
gradients, polygon/ellipse/image-alpha masks and displacement grids. PHP owns
time and resolved drawing data; native rendering has no game paths or clocks.
Generic source/raster/cache/cold-work limits apply. See Renderer documentation
for exact wire limits. `window_activation` reports native focus, not visibility
or occlusion. OS-hide/occlusion handling, hover/pressed states, broad native
input/visual coverage and Editor title authoring remain incomplete. Capability
shortfalls degrade the presentation, not game startup. CPU preparation timings
are not end-to-end gameplay or GPU performance measurements.

## Controller-ready input and normalized movement

Stateful input and normalized diagonal movement remain planned, not current
event-only API behaviour. The [input contract](input-sources.md#planned-controller-ready-input-and-normalized-movement)
owns requirements. Implement after cinematic movement/subject ownership and
before whole-game input/platform qualification.

PHP owns semantic actions, binding contexts, movement and timing. Native sources
report normalized key transitions and focus/reset events. Preserve terminal
event-only compatibility, route timing and retained-cell composition. Physical
controllers, enhanced terminal reporting and continuous subcell motion are
separate future work.

## Battle presentation direction

Stable combat identity, targeting and outcomes must be independent of pose,
weapon attachment and effect layers. Animated idle/action poses, expressive magic
and summons extend G1 rather than replacing its identity or lifecycle contracts.

Use shared timeline timing and semantic impact cues. Repainting, dropped frames
and fallback cannot lose or duplicate costs, effects or completion. Concurrent
effects need independent lifetimes; camera, UI and audio must compose without
clearing one another. Simpler static/limited-frame treatments, terminal and
reduced-motion alternatives retain readable impact and outcomes.

`BattlerArtwork` represents an image or atlas region, not an animation runtime.
`SummonPlaybackSession` separates elapsed time and cue traversal; battle still
uses the blocking adapter. Animated pose-role selection, graphical summons,
attachments and bloom are not delivered by those foundations alone.
Full-size animation masters require bounded runtime packing against the whole
scene budget, stable anchors and shared pause/cleanup, not an APNG shortcut.

## Cinematic gap schedule

The [cinematic contract](../cinematics.md#current-graphical-boundary) owns current
runtime details. Representative scene integration extends the existing
interpreter, routes, camera, animation timing and cleanup, not a second runtime.

### Contract and scope gates

- Keep subject identity and state in PHP. Graphical providers observe it;
  a visual replacement is neither another route nor another collidable entity.
- Preserve dialogue/transition covers above the field and complete replacement
  when menus, battles or transfers take ownership.
- Suppression is map/session-scoped and independent of NPC eligibility or
  collision. Do not hide art by filtering the NPC collision query. Paired poses
  suppress the ordinary player too; release re-evaluates current world state.
- Field sprites, source rectangles, layers and complete snapshots support the
  current cell-aligned slice. Subcell motion, precise attachment points,
  per-instance alpha and canvas-over-field composition remain separate gaps.
- Completion, legal skip, partial start, failure, transfer and shutdown release
  owned cast/bindings/effects. Preserve guards and deferred autosave. Test resize,
  re-entry, repeated NPC IDs across maps, pause, reduced motion and exactly-once
  outcomes; never undo legitimate story state.
- Real-subject movement needs map/session-bound transform recovery. Captured-
  entry walking/return must not become teleportation or per-start-cell scripts.
  Retry guards distinguish recorded choices from completed outcomes.

Remaining representative integrations follow the established ownership seam.
Runtime tests need ordinary, reduced-motion and legal-skip paths, matching
terminal presentations and scene-specific native observation. Source inspection,
headless tests and art previews are distinct evidence, not interchangeable
proof of complete gameplay or platform support.
