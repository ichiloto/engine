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
| G2 | Battler animation and combat feedback. Engine/Renderer with Game Design and Art. | G1. | Backlog, not authorized. Scoped brief: the [effect animation plan](../effect-animation.md) (Phases 0-2). Still needs animation/feedback acceptance evidence. |
| G3 | Graphical summon presentation. Engine/Renderer with Game Design and Art. | G1/G2. | Backlog, not authorized. Scoped brief: the [effect animation plan](../effect-animation.md) (Phase 2 renders summons through the unified session, making this a content and acceptance gate). Still needs terminal parity gates. |
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
bright edge, quiet unfocused appearance without a cursor,
and muted disabled appearance without a cursor. This is design acceptance,
not a claim that every kit state or additional asset is already wired natively.
Andrew's 21 September refinement reserves cursors for list items, not buttons;
the Results confirmation retains its steady fill/border without a cursor.

Engine/Game tests, Renderer headless checks and silent native frame inspections
are recorded in the
[battle integration record](graphical-battle-g1.md#battle-results-integration).
Full native battle playtest acceptance remains a separate gate. This does not authorize all
G5 work. After Results verification, resume captured-entry cinematic routes and
the representative rescue, without displacing independent Game milestones.

## Approved Pause upgrade

**Next after the first playable rescue.** On 17 September Andrew directed,
"Then after that the Pause menu." Engine owns this next bounded runtime slot,
before the remaining representative cinematics and controller-input work.
Independent Game M3 work continues alongside it. Reuse the already-approved
delivery below; do not reopen design approval or commission another Art batch.

The first rescue technical gate closed on 17 September after the corrected
ordinary native recheck below. Pascal, the existing Engine subagent, completed
the Pause implementation and focused checks; the coordinator reviewed it and
ran the full Engine suites. Four-option focus, confirmations, shared Config
interaction, retained-frame composition and scoped Run suspend/resume are
implemented. Runtime edits and Game's serialized muted live checks use separate
windows. The bounded terminal and macOS native technical checks below are now
complete; this is not a claim of Andrew's personal playtest acceptance.

Validation checkpoint after the Config opacity correction: 2,411 Engine tests
pass on each of PHP 8.4.21 and 8.5.10, with one existing skip (16,466 and 16,465
assertions respectively). Full PHPStan on both versions and the whitespace check
pass. Full suites use the established process-local 1 GiB memory budget; an
initial default-128 MiB run exhausted memory, not an assertion. No persistent
configuration was changed. These include real ATB retention, Config lifecycle,
ordinary battle completion, partial-entry disposal, title/quit abandonment and
failing cleanup callbacks. Linux/WSLg remains untested.

Sibling compatibility checks on both PHP versions also pass: Console ran all
eight unchanged scripts (six pass, two Game-dependent skips); Editor reports
919 passed / 50 skipped / 4,664 assertions. These headless checks used the
current sibling Engine classes/helpers through a temporary test bootstrap,
without vendor edits or pinning normal Game data. Game-dependent optional paths
are not covered by this compatibility run.

The isolated 135x36 terminal check has completed with normal exit 0. An authored
Service Road Vermin battle played through Victory/Results and returned to the
field without Pause or forced outcomes. A second battle verified Resume, the
pause shortcut, Config return focus, Cancel-default confirmations and return to
Title. Live paused combat state remained unchanged; the longer deterministic
freeze/remaining-duration cases are covered by the headless suite.

The first native pass also completed an ordinary victory and field return,
Resume/button shortcut, Config return focus, both Cancel-default confirmations
and confirmed Exit with normal exit 0. Root Pause/confirmations were centered
and opaque without a full-screen dimmer. It exposed one visual defect: Config's
owned Console runs retained null backgrounds when projected into the canvas,
where null means transparent. The fix reuses the existing battle adapter's
opaque-owned-cell conversion, preserving explicit selection colors and the
uncovered battlefield. Both live sessions closed before corrective edits;
normal config plus all nine saves matched their pretest hashes.

The corrected native recheck passed in reduced-motion mode using the same normal
checkout and existing renderer. Config's main and description panels were fully
opaque, with no battlefield/HUD bleed-through. Selection and description updates
worked; Back restored Pause with Config focused. Root Pause retained centered
labels and visible selection without a full-screen dimmer. The Title confirmation
defaulted to Cancel; deliberate confirmation returned to a clean Title screen,
then normal Exit closed the process with exit 0. Combat context/gauges remained
unchanged through the pause; all ten normal config/save hashes still matched.
No test window remains open. Captures were inspected inline, not saved as native
PNG evidence. These checks are macOS only and do not complete the broad Game
suite or the pending graphical Config redesign.

Game owns the normal-checkout kit import and catalog wiring. Andrew approved
the two remaining unchanged images, `Controls/ButtonNormal.png` and
`Controls/FocusRing.png`, with "Game can go ahead and import the approved kit."
This answered the exact two-file unavailable-packaging-tool/missing-licence-field
exception; it is not a blanket exception for other assets. The approval and
typed catalog contract have been relayed to the existing Game owner. Game has
completed the two-image import and typed `BattlePauseSkin` catalog wiring. Both
copied hashes match the approved package; all 21 previously admitted UI images
are unchanged. Six focused asset/catalog/Results tests pass with 271 assertions
on each of PHP 8.4.21 and 8.5.10. The existing Game UI receipt records the exact
exception and provenance; this is not formal packaging-tool admission or native
acceptance. No new build, publishing, substitute assets or further design
approval is authorized.

At 06:15:45 Africa/Lusaka on 17 September, Andrew's running game crashed on
ordinary post-victory cleanup at `BattlePauseState::exit()`: its loaded Console
class lacked the newly added `removeLayer()` method. Shared Engine edits during
his playtest exposed mixed loaded/new source. Fresh Engine and Game processes
now resolve the same Console class with that method present. Composer autoload
regeneration does not reload classes in an existing process. The coordinator
accepted responsibility; Andrew then explicitly lifted the incident hold and
waited for implementation/validation. Coordinate shared runtime edits with
playtest windows instead of adding missing-method or hot-reload workarounds.
Cleanup must release only resources actually owned by an entered Pause state;
ordinary battle completion and repeated disposal have dedicated regressions.

The existing Art producer has delivered the root overlay, both confirmations
and interactive browser preview in `output/pause-ui-v1-20260916/package` under
the Art workspace. The corrected delivery passes 23 browser checks, including
72 button-centering samples. The Pause delivery is complete; the producer's
next menu delivery is the Config extension below. No new Pause Art dispatch
or design approval is needed. Browser checks are not
native/runtime acceptance.

The compact centered root overlay uses Resume, Config, To Title and Exit from
the approved UI kit. Resume starts focused. Preserve the steady highlight/cursor,
restrained 140-180 ms opening fade/scale and reduced motion. Keep the suspended
scene visible without a new full-screen dimmer. No new character/background art
or replacement Config design. Return previews through the Engine coordinator.

Runtime wiring is Engine-owned, separate from Art's browser proof. Extend the
existing pause state/input path, never a parallel pause manager. Pause at the
root resumes; Config returns to Pause with Config focused. Back cancels a
confirmation, and Cancel is initially focused for both To Title and Exit. Use
the approved unsaved-progress warning, existing title-return/normal shutdown
paths and no autosave. Consume opening/closing input; pause-menu input and UI
motion continue while ATB, actions and scene progression remain suspended.
Andrew subsequently rejected the redundant "Confirm / Back" footer. Omit it
from both terminal and graphical Pause/confirmation presentation and the Art
preview; do not replace it with another hint strip. Existing controls and the
actual menu/confirmation choices remain unchanged.
This footer-only follow-up passes 26 Pause presentation/lifecycle tests with
2,359 assertions on each of PHP 8.4 and 8.5. No additional native window was
launched for the text removal; the earlier live validation above predates it.

Implementation acceptance checks from the original source review:
- Resume restores the existing running state rather than calling battle entry
  and `engine->start()` again, preserving active-time context and queued actions.
- Opening Pause stops the current Run execution before it advances gameplay.
- Config reuses the settings interaction with an explicit return context, not
  a duplicate implementation or a detour through live field gameplay.
- Resize repaints without re-entering Pause or resetting focus/confirmation.
- Paused alert/feedback deadlines preserve their remaining duration, and Config
  removes only its owned cells on return, resize or abandonment.

Preserve Results integration and the active cinematic/Game milestone work.
Runtime/native/terminal acceptance remains Engine-owned
within the bounded upgrade, not blanket G5 scope or publishing permission.

### Config and shared menu Art

On 17 September Andrew asked Art to provide Config assets and assets for the
menu system generally. The existing `Create Last Legend UI art kit` producer
accepted the Config-first extension: inspect actual settings/controls, reuse
the approved kit, and deliver composable assets, state/sizing specifications
and an interactive preview through the Engine coordinator. Shared main-menu
list/detail components follow, then secondary-screen adaptations based on the
existing menu inventory. Preserve the separately active title-screen proposals.
Update the existing Art specification rather than creating competing plans or
flattened screen images. This production scope does not authorize runtime
integration, new imports/licence exceptions, paid providers or publication.
The current Config readability correction does not wait on Art or replace its
design work; settings behavior and presentation remain separate.

Art's source inspection confirmed eleven settings in one ordered list, with
descriptions below and immediate persistence. Preserve that interaction rather
than inventing categories, Apply/Reset actions or settings. Existing frames,
selectors, focus states, slider and scrollbar assets cover the first delivery;
the Config composition extends the existing kit without changing its sealed
base or the accepted Pause/Results assets. Text, values and control hints remain
independently composable.

The first Config Art delivery is complete in the existing Art workspace at
`output/ui-art-v1-20260915/config/Config-Review.html`. Its adjacent `spec.json`,
component reference and asset-reuse inventory describe the native adaptation;
the existing `HANDOFF-NOTES.md` holds the wider menu sequence. No new raster
assets were generated: eighteen existing identities are reused. Sixty silent
headless-browser checks pass, including complete visible-row counts at three
canvas sizes, opacity, focus, reduced motion and centered labels. This is a
browser/spec delivery, not native integration or Andrew's design acceptance.
The shared Main Menu/list-detail Art foundation is also delivered at
`output/ui-art-v1-20260915/menus/Main-Menu-Review.html`, with the same adjacent
specification/component/source-inventory structure. It preserves the established
Main Menu arrangement, all source fields and approved portrait apertures, while
distinguishing Order's source mark from current input focus. Fifty-seven silent
browser checks pass; the coordinator inspected the Main Menu and scrolling
specimens and verified all 23 extension checksums. The list/detail specimen is
component demonstration data, not a completed inventory/shop runtime. Existing
sealed packages remain unchanged; no new raster assets or runtime changes were
part of this delivery. Source-specific Items, Equipment and Status compositions
are the next Art slot under the existing inventory, not a new visual direction.

Source inspection during those adapters found an existing Items paging gap:
`ItemSelectionPanel` advertises page controls and exposes counts, but writes the
entire item array without a page slice, counts rows using outer window height,
and refreshes its page title only when items are assigned. The Use, Discard and
Key Items modes only handle vertical item navigation, not page actions. Runtime
adoption must resolve visible capacity, selection/page ownership and input
together for all consumers, with terminal and graphical regressions for empty,
overflowing and changing inventories. Art may show supplied page metadata, not
claim working gameplay pagination. Effective character/equipment example values
must come from the existing isolated Game actor setup, not raw authored base
arrays or an Art-side reimplementation of stat curves. This records integration
work; no runtime paging fix was made in the Art delivery.

**Current menu integration checkpoint (21 September):** shared, theme-driven
Main Menu, Equipment and Status presentation is implemented locally. Character
focus uses the same selected background as commands across the card interior,
including padding, with no per-name cursor. Party-order source marking remains
distinct from destination focus. Optional frame `borderWidths` (left/top/right/
bottom source pixels, separate from corner `cuts`) partition opaque straight-edge
backing below selection and decorative edges above it; corners are painted once.
Widths reconcile against current artwork and density, not historical image bytes.
Shared Alert, Confirm and
Select modal presentation keeps supported graphical menus underneath instead
of replacing the whole screen with terminal cells. Existing modal controllers
retain input and outcomes; unsupported text-entry/dialogue or oversized content
still uses diagnosed terminal fallback. This is not complete menu coverage.
Single-confirmation alerts center their message and place a half-content-width
button at bottom right; button labels remain centered independently of cursors.
Choice dialogs retain their separate layout. Last Legend sets `showInputHints`
to false: keep useful descriptions, remove persistent helper strips and use the
existing Controls entry for full binding lookup. The shared hint mechanism stays
available to other themes (default true); this does not change input bindings.
Location and the bottom help panel share a measured row height. Removing hints
must not shrink help below Location or leave a gap beneath the fourth party card;
longer content in either bottom panel grows both together.
Oscillating cursors belong to list items, not buttons. Shared row composition
distinguishes command-list entries from buttons while reusing centered labels
and theme treatments. Equipment actions, alert/confirmation buttons, Results
confirmation and Pause confirmation buttons use steady focus without a cursor;
Main Menu, vertical choice lists and Pause root navigation retain list cursors.
Earlier focused validation on PHP 8.4 and 8.5 passed Engine 276 tests / 6,776
assertions and Game 474 tests / 39,897 assertions. After the footer/list-button
refinements, the four shared menu suites pass 135 tests / 3,931 assertions and
the actual Game Main Menu suite passes 18 tests / 476 assertions on both PHP
versions. Shared presentation static analysis passes. A fresh silent macOS GPUI
replay visually confirms aligned bottom panels in command/character focus,
full-card highlighting, absent helpers, and the centered alert with its
half-width bottom-right cursor-free button. This is exported-frame visual
validation, not a fresh interactive Game playthrough. The replay shut down
cleanly; no Game audio/config/save changes or new build were used.
Linux/WSLg remains untested. The broad Last
Legend suite was not completed because of the unrelated 180,000-battle
simulation baseline.

Andrew approved the current UI kit for local integration on 21 September; the
exact approval and current specifications are in the existing Art
`output/ui-art-v1-20260915/HANDOFF-NOTES.md`. No repeat visual approval is pending.
Engine owns shared adapters and native acceptance; Game owns theme admission and
source-bound validation. This does not grant publication or waive admission gates.
Items now has a shared graphical adapter over its existing command, inventory,
target and quantity modes. Terminal paging slices actual inner capacity and
follows a clamped selection; horizontal navigation changes pages in Use, Discard
and Key Items, while quantity selection retains its separate fine/coarse axes.
The graphical list follows that same selected item without hiding later records.
The regular-item view consistently excludes equipment and quest-critical key
items, including after sorting, discarding and stack depletion. Empty Key Items
remains a reachable read-only view. Descriptions retain the complete source text;
oversized native content is diagnosed rather than silently omitted. Focused
Items/quantity/consumption checks pass on PHP 8.4 and 8.5. Silent macOS native
frame replay checked commands, late inventory, quantity and the item-use alert;
the alert restores the item description instead of retaining the inactive
quantity prompt. No new Game artwork or separate build is required.

Config now uses the existing shared `ConfigMenu` owner with one graphical
composition for Main Menu and Battle Pause. Engine checks cover lifecycle,
capability fallback, bounded canvas geometry and complete setting values;
Game checks cover all 11 settings, isolated persistence, Main return focus and
Pause -> Config -> Pause without resetting the suspended battle. Silent macOS
native replay checked first/last settings and the retained battlefield. This is
not a full native gameplay playtest. Config currently uses theme-neutral slider,
scrollbar and arrow fallbacks: admission of eight optional approved-art images
is awaiting Andrew's separately presented technical import decision. The already
admitted Divider is bound through the shared theme. No protocol changes, new
builds or normal-game configuration/save changes were part of this slice.

The combined Engine regression selection passes 274 tests on each of PHP 8.4
and 8.5. Game's focused Main/Items/Config selection passes 30 tests on each,
and its six presentation/rescue suites pass 465 on each. The broad Last Legend
suite was not completed because of the unrelated 180,000-battle simulation
baseline. Linux/WSLg remains untested. Oversized descriptions/status still
diagnose a terminal fallback; general detail scrolling and Editor presentation
authoring remain explicit follow-on work, not completed runtime features.

Abilities and Magic now share a graphical composition over their existing
owners, with all tabs, learned/ready counts, source notes, learning requirements,
sorting and field-spell target selection. Actor portraits retain stable role
bindings; optional skill icons reuse the game's already-admitted book icon.
Magic's displayed requirement progress/status now receives the same story flags
as learning. The shared terminal ability/magic list no longer double-offsets
scrolled rows. Learning rules, casting outcomes and field target ownership are
unchanged. Shared word-aware wrapping keeps source notes and hyphenated item
names intact where they fit, using the same Canvas cell budget for measurement
and painting without discarding text.

Final focused Engine regressions pass 361 tests / 10,291 assertions on each of
PHP 8.4 and 8.5. Game checks cover all four real actor books, learning charges
once, story gates, all tabs, sorting, Cure target/cancel/confirm, no-effect and
insufficient-MP feedback, modals and terminal fallback: 47 focused cases and
482 cases / 43,319 assertions across its six scoped suites on each PHP version.
Silent native frame inspection checked Abilities, learning/source wrapping,
Magic target selection and retained graphical alerts. No full native gameplay,
Linux/WSLg, general oversized-prose scrolling or Editor-authoring acceptance is
implied; the earlier broad-suite limitation remains unchanged.

Quests, Records and Controls now have shared graphical adapters over their
existing state/input owners. Andrew's 21 September correction supersedes the
graphical Quest list-or-journal layout: navigation remains visible on the left,
with independently styled identity, Description, Objectives and Rewards on the
right. Semantic sections supply text and icon roles together; the painter does
not infer meaning by parsing formatted strings. The game reuses its admitted
book and inventory art through theme bindings, with neutral objective ring/check
fallbacks rather than Unknown artwork. The detail viewport uses a scrollbar and
steady reading focus without a redundant text footer. Records keeps the existing
Info panel with dedicated reading focus. Both use a PHP-owned source-text
position and measured pages for complete terminal/native scrolling,
including long objectives, rewards and earned reports. List previews explicitly
indicate shortening and retain complete text in the detail view. Back restores
the same list selection; tab changes retain the existing owner semantics.
Secret achievements and undiscovered/unearned knowledge remain filtered before
presentation. Structured quest-item rewards now use existing reference/name
resolution and quantity semantics without instantiating reward objects merely
to display them; granting and progression are unchanged.

Terminal Quests and Records reuse the existing horizontal tab component and
retain their established navigation. Pagination belongs in the corresponding
Window help/border field, never in its content rows. The current Window supports
bottom-left help, so that approved fallback is used without fake right-padding.
Quest list/detail ranges and Records list/reading ranges use this path; content
now uses the freed rows. Native composition batches document text and objective
markers, and measures wrapped tab headers rather than assuming one text line.

Controls is the dedicated binding lookup even when a theme hides inline hints.
It shows the primary owner-supplied control and every selected keyboard alias,
supports existing rebinding/defaults/cancellation, and distinguishes a failed
save from a successful session-only rebind. Consumed action edges cannot also
navigate or bind themselves. Control glyph roles remain replaceable through
the existing theme registry; absent art uses readable labels, not Unknown icons.
Current keyboard controls and replaceable display providers do not constitute
physical gamepad detection or support. No new controller mapping is installed.

Final combined Engine regressions pass 450 tests / 12,683 assertions on each of
PHP 8.4 and 8.5, including horizontal tabs, border pagination, dense/wrapped
themes and complete journal text. Scoped static analysis is clean. Game's
focused menu checks pass 68 cases / 6,079 assertions; its six scoped suites pass
503 cases / 45,149 assertions on each PHP version. Silent native frame inspection
checked Controls lookup/listening and an explicitly supplied Xbox-family display
example, plus the corrected real Quest layout and Records/Field Index. These are
renderer-frame checks, not a full native gameplay or physical-controller test.
Normal configuration and saves were untouched; no renderer protocol or game
content/progression changes are part of this UI slice. Linux/WSLg remains
untested, and the earlier broad-suite limitation remains unchanged.
Main Menu Quit currently routes its chooser directly to title/exit; confirmation
parity with Pause is an existing behavior gap, not part of this presentation fix.
Editor TUI authoring of the theme and shared artwork-role bindings remains
planned in the Editor roadmap, not implemented by runtime composition.

## Controller-ready input and normalized movement

**Queued for G4 field readiness; scheduling only, not started.** Andrew requested
capture on 16 September 2026 without displacing the G-series or Last Legend's
remaining gameplay milestones. The implementation requirements are consolidated
in [the existing input contract](input-sources.md#planned-controller-ready-input-and-normalized-movement),
not another planning package.

Use the next coordinated Engine/Renderer field-input slot after the current
cinematic movement/subject-ownership changes have passed acceptance, respecting
already-scheduled Results and next-slot Pause commitments. Complete before G5's whole-game input/UI
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

**Art acceptance, 21 September:** Andrew approved the revised Kaelion v3 idle
and eight Victory poses (Kaelion, Liora, Drazek, Seraphis, Nivira, Aeryn, Orwin,
Thalric). Drazek's subsequent crossed-arm/no-visible-knife choice supersedes his
knife-in-hand Victory only; use Art's updated export/receipt, not the original
batch entry. The existing Art workspace's `output/cowork-art-review-20260921/
receipts.json` records the exact approval and provenance. Separate ready-pose
and portrait corrections are outside this approval. Game owns the bounded
admission/readiness assessment; visual acceptance is not a new import exception
or a claim of native playback. The approximately 85.5 MiB, 144-frame APNG is a
review master, not a runtime asset to import wholesale. Establish game-ready
packing, stable registration, shared playback and reduced-motion behavior under
the existing effect-animation/G2 sequence. Do not start a duplicate animation
system or treat approval as authorization for the entire G2 backlog.

The bounded Game assessment is complete: all eight current Victory PNGs pass
Engine limits and local decoding; no images were imported. Aeryn's existing
Victory is the source of the approved artistic correction, not a byte-identical
or resize-only replacement. Shared actor pose-role selection and Results' final
battlefield capture lifecycle must be implemented before Victory can be wired;
Editor role selection/round trips are also absent. Adding art must not add party
members. The idle APNG exceeds the 16 MiB encoded limit; its 144 full-size frames
would consume 546.75 MiB decoded. It needs bounded atlas preparation against the
whole-scene resource budget, stable anchors and PHP-owned playback with pause,
cleanup and reduced-motion semantics, not an APNG or walk-animation shortcut.
Package/licence admission remains unresolved: `game-dev` is unavailable and the
Art directory has no sealed admission package or licence declaration. No limit
bypass, unknown-licence exception, asset overwrite or full G2 work is authorized
by this assessment. Keep this behind current UI and audited Phase 0 sequencing.

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
| 0: preparation complete | Engine coordinates Game, Renderer and Art. Freeze one existing rescue segment, subject identities, entry/continuation, visual takeover, route/facings and minimum poses. | Game supplied source-checked routes, subject/facing and root-trigger entry proposals with outcome constraints. Engine's captured-entry walking/return and real-subject transform recovery are implemented and headless-tested, separately from visual suppression. No nested cinematic inside an active common event. Already-approved narrative corrections continue independently. |
| 1: Engine seam implemented | Engine preserves graphical field continuity, adds optional staged graphical providers and scoped presentation takeover for existing subjects. Renderer owns regression coverage against actual emitted frames. | Field continuity, staged sprites/sheets, real-subject leases, paired suppression, transform recovery, pre-finalizer restoration, captured-entry walking/return and handled application teardown pass local regression tests. Temporary battles suspend/resume the caller; inactive fields cannot repaint over battle. The first rescue acceptance is recorded below; later scene integrations are not implied. No production art dependency for ownership tests. |
| Parallel Art slot: complete | Art prepared and registered only the first rescue's required figures, support pose and visible prop using existing approved references. | Andrew approved integration of the exact corrected 13-image batch on 17 September, including its scoped technical admission exceptions. Game rechecks hashes and records provenance when copying; this is not blanket approval for later batches. Full cast animation sets are not prerequisites. |
| 2: First rescue technically accepted | Game imported the approved art and wired the existing scene through its three root callers; Engine reviewed actual native captures. | All 21 legal entries pass normal/reduced-motion live-script checks, plus expanded interruption/retry cases. Ordinary terminal and ordinary/reduced native functional walkthroughs passed. Corrected companion spacing passes the ordinary native recheck, with exact return/cleanup and untouched author files. No fresh reduced native run after that spacing correction or Andrew aesthetic acceptance is claimed. Use the normal Game checkout, not another build. |
| Complete: Pause menu technical gate | Pascal completed the existing pause state/input upgrade; the Engine coordinator reviewed it and passed full dual-PHP/static checks. Game completed the approved two-image import/catalog wiring and serialized muted terminal/native checks, including the corrected opaque Config surface. | Ordinary battle completion, suspended gameplay, Config return focus, safe confirmations, reduced motion, Title and normal exit verified on macOS. All test windows closed; normal saves/config unchanged. Andrew's personal playtest remains separate. Graphical Config/shared-menu Art proceeds in parallel with independent M3 work; coordinate any further runtime edits with playtesting. No new build or publishing. |
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
The first bounded rescue-art batch has delivered registered frames and previews.
On 17 September Andrew answered "Approve this 13-image integration", accepting
the unavailable packaging/formal-validation tool and missing explicit licence
field for this exact corrected batch only, with verified hashes and local image
checks. The Game and Art owners received the approval. Game must recheck the
current manifest and preserve the scoped exception/provenance at import. No new
build or publishing is authorized, and static registration is not native rescue
acceptance. The existing Art
producer subsequently delivered the approved Battle Results and Pause Menu
packages; the current Pause delivery is recorded in [Approved Pause upgrade](#approved-pause-upgrade).
Those Art deliveries are complete; the separately authorized Config/shared-menu
extension is recorded above. Art delivery is not runtime integration or
permission to change mechanics. Previews return through
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
At that earlier validation point, the rescue art had only preview cells.
Paired contact/release registration and the batch-specific approval have since
been completed as recorded above. Do not alter world coordinates or renderer
semantics to conceal a visual alignment mismatch.

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

Closure validation on 17 September 2026: the complete Engine suite passes
**2,272 tests / 13,418 assertions, one existing skip**, on each of PHP 8.4 and
8.5, with full PHPStan clean. These full-suite runs used a test-process-only
1 GiB memory allowance; the default 128 MiB runner exhausted memory in the
large combined suite. Product configuration and planner bounds are unchanged.
Coverage includes actual inverse walking/facing, stale/duplicate history,
blocked routes, reduced motion, subject exclusivity, real-subject rollback,
throwing/reentrant cleanup, caught startup/loop errors, battle suspension,
return, abandonment and inactive-field presentation isolation. Handled crashes
are covered; OS termination and power loss are not recoverable guarantees.
Renderer reran four existing full-replacement tests using its unchanged local
test executable; that is headless regression evidence, not fresh compilation
or native rescue acceptance. No renderer protocol change or new build was
needed. Game's regular non-simulation suite separately passes 468 tests after
integrating its accepted narrative, presentation and passage-reveal checkpoints
into local `develop`. The broad Last Legend suite was not completed because of
the unrelated 180,000-battle simulation baseline. Linux/WSLg remains untested;
no native/game/audio window was launched by this closure pass.

Subsequent Game integration on 17 September imported and rehashed the exact 13
approved images, added the three real cinematic root callers and shared rescue
choreography, and updated the persistent clear-position endpoint. The candidate
passes 112 route/production tests with 5,941 assertions, including all 21 legal
entries in normal/reduced-motion modes, pre-outcome interruption/retry,
post-outcome failure convergence for all three callers and task-order hydration.
These are headless checks, not native acceptance. Shared Editor/Console
validation now follows common events in their actual cinematic or legacy
context, retaining malformed-route, missing-reference and cycle failures. Strict
Last Legend validation passes on PHP 8.4 and 8.5. A route-constructor diagnostic
regression was corrected in Engine to retain the missing subject identity and
map; the original Editor preview assertion was preserved. Full Editor suites
pass 915 tests / 4,564 assertions with 54 skips on each PHP version; full Engine
suites, including the subsequent shared shop ownership and shutdown fixes, pass
2,370 tests / 13,985 assertions with one existing skip on each; full PHPStan is
clean.

Game completed the muted ordinary-timing terminal walkthrough at 135x36,
including visible contact/withdrawal, captured position/facing restoration,
correct caller completion, resumed movement and menu/field reconstruction.
Its owned PTY exited normally with no staged actors left. Game's before/after
hashes matched for configuration and all nine normal saves. Native graphical
verification was initially deferred after a locked-Mac result. Andrew subsequently
confirmed the Mac was unlocked on 17 September; fresh coordinator and Game
computer-use inventories succeeded. The stale lock status is no longer a blocker.
Game owns the resumed muted native ordinary/reduced-motion validation; native
registration remains separate from functional acceptance. Both native runs have
now completed with ordinary and reduced-motion timing: exact entry position and
facing restored, camera following, no staged actors left, correct one-time
outcomes, resumed movement/menu use and normal Quit with exit code 0. The
ordinary run also confirmed that re-interaction does not replay the rescue.
Configuration and all nine current saves matched before/after each run; all
owned windows closed. Native captures revealed crowded companion blocking:
one companion obscures the brace holder, and the approach crowds the paired
rescue subjects. Art verified the approved hashes, crops and anchors; overlapping
opaque-content bounds and same-layer author order explain the obstruction.
Game corrected actual staged positions and collision-checked routes, preserving
the brace relationship, rescue outcomes and captured return. Expanded route and
retry checks pass 413 tests / 16,198 assertions on each PHP 8.4 and 8.5; the
coordinator independently reran that suite on PHP 8.5. One corrected ordinary
native recheck passed: the coordinator viewed the waiting, withdrawal and
release captures and confirmed both diagnosed occlusions were resolved. Exact
entry position/facing, camera follow, zero staged actors, correct one-time
outcomes, resumed movement/menu use and normal Quit with exit code 0 also pass.
Configuration plus all nine current saves match the immediate prelaunch baseline,
and the owned window/process closed. No image, crop, scale, opacity, layer,
renderer offset, collision bypass or protocol change was needed. This closes
the bounded first-rescue technical gate, not Andrew's aesthetic acceptance.
There was no fresh reduced native run after the spacing correction; distinguish
the earlier reduced native functional pass from current both-mode route tests.
Game's legacy retry fixtures now
use the actual production roots and a shared tests/Support fixture, preserving
all 66 cross-caller/write-count cases without test-file order dependence. The
combined route/retry run passes 178 tests / 12,913 assertions on PHP 8.5; the
full non-simulation Game suite passes 601 tests / 602,720 assertions. The
combined rescue/retry/equipment selection passes 200 tests / 13,417 assertions
on PHP 8.4, including the exact 13-image hashes and presentation bounds. This is
not a full Game PHP 8.4 suite or completion of the 180,000-battle simulation
baseline. The exact 13-image approval and verified copy are no longer pending
user decisions.

The independent M3 terminal equipment retest now passes purchase/sale/repurchase
and role-aware equipment selection, with its configuration and all nine current
saves unchanged. Unlike the earlier rescue walkthrough, that later run did not
exit cleanly: confirmed Quit restored terminal modes but left the process alive
until interrupted. Engine reproduced cached confirmation input triggering a
post-quit field interaction and corrected the shared shutdown/input continuation
boundary, including both blocking modal implementations. The full Engine checks
above include this fix. Editor's full suites and Console's eight-script suites
and strict Last Legend validation were rerun successfully on PHP 8.4 and 8.5
against it. Game's one subsequent muted 110x35 terminal exit retest at the same
Waymeet interaction also passes: Q then Enter returns from normal Game::run with
exit code 0, without another input or forced interruption. Configuration and all
nine current saves match before/after, and no owned game/renderer process remains.
This closes the observed terminal exit defect. The first rescue's native
functional and corrected-spacing checks also pass as recorded above.
