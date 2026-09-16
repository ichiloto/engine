# Dual-Presentation Integration

This programme follows the accepted GPUI feasibility spike. It does not reopen
the spike or extend its S-numbering. T1 and G1 have implementation authorization;
the cinematic schedule below has planning authorization dated 16 September 2026.
Other milestones remain backlog, not implementation permission. Andrew's subsequent
cross-platform correction request covers the existing startup defect and the
installation audit, not blanket authorization for those milestones or releases.
On 16 September Andrew additionally approved bounded Battle Results integration
in the existing game alongside cinematic work, with no publishing or new build.

Engine coordination owns shared Engine APIs and integration. Last Legend - Game
Design owns presentation priorities and coordinates with Last Legend - Lore.
Last Legend - Art owns placeholder and production art. Story-specific design
and acceptance stay in the private game-docs repository, not this document.

| Entry | Scope and owner | Dependencies | Status, acceptance evidence and remaining work |
| --- | --- | --- | --- |
| T1 | Retained-cell native terminal composition and scrolling. Engine coordinator. | Accepted overlay/Unicode/output fixes; matched Garden fixture. | Merged at `d0c4f0c`. Matched medians H 0.575-0.580 ms / V 0.491-0.565 ms, exact payload/style equality on 216 frames, full Engine and bounded integrations pass; about 2.1 MiB additional retained process memory. Andrew subsequently playtested and confirmed it was "absolutely brilliant and fast"; [author acceptance receipt](https://github.com/ichiloto/engine/pull/94#issuecomment-5662733694). This is observed responsiveness, not measured end-to-end latency or Linux/WSLg acceptance. See [validation](t1-retained-cells-validation.md). |
| G1 | Independent graphical battle layout, static arena and graphical combatants. Engine integration with Renderer; Game Design and Art. | Agreed canvas contract and matching fixtures; native implementation; approved graphical layout/assets. | Accepted in the normal local Game, including right-aligned resources, recipient-side feedback and target cursors. Shared native controls apply to all 11 authored troops. The approved four-creature/six-background expansion covers 14 authored encounter contexts with explicit location bindings; Cryptic Ruins/Loch Ness remain deferred. [Current contract and validation](graphical-battle-g1.md) is authoritative; superseded temporary runtimes are removed. Engine passes 2097 tests on PHP 8.4/8.5 with one existing skip each; Game artwork/presentation checks pass 54 / 2884 on both. Prior native macOS acceptance covers one illustrated encounter and corrected Cure placement, not a new all-encounter playthrough. Platform/outcome limits and actor-cursor polish remain explicit. Local integration is not remote publication. |
| G2 | Battler animation and combat feedback. Engine/Renderer with Game Design and Art. | G1. | Backlog, not authorized. Needs scoped brief and animation/feedback acceptance evidence. |
| G3 | Graphical summon presentation. Engine/Renderer with Game Design and Art. | G1/G2. | Backlog, not authorized. Needs scoped summon presentation and terminal parity gates. |
| G4 | Complete graphical field actors, objects and environment. Engine/Renderer with Game Design and Art. | Shared field presentation contract and asset coverage. | Backlog, not authorized. Needs coverage inventory and field acceptance. Includes the queued [controller-ready input and normalized movement](#controller-ready-input-and-normalized-movement) work, not started. |
| G5 | Graphical interface and game-wide presentation coverage. Engine/Renderer with Game Design and Art. | G1-G4. | Backlog, not authorized. Needs interface coverage and complete presentation audit. |
| G6 | Packaging and supported-platform readiness. Engine/Console/Renderer owners. | Accepted presentation coverage. | Full delivery remains backlog. Shared GPUI maximize/restore corrects the blanket non-macOS rejection; the accepted optimized build is installed locally through the existing Engine manifest. This is macOS validation only. Players should receive a verified platform package, not compile Rust; published artifacts, a Console installer, runtime dependencies and Linux/WSLg validation remain undelivered. Native Windows also needs a correct process transport. No release or distribution publication is authorized. |

T1/G1 completion will not mean the programme is complete. Publication follows
existing develop -> main PR flow. No remote working branches, release branches,
tags or releases are authorized by this roadmap.

## Approved Battle Results integration

The accepted Results artwork is now connected to the normal Game catalog,
not a separate runtime. Engine captures immutable EXP, level, stat and skill
facts at the shared award boundary, plus rolled drops and actual inventory/gold
retention. Playback owns only reveal timing, pages and completion; repainting,
re-entry and skipping a reveal never award anything. EXP remains the existing
full per-travelling-member award, including reserves, not the Art demo's split.

The optional Results skin projects Primary, Level Up, Learned Ability and
explicit Special Reward events over the actual final battlefield. No special
rarity is inferred and current Game content supplies no special-event metadata.
Terminal results expose the same facts and event order with explicit overflow
pages. Shared source-budget checks run before battle-entry effects. The existing
renderer protocol and installed binary are unchanged.

Andrew approved verified admission of eleven unchanged one-density UI images
despite the unavailable packaging tool/noncanonical sealed package and absent
licence field. The exact Game receipt records that narrow exception. Character
portraits are not included in that particular batch. The formerly empty portrait
mapping was an unfinished integration step, not a rejection of earlier artwork.
Kaelion's completed Menu.png (512x512) and Dialogue/Neutral.png (768x960) exist
in Art's `output/kaelion-portraits-v1-20260915/package`; all 34 sealed files pass
hash verification. Do not reopen the existing portrait visual/finishing review.
Andrew explicitly answered "Approve the two-file integration" to importing and
wiring this pair with verified hashes despite the unavailable packaging tool
and missing licence field, limited to these two files and no build or publishing.
The existing Game owner has imported the unchanged pair and mapped Kaelion's
menu portrait to Primary Results and neutral bust to Level Up/Learned Ability.
The separate two-file approval, hashes and exceptions are recorded in the
existing Game Results receipt. No permission is pending for this pair. Silent
macOS composed-frame checks verified both families and overlay removal; this
is not a new real-battle playthrough. Other batch exceptions do not silently
extend to it.
The approved portrait scope is the whole starting party: eight distinct menu
and neutral dialogue/bust files. Kaelion-first established the format; it did
not reduce that scope. Andrew's correction, "All portraits should have been
settled", was reconciled with his remaining-three instruction in the existing
Last Legend - Art chat (user turn `b6f1714a-755b-4b03-8ce4-0f741e50b5c1`).
The existing Art producer delivered the remaining six portraits in
`output/starting-party-portraits-v1-20260916/package`; the closed 35-file seal,
individual PNG hashes and coordinator contact-sheet checks passed. Andrew's
exact answer, "Approve six-file integration", accepts the unavailable packaging
tool and missing explicit licence field for these six files only. Game imported
them unchanged and activated both families for Liora, Drazek and Seraphis in the
normal checkout. All eight starting-party portraits are now accounted for;
later main-cast descriptions are outside this batch. No further production,
design or import approval is pending. Neutral initials remain only for actors
without configured portrait art, not as a substitute for this delivery.

Engine now separates catalog path/crop validation from concurrent-frame budgets
through shared `CanvasImagePreflight`: battle/HUD, Primary's at most four menu
portraits and each event's single bust are checked separately before entry
effects. This also covers native UI over a text battlefield. The renderer's
unchanged limits independently bound each snapshot's unique decoded sources
(64 MiB / 1024) and guarded prepared regions (64 MiB / 4096). Crops, clipping and
zero opacity do not remove source cost. Cache eviction drops cache ownership,
not queued/displayed snapshot references; this is not a total-live-memory ceiling.
Results currently replaces stages without overlapping portrait families. Future
crossfades must budget their actual union. No limits were raised, no artwork was
shrunk, and no renderer/protocol or binary change was required. Whole-party Game
frame checks and native inspection are recorded in the integration record below.
Four admitted hover/pressed/disabled/unknown-state assets remain reserved, not
falsely represented as active input or unknown-progression features.

Andrew has approved the Art kit's focused/unfocused/disabled distinction:
"The art kit's focus distinction is approved." Preserve steady focused fill,
bright edge and separate cursor, quiet unfocused appearance without a cursor,
and muted disabled appearance without a cursor. This is design acceptance,
not a claim that every kit state or additional asset is already wired natively.

Engine/Game tests, Renderer headless checks and silent native frame inspections
are recorded in the
[battle integration record](graphical-battle-g1.md#battle-results-integration).
Full native battle playtest acceptance remains a separate gate. This does not authorize all
G5 work. After Results verification, resume captured-entry cinematic routes and
the representative rescue, without displacing independent Game milestones.

## Approved Pause upgrade

The existing Art producer has delivered the root overlay, both confirmations
and interactive browser preview in `output/pause-ui-v1-20260916/package` under
the Art workspace. The corrected delivery passes 23 browser checks, including
72 button-centering samples. The producer is idle pending scoped feedback;
there is no missing Art dispatch or new design approval. Browser checks are not
native/runtime acceptance.

The compact centered root overlay uses Resume, Config, To Title and Exit from
the approved UI kit. Resume starts focused. Preserve the steady highlight/cursor,
restrained 140-180 ms opening fade/scale and reduced motion. Keep the suspended
scene visible without a new full-screen dimmer. No new character/background art
or replacement Config design. Return previews through the Engine coordinator.

Runtime wiring is not delivered by Art or this source inspection. Extend the
existing pause state/input path, never a parallel pause manager. Pause at the
root resumes; Config returns to Pause with Config focused. Back cancels a
confirmation, and Cancel is initially focused for both To Title and Exit. Use
the approved unsaved-progress warning, existing title-return/normal shutdown
paths and no autosave. Consume opening/closing input; pause-menu input and UI
motion continue while ATB, actions and scene progression remain suspended.

Current local-source findings to address before runtime acceptance:
- `BattlePauseState::execute()` calls `setState(runState)`, which calls
  `BattleRunState::enter()` and `engine->start()`. Active-time startup resets
  battle state. Resume must restore the existing running state, not restart it.
- `BattleRunState::execute()` currently continues to run the engine after its
  input handler switches to Pause; the opening input must not reach gameplay.
- Config currently lives in `MainMenuConfigMode`, coupled to `MainMenuState`,
  and Back returns to main-menu commands. Reuse its settings interface with an
  explicit return context; do not build a separate Config implementation or
  resume field gameplay to access it.
- Resize currently calls Pause `enter()` to repaint. Keep repaint separate from
  entry/focus initialization so resize cannot reset a confirmation or selection.

Preserve Results integration and the active cinematic/Game milestone work. Art
can finish independently; runtime/native/terminal acceptance remains Engine-owned
within the bounded upgrade, not blanket G5 scope or publishing permission.

## Controller-ready input and normalized movement

**Queued for G4 field readiness; scheduling only, not started.** Andrew requested
capture on 16 September 2026 without displacing the G-series or Last Legend's
remaining gameplay milestones. The implementation requirements are consolidated
in [the existing input contract](input-sources.md#planned-controller-ready-input-and-normalized-movement),
not another planning package.

Use the next coordinated Engine/Renderer field-input slot after the current
cinematic movement/subject-ownership changes have passed acceptance, respecting
already-scheduled Results commitments. Complete before G5's whole-game input/UI
acceptance and G6 platform/package acceptance. G2/G3 and independent Game
milestones continue in parallel; this is not a prerequisite for their unrelated
work or a reason to pause the programme. No calendar deadline is invented.

Engine owns semantic input state, bindings, movement timing and the shared
Player/Camera/session boundaries. Renderer owns native key transitions and
focus/reset reporting. Game validates real maps, events and cinematic routes.
Before dispatch, agree shared-file ownership and freeze the smallest compatible,
negotiated transition contract against the pinned GPUI APIs. Planning records
no chosen wire fields, dependency or renderer upgrade.

First delivery is reliable GPUI keyboard held/edge state, simultaneous directions
and distance/time-normalized diagonal grid movement with safe collision and
context cleanup. Preserve terminal event-only compatibility, existing menus,
authored route timing and T1 composition. Physical controllers, enhanced terminal
key reporting and continuous subcell animation remain separate later work.
Future controller compatibility is a standing design constraint now. This queue
entry grants no implementation dispatch, art admission, publishing or release
authority; the coordinator resumes it at the stated milestone checkpoint.

## Battle presentation direction

Andrew's production direction is living combatants, expressive magic and summons
that are battle highlights: animated idle breathing, moving hair/robes, visible
weapons, glowing glyphs, energy streams, orbs, bloom and dramatic animated set
pieces. Begin with intentional, presentable simpler treatments and enrich them
incrementally. Static G1 artwork is the current delivery, not the final ceiling.
This direction informs G2/G3 and shared foundations; it does not release their
entire backlog for implementation.

- Separate combatant identity, targeting and combat state from the current pose,
  animation frame and any weapon/effect attachments. Maintain stable registration
  through pose changes without fixing the art pipeline to one animation technique.
- Keep presentation timing and semantic impact cues separate from frame drawing.
  Reuse the existing summon compiler/playback boundary rather than making each
  spectacle a bespoke runtime. Repaint, dropped frames and presentation fallback
  must not lose or duplicate gameplay effects, resource spending or completion.
- Give concurrent effects independent lifetimes and ownership. Compose character,
  magic, summon, camera, UI and audio contributions without one effect clearing
  another or hiding essential target/impact feedback. Renderer capabilities such
  as bloom remain explicit future work, not assumptions baked into game logic.
- Author deliberate simpler alternatives: static or limited-frame poses, compact
  summon sequences and reduced effect density, with meaningful terminal and
  reduced-motion counterparts. Preserve the action's identity, readable impact
  and outcomes; shorter choreography may differ. Select a supported treatment
  before playback, not by concealing invalid content or silently dropping cues.

Current source boundaries: `BattlerArtwork` describes one image/crop, not a full
battler animation controller. `SummonPlaybackSession` already separates elapsed
time and cue traversal from rendering; the existing battle host still uses the
blocking `SummonCutscenePlayer` adapter. Animated battlers, graphical summon
choreography and bloom are not delivered by those foundations alone. Extend
these boundaries when implementing the relevant slice, retaining shared lifecycle
and cleanup behavior rather than adding scene-specific exceptions.

## Cinematic gap schedule

Andrew requested scheduling with **Ichiloto - Renderer Planning** on
16 September 2026, then replied "Approved." to the planner's proposed schedule.
The planner, Renderer, Game and Art owners supplied the dependencies below.
This is the next bounded presentation work queue, not a
new cinematic framework or blanket G2-G6 implementation approval. Scene-specific
decisions remain in the private Game presentation roadmap and existing milestone
handoff. The [cinematic contract](../cinematics.md#current-graphical-boundary)
records the current limitations separately from this planned work.

| Slot | Owner and deliverable | Start condition and completion gate |
| --- | --- | --- |
| 0: immediate preparation | Engine coordinates Game, Renderer and Art. Freeze one existing rescue segment, subject identities, entry/continuation, visual takeover, route/facings and minimum poses. | Game supplied source-checked routes, subject/facing and root-trigger entry proposals with outcome constraints; this is not runtime acceptance. Engine must settle captured-entry walking/return and temporary real-subject transform recovery, separately from visual suppression. No nested cinematic inside an active common event. Already-approved narrative corrections continue independently. |
| 1: Engine implementation in progress | Engine preserves graphical field continuity, adds optional staged graphical providers and scoped presentation takeover for existing subjects. Renderer owns regression coverage against actual emitted frames. | Field continuity, staged sprites/sheets, real-subject leases, paired suppression, visual replacement, transform recovery and pre-finalizer skip restoration are implemented locally and tested. Captured-entry walking/return, application quit/crash scene teardown and representative native acceptance remain. The battle-opening slice is checkpointed separately from cinematic work. No production art dependency for ownership tests. |
| Parallel Art slot | Art prepares only the first rescue's required figures, support pose and visible prop, using existing approved references. | Freeze participants, route-visible facings, dimensions, pivots and crop layout first. Preview/admission of new art remains explicit; prior batch exceptions do not transfer. Full cast animation sets are not prerequisites. |
| 2: first playable rescue | Game binds the stable Engine seam and admitted art to the existing scene and all its callers. Engine coordinates acceptance. | Slot 1 and required art ready. Visible approach/withdrawal, one representation per subject, intact graphical field, identical terminal story outcomes and correct continuation. Use the normal Game checkout, not another build. |
| 3: remaining representative scenes | Game and Art reuse the rescue foundation for the distant sighting and environmental response/evacuation. Engine adds only demonstrated missing graphical effect adapters. | Rescue gate passed and each scene's staging/art scope settled. Content may proceed in parallel; native tests remain serialized under one window/input owner. |
| 4: integrated acceptance | Engine consolidates Game state checks, Renderer native observations and Art review. | One normal pass per representative scene, terminal counterparts and applicable legal-skip/reduced-motion checks. Present one consolidated acceptance request, not questions scattered across tasks. |

### Contract and scope gates

- Apply the project-wide production-direction rule to each implementation, not
  just its demo. The [cinematic extension boundaries](../cinematics.md#production-extension-boundaries)
  cover replaceable poses, independently owned effects and future controlled
  voice playback. Account for these in subject/session ownership now without
  treating the approved rescue as authorization for a speculative framework.
- Reuse the existing interpreter, routes, camera, animation timing and cleanup.
  PHP owns state; graphical providers observe it. Do not create a second route
  for a visual clone or a second collidable entity.
- Graphical field availability must follow presentation ownership and authored
  visibility/cover, not simply remove the current active-cinematic exclusion.
  Keep dialogue and transition covers above the world, and clear old content
  when menus, battles or map transfers replace it.
- Presentation suppression is map- and session-scoped, independent of authored
  NPC eligibility, wandering and collision. In particular, do not filter
  `visibleNpcs()` merely to hide artwork: it also supplies `npcAt()`. Normal and
  direct movement/facing redraws must respect takeover. Release re-evaluates
  current world state rather than forcing an evacuated NPC visible. A paired
  pose must also suppress the corresponding ordinary player representation.
- Existing field sprites, source rectangles, explicit layers and complete
  snapshots suffice for the first cell-aligned slice. Renderer source audit
  found no required protocol/runtime change. Add a regression sequence using
  existing renderer tests, not a new framework or rebuild by default.
- Smooth sub-cell movement, precise hand/head attachments, per-instance alpha
  and canvas-over-field composition are real gaps, not delivered capabilities.
  Do not bake the field into a canvas to conceal them. Scope any required
  extension separately; the first slice uses supported cell movement and covers.
- Complete, legal skip, partial-start failure, controlled failure, transfer and
  shutdown must release temporary cast/bindings/effects. Test re-entry, same-ID
  NPCs on another map, camera/input restoration, pause/resume, resize, reduced
  motion and no duplicate outcome writes. Preserve save guards and deferred
  autosave; do not relax irreversible skip rules or undo legitimate story state.
- Temporary movement of real subjects requires scoped position/facing recovery
  on failure, not just revealing their old artwork. Keep it map/session-bound.
  Captured-entry walking and return must not become teleportation or duplicated
  scripts per start cell. Game retry guards must distinguish a recorded choice
  from completed scene outcomes; test failure before and after outcome writes.

### Scheduling and evidence

The coordinator owns remaining Engine implementation and dependent Game/Renderer
handoffs; Andrew is not expected to watch each task. No implementation release
is implied by a queue position.
Battle Results art has a separately approved next-slot reservation in the
existing private Game presentation roadmap: it follows the first bounded
priority rescue-art handoff, not every later cinematic or G2-G6 milestone.
Andrew subsequently accepted the missing character-appearance proposal with
"Love it. Approved." The existing Art producer owns the approved reference;
runtime pose/crop validation and production admission are separate from that
appearance acceptance. Exact character/staging details stay in private docs.
The first bounded rescue-art batch has delivered local candidate frames and
previews; runtime registration and admission remain pending. The existing Art
producer subsequently delivered the approved Battle Results and Pause Menu
packages; the current Pause delivery is recorded in [Approved Pause upgrade](#approved-pause-upgrade).
There is no preceding active Art batch. This is Art-only work, not Pause runtime
integration or permission to redesign Config/mechanics. Previews return through
the coordinator; no further design decision is pending. Andrew
approved the rescue batch's local resize, stray-transparency trimming and
sprite-cell alignment, then added
"And approved for similar future requests." Preserve originals and make no
design changes; this is not blanket appearance, admission or publishing approval.

The first Engine implementation tranche passes 2,130 tests on each of PHP 8.4
and 8.5 (12,364 assertions and one existing skip each in the final run), plus
PHPStan. Coverage includes staged route-frame progression, independent actor
identity, terminal provenance, explicit overlay/cover ordering, unchanged field
tiles during cinematics, completion/failure/legal-skip cleanup and same-ID
re-entry. Renderer state/composition regression passes 19 tests against nine
actual PHP-emitted FIELD snapshots, including mixed terrain/cast/effects/covers
and full scene replacement. Xcode's installed Metal toolchain became available
after its component-status check; normal compiler commands and the unchanged
headless build then succeeded with approved compiler-service access. No download,
shader workaround, protocol change or native window was needed. These Renderer
tests are macOS headless checks, not GPU/native acceptance. Real-subject takeover
and recovery are not delivered by these
tests. Game separately reports its retry fix passing 66 targeted cases / 5,334
assertions, with combined narrative checks 157 / 7,788 on both PHP versions;
these are interpreter/state checks, not staged rescue or native acceptance.
The first rescue art has local preview cells; paired contact/release registration
still needs technical validation before admission. Do not alter world coordinates
or renderer semantics to conceal a visual alignment mismatch.

The original planning pass inspected source and received owner reports; it did not run
new runtime tests, generate assets or open a game window. Preserve current
uncommitted Engine/Game work and author saves/configuration. Automated native
launch requires verified silence without changing Andrew's normal settings.
Linux/WSLg remains untested; macOS evidence cannot establish other-platform
acceptance. The broad Last Legend suite was not completed because of the
unrelated 180,000-battle simulation baseline. The dark-player-on-dark-field
issue remains an art/background concern. No renderer protocol or game-content
changes were part of this planning pass.

Local checkpoint verification on 16 September 2026: the combined Engine work
passes 2,205 tests (13,058 assertions, one existing skip) on PHP 8.5.10 and full
PHPStan analysis. Battle openings, cinematic foundations and Results are separate
local commits. This does not close the remaining cinematic or native acceptance
gates, run the broad Last Legend simulation suite, or authorize publication.
