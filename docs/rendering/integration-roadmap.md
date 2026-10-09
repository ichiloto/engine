# Dual-Presentation Integration

This roadmap tracks reusable terminal and graphical presentation capabilities.
Game-specific production decisions, asset admission and author acceptance belong
in private game documentation. The contracts below describe runtime behaviour
and remaining engineering work, not release status.

| Entry | Scope | Current boundary |
| --- | --- | --- |
| T1 | Retained-cell terminal composition and scrolling | Implemented; see [validation](t1-retained-cells-validation.md) for the bounded fixture and measurement method. |
| G1 | Graphical arena, static battlers and battle UI | Implemented; see [battle contract](graphical-battle-g1.md). Artwork coverage is project-owned. |
| G2 | Animated battlers and combat effects | Complete locally across Traditional/ATB, terminal and reduced motion. Approved art and all eight party-pose calibrations are in normal Game develop. Native checks cover party roles, command/counter sequences, directional/repeated effects, ground rings, fallen poses, revival, healing and actual poison-to-results ordering. Normal/reduced real-scene runs now show victory pages and graphical Game Over; normal entry shows the approved wipe. Native authoring edits, save/reload and conversion Write/Undo/Redo are verified. Full Engine: 5,585 passed / one existing skip / 383,251 assertions. Live Terminal cadence/authoring and the ordinary walking-triggered entry's pixels remain unobserved; automated composition/behavior and ordinary encounter handoffs pass. These are reported testing limitations, not another delivery-blocking loop under Andrew's October 6 instruction. The terminal-styled intermediate defeat message is a G5 coverage follow-up, not a missing Game Over state. Current Game command acceptance passes seven tests / 50,678 assertions; full Game CI retains its separate NPC-reachability failure. The approved current-major compatibility reader stays supported; next-major removal and publishing are not G2 gates. See [runtime and acceptance](../effect-animation.md#local-battle-runtime-october-2026) and the fixed checklist below. Kaelion-Fira story work and Aeryn's separate Victory proposal are outside G2. |
| G3 | Graphical summon presentation | Complete locally under Andrew's October 7 workable-stand-in criterion. D'jin's accepted painted-2D layers and paired timeline are integrated in normal Game develop. Native Traditional/ATB casts pass in normal and reduced motion: actual arena return precedes exactly-once all-enemy damage and one MP charge, with formation/control cleanup. The original independent Terminal lane is preserved; actual-engine Terminal lifecycle and source-preserving Editor checks pass. Native Editor tabs, pinned preview/timeline and all four resize boundaries are verified. A shared native JSON-coordinate precision defect found during acceptance is corrected without weakening canvas bounds. Later animator-quality boot/armour continuity, other summon cinematics and platform qualification are not part of this completed stand-in slice. See the checklist and October 7 closure record below. Work is local and uncommitted, not published or released. |
| G4 | Field actors, objects and environment | Active from October 7; incomplete. The [fixed acceptance checklist](#g4-field-delivery-checklist) covers asset-role coverage, [field-map corrections and GUI authoring](../layered-tilemaps.md#graphical-correction-roadmap), shared field/cinematic previews, and native acceptance of implemented [held walking](#controller-ready-input-and-normalized-movement). |
| G5 | Game-wide interface coverage | Shared adapters and [safe graphical notifications](../notifications.md) are implemented; complete screen coverage and Editor authoring remain outstanding. Actual October 6 defeat runs show a terminal-styled intermediate result message before the graphical Game Over menu; the shared result presentation needs defeat coverage without changing the terminal or gameplay outcome. |
| G6 | Packaging and supported platforms | [Early platform feasibility and release gates](#platform-feasibility-and-release-gates) precede platform-sensitive production commitments; they do not wait for G1-G5 completion. Console's source-development updater is not player distribution. Prebuilt packages, native Windows transport/audio and whole-platform qualification remain unfinished. |

Headless PHP checks and macOS rendering checks do not establish Linux/WSLg or
Windows support. Merging implementation does not create a package release.

## G4 Field Delivery Checklist

Andrew started G4 on October 7: "Your next Goal is to complete G4."
This checklist reconciles the existing field, layered-map, input and GUI plans;
it does not replace them or silently reduce their scope. Game-specific artwork
coverage and admission stay in the existing private Game plan. Later-six summon
production, Kaelion-Fira story work, G5 screen coverage and G6 distribution do
not count toward G4 progress.

Latest October 9 preview lifecycle correction: the Editor now names the actual
graphical host epoch in controls and exchanges; GUI restart/resize/reopen no
longer treat equal grid dimensions as continuity. Retired pictures, READY/ACK
feedback and late view replies cannot reach a replacement session. Both wire
routes forward that identity. Current-map load diagnostics survive into both
preview types and clear on successful transfer; effects without a start map
now show diagnosed undefined terrain rather than refusing to open. This is a
shared lifecycle correction, not a tab-switch workaround or renderer-protocol
relaxation. Delegated PHP checks pass 48 / 784; the final 19 / 333 contract
family includes two additional wire regressions (50 distinct PHP cases across
these focused runs). GUI lifecycle/control/tab/adjacent-art checks pass 25,
with no failures or ignored cases; the normal managed offline release builds.
The first silent native attempt was closed normally before control inspection
when missing wire forwarding was discovered. After that forwarding passed,
the relaunched window could not be inspected because the desktop was locked;
only its owned GUI/host were terminated (143), then verified gone. All 32
synthetic files matched before cleanup. The subsequent October 9 native run
used a new, CPU-validated fixture with music/SFX/voice explicitly disabled and
master volume zero. In its GPUI cinematic tab, changing Initial Presentation
to visible without saving showed the initial player beside marker A. Twelve
paused Steps showed the relocated player beside distant marker Z, with the
camera following on the same map. Skip completed the authored transfer to the
distinct destination ground; Start again returned to the initial map/player,
without retaining the retired destination picture. Ordinary watched completion
also reached the transferred map. The desktop locked before the subsequent
Terminal-tab and missing-map diagnostic inspection: those pixels and physical
held movement remain unverified. Only the identified owned GUI/host were
terminated (143) and verified gone; all 34 fixture files matched before removal.
No normal Game project or settings were edited, and no new build or full suite
was run. Small evidence remains in the secondary g4-native-preview-20261009-2217
capsule; earlier contract receipts remain in g4-preview-lifetime-20261009-2023
and g4-preview-lifetime-20261009-203204. This native evidence supersedes the
earlier unobserved camera/Skip/transfer/restart status, not the remaining failure,
physical-input or production-art acceptance gaps.
G4 remains active; production field-art and other-platform acceptance are open.

October 10 follow-up native inspection verified missing-map diagnostics in both
GPUI and Terminal tabs, including the explicit forbidden-Skip refusal. Switching
to the valid synthetic scene cleared the retired diagnostic. Twelve paused Steps
in Terminal showed the relocated player beside distant marker Z with camera
follow; Skip reached the distinct destination ground. The final restart capture
did not return an image before the app quit, so Terminal restart remains open.
This supersedes the unverified failure-diagnostic status in the checklist below.
The owned GUI/host exited normally, all 34 fixture files matched, and the
disposable project was removed. Music/SFX/voice were explicitly false with
master zero; normal Game files, saves and settings were untouched. Evidence
remains in secondary g4-preview-acceptance-20261010-002656. Physical held input
and production-art acceptance are not established by this inspection.

October 10 cue-kind authoring is implemented through the shared Editor inspector:
Story, Route and Unclassified (clear kind), without automatic classification or
writing omitted defaults. Actual Terminal navigation and GUI session transport
pass save/undo/redo/reload checks; native cue-picker pixels remain unobserved.
The shared map owner now retains its original rewrite basis across saves and
layer undo, restoring removed expressions, references, comments and key positions.
Undo no longer overwrites the saved validation checkpoint, removing the bypass
that allowed incomplete route/rest drafts to save after layer history replay.
Affected Editor families pass 406 tests / 2,636 assertions; two existing
conditional-cue validator cases pass separately / three assertions. The affected
JUnit retains invalid ANSI dataset controls from existing tests and cannot be
parsed as XML; its bytes were not normalized or failures suppressed. Evidence
is in secondary g4-cue-authoring-20261009. No native launch, GUI rebuild, full
suite, Game/settings/save change or publication occurred for this slice.

October 10 publication check: current full Engine Unit passes 7,061 tests /
451,310 assertions, exit 0 under strict warning/risky/deprecation/notice/empty
suite gates. The first readiness run passed every assertion but exited 1 for
obsolete explicit GD destruction in the badge proof; all three calls now release
their object references instead, and the complete strict rerun is green. Original
logs and JUnit bytes, including noninteractive tty diagnostics and invalid ANSI
dataset controls, remain in secondary engine-publication-readiness-20261010-wNmuMD.
This is completed implementation evidence for publication, not G4 completion or
new native/other-platform acceptance.

| Acceptance area | Owner | Current evidence and work remaining |
| --- | --- | --- |
| Current field asset-role coverage | Claude/Game, Art through coordinator | October 9 all nineteen reachable maps have material layers, and all five currently authored semantic save points have their registered artwork and effects. Game has implemented seven interior expansions with north/south wall faces, reviewed coordinate/save migrations, existing facing desk-chair bindings and removal of embedded room/compound labels in both presentations. Approved Sleep/Shared, revision-4 modular roofs, waiting seats, Waymeet fixtures and Lanternrest Recovery damage are now admitted locally under Andrew's named tool/licence exceptions. Source-preserving bindings include the console/computers, localized Recovery damage, current Happyville roof rectangles and five existing low-ground save sigils. Four leader-selected rest stages use shared Inn ownership. Waiting-seat alternatives are palette-ready, not guessed assignments to unidentified fixtures. Strict headless Game presentation and route checks pass 82 / 748,599 and 117 / 80,499; adjacent Engine rest checks pass 148 / 1,257. CPU tile compositions were inspected; native/GPU art acceptance remains open. Eleven disconnected prototypes, unresolved fixture/facade identities and noticeboard alignment remain open; prototype retention is not inferred. World has no currently authored semantic save points; none were invented. The existing private Game ledger remains the coverage authority. Material coverage is not complete map dressing. |
| Graphical maps and safe GUI authoring | Claude/Editor/GUI | Shared Draw tiles, validator-derived coverage, selection copy/cut/paste, piece eyedropper, ordered shadow layers, independent sheet-piece/shape controls and staged-actor art/pose-loop controls are implemented locally. Row/column insertion, map duplication and metadata-derived relocation use reviewed source-preserving transactions; ordinary saves keep stable identity. Dim/Restore is session-only, respects hiding and resets on map/project change. Native checks verified insertion, duplication, relocation cancellation and dim/hide/reset; detailed receipts below name their limits. Route references now use the actual command owner; camera/transfer/effect placement and field-sheet controls are connected. NPC sheet selection preserves index/layer and previews four directions; staged walking sheets use the same current-file crop contract. Runtime and GUI share retained painting, including transparent unstyled blank cells. October 9 native inspection verifies the corrected modal width, cardinal route edit/refusal/save/undo, actual NPC-owned Retrace choices and Inn stage selection/save/undo; the latest direction previews still need native inspection. Unsupported source edits refuse before writing, with undo/reload, external-change protection and Terminal/collision isolation preserved. Remaining representative native checks, production-art acceptance and explicit author decisions are not closed by automated tests or a successful build. |
| Shared field/cinematic previews | Codex/Engine, Claude/Editor/GUI | Shared SceneFrameComposer, isolated ScenePresentationContext and Console capture, styled Terminal rows, pure dialogue pagination and named runtime GPUI grid are implemented. Native SceneSession/ScenePainter reuse retained validation, projection and painting with canonical READY/ACK/rejection messages in both preview tabs. One isolated PreviewField uses the shared interpreter, movement and field camera; presentation upkeep does not execute gameplay/input. Actual host tests cover malformed refusal, detach/reopen/reset, EOF cleanup and optional-list semantics. The shared Skip policy/finalizer supports paused/playing completion, refusal explanations and failed previews without a substitute arena or duplicate interpreter. Graphical host epochs prevent retired pictures, feedback and view replies from reaching equal-grid replacement sessions. Current-map diagnostics persist until a successful transfer; missing effect start maps remain diagnosed. October 8 native evidence covers field/cover/narration completion, restart/step, Terminal/GPUI tabs and effect seek/reconnect/play with a pinned timeline. October 9 GPUI evidence additionally verifies same-map relocation with camera follow, watched and skipped transfer, and restart to the original scene rather than a retired picture. Fixtures remained unchanged and owned processes were closed. Native failure diagnostics, the latest Terminal-tab restart, physical held input and production-art acceptance remain open; a locked-desktop transport run or screenshot timeout does not prove pixels. |
| Held walking and input lifecycle | Codex/Engine/Renderer | Implemented; the immediate empty event-session latch is corrected globally. Focused PHP checks initially passed 242 tests / 1,686 assertions and the full run below includes the correction. Muted native Home checks now inspect introductory dialogue, right/down tap movement, camera scrolling and graphical main-menu opening, cancellation and resumed field movement. The preview closed cleanly with saves/settings unchanged and no audio backend. Simultaneous held keys, partial/full release, corners, focus and cinematic return remain unobserved natively; a tap or headless pass is not native held-input acceptance. |
| Regression and bounded native proof | Coordinator and owners | October 8 full Engine Unit: 6,514 passed / 439,567 assertions, exit 0, no warnings/skips reported. Earlier warnings came from a vacuous ID-keyed state assertion; the old unconditional Enemy construction skip is removed. Tests now check live state identity/duration/HP and real construction with replaceable synthetic art (focused 23 / 173). Two full-run inn failures were reproduced after a real Console hand-back test: the inn fixture now acquires its own headless Console session and restores its parent state, preserving every five-beat/recovery/payment/audio/wake assertion. The corrected shutdown-plus-inn sequence passes 45 / 474; no inn runtime change was needed. Presentation upkeep/player walking/pose regressions: 192 passed / 44,038 assertions. Renderer after blank-cell correction: 238 passed / four existing opt-in ignored; strict Clippy/formatting pass, with existing dependency future-compatibility notices unchanged. GUI release after Skip: 66 passed; focused new Editor controls and adjacent preview families: 36 passed / 533 assertions. Scoped live-Engine static analysis passes. The normal GUI app was rebuilt and its macOS bundle relinked to that release executable on the managed secondary cache. GUI repository-wide formatting and warnings-as-errors Clippy checks do not pass: their diagnostics are in unchanged files outside the new control; no warning was suppressed and unrelated source was not reformatted. Changed-file whitespace checks pass. Editor full suite before the subsequent Skip addition, after preview movement repair: 1,685 passed / 82,037 assertions, exit 0, no errors/failures/skips in JUnit; focused preview families 20 passed / 223 assertions and scoped live-Engine static analysis clean. Earlier mapping/inn/host checks: 13 passed / 125 assertions. Full Game CI: 1,681 passed / two failed / 929,926 assertions, exit 1, with the existing battle-simulations exclusion unchanged. Its garden-route-control wall-top/glyph assertion and town-center orphaned information glyph remain with Game ownership. All 383 affected storage/menu test-family cases pass; source fingerprints, normal logs and all ten live save/settings files stayed unchanged. Test storage uses shared exception-safe ownership/cleanup on the secondary drive, not checkout-rooted save scratch or suppressed diagnostics. Complete remaining representative native acceptance; Windows/Linux/WSLg remain untested. |

Latest October 9 verification after shared field/UI changes: full Engine Unit
passes 7,034 tests / 451,181 assertions, exit 0, with no errors, failures or skips
in JUnit and strict warning/risky/empty-suite gates. The first full run found one
stale array assertion after narration moved to CinematicTextPresentation; the
corrected test verifies content and the same independent overlay identity after
animation cancellation. Generated save fixtures now construct storage inside
their owned disposable project, preserving the production project-relative
save contract. Effect and transport fixtures use the shared directory owner;
transport children stop before capture cleanup. The focused five-family run
passes 179 / 1,023. Full-run source/fixture fingerprints match and secondary
scratch is empty; only proven newly leaked test output was removed. All new
test artifacts are on the secondary drive. Noninteractive tty diagnostics are
retained, not suppressed. The run used a process-only 512 MiB PHP limit; no
normal settings, gameplay assertions or runtime resource limits were changed.

The fresh full Editor Unit run against live Engine passes 1,977 tests / 99,950
assertions, exit 0, with no PHPUnit warnings, risky cases or skips. All 480 Editor
and 2,273 Engine fingerprinted files matched at its post-run snapshot; concurrent
subsequent owner changes are not covered by that unchanged claim. Original JUnit
bytes retain eight invalid ANSI dataset controls, so XML parsing failed; bytes
were not normalized or the suite rerun. Application refusal/fallback diagnostics
are distinct from runner failures. Game's latest scoped interior run reports
183 passed / one existing Town Center noticeboard failure, not a clean full Game
suite. These are macOS/PHP 8.5.11 checks, not new native pixels or other-platform
acceptance. Evidence lives in secondary capsules g4-engine-owner-fixes-20261009
and editor-g4-unit-20261009-142535. The desktop was locked at that inspection;
no native window was launched for those suite runs. G4 remains active.

Later October 9 muted native GUI inspection used the existing 33-file synthetic
secondary-drive project and verified normal release 1bc2a69b. The shared route
dialog now has usable width and wrapped guidance. Choosing an origin leaves the
NPC's saved position unchanged; a diagonal endpoint refuses, a cardinal edit
persists only its intended count, and native Undo/Save restores original bytes.
The NPC Retrace picker lists its own remembered route, not the unrelated scene's
routes. Inn's stage picker selects and saves an actual alternative stage; native
Undo/Save again restores original bytes. All 33 baseline files match afterward,
stderr is empty and the owned GUI/host session exits normally. Music/SFX/voice
were explicitly false with master zero before launch. No normal Game files,
saves or settings were changed. This bounded inspection does not establish
face-only/nullable-waypoint authoring, native reload/stale-source refusal,
direction-sheet pixels, physical held movement or production field artwork.
Evidence is in the existing g4-native-acceptance-20261008-234126 capsule;
Game's applied-art receipts are in g4-local-import-20261009. G4 is incomplete.

The next October 9 delegated held-walking runner is prepared in the existing
secondary g4-native-20261008 capsule. Parent review and a fresh no-launch
preflight pass against normal Game/live Engine and the installed renderer.
Music/SFX/voice are false, master volume is zero and autosave is disabled;
save writes refuse and the bounded launch restricts writes to its own evidence.
No native window was launched because inspection reports the desktop locked.
Physical held/release, focus and cinematic-return acceptance remain open; the
runner is preparation, not a movement pass. Art recovered the already-presented
Route Controller proposal without regeneration; appearance acceptance and the
remaining directional views are still pending, not covered by the named imports.

October 9 approved battle-status artwork is now integrated in normal Game
develop through the existing shared menu/battle icon catalogue. All 22 approved
appearances are installed; fourteen stat-polarity variants plus actual Poison
and Stun states are active. Six other appearances remain catalogue-only because
their gameplay states are not defined; no mechanics or name-derived aliases
were invented. Shared 32px badges retain signed stage footers, stable identity,
wrapping and immediate cure/removal, with Terminal hints unchanged. Parent
inspected actual-condition headless before/after-cure canvases; these are not
native/GPU proof. Combined current battle, status, command and main-menu checks
pass 215 tests / 44,331 assertions, exit 0 under strict warning/risky/deprecation/
notice/empty-suite gates. The first combined run exposed six stale menu-test
label assumptions after Config correctly became the configured Options term.
Test navigation now uses command identity and title assertions use current
Vocabulary, preserving existing behavior and authored wording. All new evidence
and retained application diagnostics are on the secondary drive. This bounded
integration does not close G4's remaining native and field-coverage acceptance.

Historical October 8 combined authoring verification: the full Editor Unit suite
against live Engine and the current Last Legend checkout passes 1,910 tests /
84,085 assertions, exit 0, with no errors, failures, warnings or skips. Source
preservation receipts match before/after for 1,483 Editor/Engine source and test
files plus 3,383 Game files, including saves/settings. This post-correction run
includes the route, Inn and source-freshness lanes below. GUI tests pass 108
with no failures or ignored cases, and the managed offline release build passes
on the secondary cache. Changed route-source formatting and whitespace checks
pass; the previously reported unrelated repository-wide formatting/Clippy
diagnostics are not claimed resolved. These checks do not establish native
pixels or Windows/Linux/WSLg acceptance. The raw Editor JUnit retains illegal
controls in existing ANSI dataset labels; its original bytes remain intact,
and the successful process exit and text log establish the execution result.

October 9 interactive Terminal acceptance used the actual Editor in an owned
140-by-44 PTY, with the existing runtime-valid Harbour Lanterns fixture and
explicit music/SFX/voice mute plus zero master volume. The command tree and
Terminal stage were present simultaneously. Step and pause held at 0.1 seconds;
the watched run completed at 4.5 seconds through its finalizer, authored Skip
completed the finalizer immediately, and Stop restored player input. The
watched/skipped comparison reported only the expected checkpoint difference.
Closing Cutscenes returned to the map editor, and normal Quit exited 0. All 29
fixture files remained unchanged; the temporary secondary-drive project and
helper were removed. An earlier disposable authoring fixture was unsuitable
for playback because its legacy Sleep event omitted required wake coordinates:
the preview exposed the load failure and Jump selected the failed route. Its
33 files remained unchanged; it was not counted as successful playback or
silently repaired. Repeated Space after completion intentionally restarts, not
a clock defect. This closes these live Terminal controls, not GPUI pixels,
physical held-key input, production-art or other-platform acceptance.

Later October 8 native authoring inspection now supersedes the uninspected
Dim/Restore, copy and move controls above. The normal rebuilt GUI app used one
explicitly muted synthetic project on the secondary drive. Floor dimming was
visibly distinct from its decor layer; hiding overrode dimming, switching maps
reset both view-only choices, and no source changed through those controls.
Copy produced a separate map. A changed, unsaved Name left its path stable;
the explicit move dialog showed the reviewed destination and reference/undo
warnings. Cancel preserved the dirty copy; confirmation saved and selected the
new path with its layers and tilemaps intact. All original project file hashes
remained unchanged; only the seven copied/moved files were added. The owned GUI
and host exited normally, and the disposable project and helper were removed.
This establishes these controls on macOS, not production-art, shadow-band,
blank-cell layering, remaining authoring views or other-platform acceptance.

The later walking audit proved a shared lifecycle defect: clearing held input
left a queued tap and step clock alive in PlayerWalk. The input owner now
retains its reset press-order cutoff, and PlayerWalk removes stale queued intent
at that boundary while accepting newer presses from the same update. Fifteen
new synthetic regression cases cover reset/failure/restart/shutdown, immediate
menu return, yielded dialogue/cinematic return and blocked corners with
exactly-once committed-cell effects. Focused input/walking: 92 passed / 504
assertions. Full Engine Unit after this correction: 6,529 passed / 439,664
assertions, exit 0, with noninteractive `/dev/tty` and `stty` diagnostics retained,
not suppressed. This later run supersedes the 6,514-test total above. Terminal
event-only stepping and menu edges remain unchanged. Physical simultaneous
held-key/release acceptance is still open; the available native inspection API
reports atomic key presses rather than a controllable physical hold interval.

The final scoped input-source static analysis also passes. A subsequent muted
native Skip attempt used a separate synthetic project, but the desktop was
locked before inspection, so none of its controls or pixels are claimed as
verified. Only the owned host/window were terminated; both were checked gone,
all fixture source hashes were unchanged, and its project/helper were removed.
The earlier successful native map-control checks above remain valid.

The full Engine run exposed unowned temporary fixture roots in the event and
script-registry test families. All thirteen allocations now use the existing
shared test-directory owner; redundant local deletion was removed, preserving
assertions and service restoration. Both the worker and coordinator reruns pass
138 tests / 782 assertions, with the isolated secondary-drive fixture directory
empty before and after. Twenty-two verified dangling roots from these families
were removed; protected caches, locks, logs, receipts and unrelated files were
left intact. The 6,529-test full run precedes this fixture-only cleanup; it was
not repeated or presented as a post-cleanup full-suite result. An initial
coordinator rerun without secondary-drive write access failed at fixture setup;
the corrected-access rerun is the passing result above, not a hidden test fix.

Game has reproduced both remaining integration failures. Its wall-top assertion
incorrectly treats a mixed floor/wall tile layer as wall-only content; Game is
correcting that authored-content premise while preserving source-derived tile,
Terminal ownership, geometry and collision coverage. The town-center invariant
found a real noticeboard interaction displaced four cells from its visible
marker. The coordinator has asked Andrew whether to align the interaction and
associated artwork to the visible board without reverting his layout. Neither
the Game correction nor the decision is reported complete here. Game's current
asset coverage consolidation belongs in its existing private presentation
roadmap, outside its preserved September archive, not a new competing ledger.

The subsequent Game handback completes the test correction and that existing
private coverage section. All seven authored tilemaps pass the source-derived
presentation invariant. Focused Game: 213 passed / one failed / 152,750
assertions; full existing Game CI: 1,684 passed / one failed / 935,125
assertions, exit 1, retaining the battle-simulations exclusion and strict
warning/risky/empty-suite flags. The sole remaining failure is the pending
noticeboard placement, not a clean full-CI result. No map or runtime binding was
changed by this lane.

The October 8 inventory distinguishes nineteen maps with reachable arrivals
from eleven disconnected glyph-only prototypes. Seven maps have tile layers;
inn front still has forty unpainted grass cells. Twelve playable environments
remain untiled. Of 129 visible NPC/object definitions, 128 have field bindings;
Route Controller lacks an established field identity/art. The coordinator has
asked the existing Art producer for that proposed design, not approved or
integrated it. Existing admitted materials still need placement/piece mappings;
At that inventory checkpoint Sleep and the new environment proposals awaited
approval/admission, and the rite's field-healing aura was Terminal-only. The
later approval and healing integration below supersede those statuses. Detailed
project roles and map lists stay in the current private coverage section;
available art and zero glyph fallback are not treated as completed role or
native acceptance.

October 9 Game has bound the currently admitted environment catalogue through
223 new reusable visual pieces in the four existing tilesets. Existing piece
identities are reused where present; tile-only stamps preserve independently
authored gameplay footprints rather than inventing blockers or new Terminal
glyphs. Focused Game checks pass 64 tests / 279,784 assertions and syntax/changed
source whitespace checks pass. The source handback preserves all 56 prior
piece definitions and top-level metadata; its one-off audit confirms all 304
snapshotted map/collision and tileset-image files are unchanged. These checks
protect usable current sheets, legal crops and visible tile/frame content,
not frozen artwork hashes or layouts. The palette is available to shared
authoring; this is not completed map dressing or native acceptance. Rejected
houses, unaccepted revision-3 roofs and new Sleep/Shared imports remain excluded.

The subsequent October 9 Game handback adds thirty literal material-layer files
with 53,417 placements across the twelve previously untiled reachable maps and
the forty inn-front grass cells. All nineteen reachable maps now have material
layers; current reachability reports no problems on those nineteen. Physical
glyphs, events, NPCs, collision and save geometry are preserved. Unresolved
fixtures, labels and facades remain explicit; this is material coverage, not
complete environment dressing or native acceptance. The initial focused
five-file run failed on three existing Shop decorations referencing wholly
transparent crops, then exhausted the default 128 MiB in real-movement fixtures
before a final summary. Game reconciled those three picture bindings with the
established drawable portrait/landscape palette cells, preserving the clock,
layout and collision; its six focused checks pass 487,530 assertions. The
separate lifetime diagnosis found deferred cycles in discarded test-owned
scene/player/camera graphs, not a runtime map parser defect. Explicit fixture
release retires only its owned triggers and references; all original movement
cases remain, with a new immediate-release regression. The identical five-file
strict rerun now passes 88 tests / 690,588 assertions, exit 0, at 90 MiB PHP peak
under the unchanged 128 MiB limit. No Engine/runtime memory default, assertion
or validation is weakened. These results supersede the failed focused receipt,
not the earlier full-CI snapshot or native acceptance. Eleven disconnected
prototypes, noticeboard alignment and the pending asset/identity decisions remain.

The next October 9 Game slice binds the three missing save-point visuals through
their existing tileset pieces and `save-point-energy` effect. All five current
semantic save-point cells now have coverage; the new invariant resolves actual
map layers and collision roles rather than pinning glyph spelling or positions.
Front Court's props layer explicitly names its existing fixtures owner because
its palette has multiple layer writers. The focused Game run passes 24 tests /
505,564 assertions, exit 0, including current sheets/crops, effect playback,
semantic coverage and neighboring layer-aware collision. No physical grids,
events, NPCs, saves or normal settings changed; full CI and native pixels were
not rerun for this slice.

The October 9 terrain-seam correction establishes two independent content
requirements: concave source quarters must continue the terrain boundary rather
than contain miniature isolated tiles, and transparent surface boundaries need
explicit lower terrain. Art corrected ten corner sheets across 107 material/frame
variants and prepared one matching Interior meadow base without changing its
pixels. Game admitted these resources, backed all eight affected maps and added
reusable base/surface pieces to three palettes. Intentional voids and physical
glyphs, events, collision and saves are unchanged. The shared Engine corner
selection was already correct; only its misleading quarter-order comment changed.
Synthetic corner/layer regressions pass 321 tests / 2,248 assertions, including
all 256 neighbour combinations. Game terrain/binding checks pass six tests /
506,776 assertions across current maps and water phases; Editor kept-layer checks
pass 14 / 49, and fourteen current Game palette combinations pass stamping,
undo/redo, save/reopen and erasure in disposable fixtures. The actual overworld
production-wire crop has no isolated corner boxes, transparent holes or dark gaps.
A silent renderer-only GPUI replay painted and acknowledged that world and exited
normally, but native screen inspection timed out: this is not on-screen visual
acceptance or other-platform qualification. A follow-up actual-map crop exposed
indoor flooring over the inn's authored outdoor meadow; Game removed only that
graphical overlap, preserving the intervening wood strip and physical geometry.
The final combined terrain, binding and map-loading/fallback run passes 217 tests /
912,287 assertions with no skips. Fallback projection now exercises all thirty
current maps without requiring production content to remain glyph-only; per-cell
ownership, clipping, terminal geometry and collision checks remain. The temporary
capture helper is removed and small verification evidence stays on the secondary
drive. The existing Happyville town macro
still lacks its matching graphical resource; it was not stretched or hidden by
terrain fill. Game-specific admission receipts remain in its existing terrain
document, not permanent runtime hashes. See the [source and backing contract](../graphical-field.md).

October 9 Lanternrest's damage review exposed a Game binding error as well as
missing context-specific art: the temporary eastern barrier was placed on
Recovery's permanent wall rather than the existing outer exit. Game corrected
only the three barrier positions locally and added conditional forward save
compatibility for older positions newly occupied by the barrier. Already-cleared
saves stay in place; static wall geometry and transfer conditions are unchanged.
The scoped Game run passes 180 tests, including closure, rescue/retry and save
preservation checks; this is not native visual acceptance or full Game CI.
Static wall geometry and transfer conditions remain independent of
artwork; rendering must not disguise a permanent wall as a cleared passage.
Art has prepared eight reusable cream-stone damaged caps, masonry rubble, timber
debris, cracks and exposed-masonry pieces matching the occupied wayhouse. Andrew
approved revision 3 and subsequently authorized its bounded local import with
the named tool/licence-metadata exceptions. Integration is assigned to Game,
not yet completed runtime dressing. Their
blocked/cleared proofs must follow the actual conditional closure footprint and
leave no visual obstruction in a reopened passage. Revision-3 context proofs
use the corrected eastern doorway and confine structural damage to Recovery,
matching the authored evidence rather than making the whole wayhouse a ruin.
Game's existing terrain
document owns the resource bindings and acceptance record; no wall-glyph remap
or Lanternrest-specific Engine fallback is required.

Andrew's October 9 interior correction supersedes the earlier material-only
boundary for affected Game interiors. Game owns real north/south wall volume
and adequate interior space using the current author-edited interiors as the
reference, not floor textures stretched across missing wall faces. Structural
growth must use the existing project-wide line-insertion inventory and reviewed
source-preserving transaction, carrying every affected layer, incoming transfer,
spawn, NPC/wander area, event, cinematic route/camera and forward save shift.
Unsupported/computed references require reconciliation, not flattened PHP or
guessed coordinates. Already-corrected interiors and outdoor maps are not
expanded blindly; actual saves/settings remain untouched.

The same correction removes embedded room/compound lettering from both Terminal
and graphical map drawings. Game must preserve navigation through approachable
information posts and natural interaction text; recurring building identifiers
use plaque artwork. Identified monitor consoles, facing computers and desk chairs
need actual role/footprint bindings, not unrelated palette substitutions or a
renderer glyph remap. Art reuses suitable admitted wall/sign resources and
prepares only genuinely missing designs for appearance review. Game's seven
interior expansions and embedded-label removal are now implemented locally with
reviewed forward save compatibility; existing desk chairs are bound, while new
approved fixture artwork integration is still in progress. A fresh shared Editor insertion and
session run passes 30 tests / 346 assertions, including source preservation,
coordinate inventory, save shifts and undo; the initial sandbox attempt could
not create secondary-drive fixtures and provided no behavioural evidence.
The permitted rerun used disposable fixtures/cache on that drive. These checks
do not establish final Game map dressing or native visual acceptance.

On October 8 Andrew approved the current consolidated G4 art review, with this
explicit exception and requirement: "All G4 art review is approved except the
angled roofing pending clarification. What I want are reusable tiles from which
different  style houses can be constructed. Consider the attached image".
Art and Game received the exact instruction and supplied modular-house reference.
Sleep and the six current environment families can proceed through the existing
admission and binding workflow; approval does not itself establish runtime
integration or approve rejected originals preserved as history. Angled roofing
remains unapproved. Its deliverable is interchangeable roof, wall, door,
window/shutter and foundation pieces with compatible grid/perspective/joins,
repeatable spans and necessary ends/corners/junctions. Different-size house
assemblies and material swaps must demonstrate reusability; a completed building
sprite is insufficient. Graphical overhang must not change Terminal geometry or
collision. Game owns content bindings; shared tileset authoring remains the
construction mechanism, not a new house-specific gameplay model.

Andrew subsequently authorized Art's bounded local facade preparation and
assembly proofs on the secondary drive, excluding Game writes, publishing,
paid generation and final roof acceptance. The resulting twelve house
proposals were rejected in Art's chat: their roof perspective and facade
layout did not meet the modular brief. They are non-runtime history, not
accepted resources. Art's October 9 revision 4 provides the fourteen exact
structural roof roles Andrew annotated, in terracotta, slate blue, brown and
gold, with small, wide and tall assemblies. Andrew approved these 56 reusable
tiles and separately confirmed "Roof front continuity is approved". His
"The waiting seats are approved." accepts the sixteen prepared waiting-seat
assets, not unspecified desk-chair designs. The dated appearance receipts are
current authority; older pending proposal labels are historical. Admission and
actual scene placement still belong to Game. The earlier non-roof G4 approval
is unchanged, and rejected kit proofs remain excluded.

October 8 GUI row/column insertion now uses the existing LineInsertionPlanner
and reviewed SourceSet transaction. Its review lists dimensions, all changed
paths, hand-edit warnings and save shifts; confirmation checks the exact map
revision and source fingerprint. Unsaved work, changed choices and external
source edits are refused. Undo/redo reload all affected map/database documents;
absolute selection and pointer state clear while relative copied content and
tile stamps remain usable. Synthetic tests cover both axes, incoming transfers,
NPC/wander positions, scripts, cinematics, starting positions, save migrations,
source preservation, cancellation, clipboard preservation and safe refusal.
Focused Editor checks pass 57 tests / 587 assertions; the complete Editor suite
passes 1,734 / 82,611 with no failures, warnings or skips. Scoped static analysis
passes. GUI tests pass 91, including fifteen insertion cases, and its managed
offline release build passes. After native tool access recovered, a bounded
retry through the registered Ichiloto bundle verified row and column review,
apply, undo and redo; cancel and Escape preserved every original file hash,
and changing the insertion required fresh review. All twelve affected paths,
notes and manual-edit warnings were available. The dedicated long-path row
remained clipped, although its wrapped write description exposed the full path;
that presentation correction is now implemented in source and awaits native
inspection of the rebuilt view. External-change refusal,
database refresh and clipboard behavior were not exercised natively. The muted
owned app and host exited; final undo restored the original hashes and the
fixture/helper were removed. Only small receipts remain on the secondary
drive. The full Editor count above predates the following new authoring work;
it is not a combined post-integration suite result.

Delegated GUI piece authoring now provides sheet-region selection, new pieces
and tile layers, assignment to all or one connected shape, and all sixteen
cardinal-neighbour previews through shared shaping/autotile composition.
Explicit glyph footprints remain authoritative: mismatched selections are
refused rather than changing collision to fit art. Existing database commands
retain source-preserving save, undo and redo. Focused PHP checks pass 59 tests /
523 assertions; seven focused Rust tests, scoped static checks and the managed
release build pass. A bounded October 8 muted native fixture verified sheet
drag-selection and assignment, the sixteen-shape gallery, Undo/Redo, new piece
and tile-layer creation, footprint mismatch refusal, shape-specific assignment
and saved tile identities without glyph/collision edits. The owned app/host and
fixture were removed. Successful project reopen, Shift-click, tile-zero and
every individual shape assignment were not observed; broader native acceptance
and other platforms remain open.

Delegated staged-actor authoring exposes graphical assets, walking sheets,
pose frames/timing/rest frames, crop, visual size/layer and player/NPC binding
through existing GUI record fields and asset selectors. Switching image forms
removes incompatible fields atomically; unsupported source expressions are
refused before history mutation. Terminal authoring keeps its existing controls
and preserves graphical data. Related checks pass 129 tests / 1,017 assertions
with one optional production-content skip. Native acceptance remains open.

Game's admitted healing image is now bound as a graphical variant while
preserving the Terminal timeline and total duration. Shared normalized image
pivots propagate through field definitions, projection, optional transport and
renderer placement; omitted pivots preserve bottom-centre. Focused Engine
checks pass 251 tests / 1,780 assertions, with renderer geometry/transport,
static analysis and formatting checks passing. Game's combined binding,
field-timeline, presentation and rite tests pass 46 / 7,807, including all
frames, reduced-motion rest, current crop validity, ground-point propagation,
duration and cleanup. The shared renderer release is now built and installed
through the verified updater, with matching built/installed hashes and READY
advertising the optional sprite-pivot capability. Renderer aggregate checks pass
244 tests with four existing opt-in cases ignored. The subsequent full Engine
Unit run passes 6,538 tests / 440,122 assertions, exit 0; noninteractive terminal
diagnostics remain visible. Editor exposes field bottom-centre and battle/stage
centre defaults through the existing preview context without writing omitted
source values. Related PHP checks pass 27 / 401 and ten Rust timeline checks
pass. A later muted native field-healing fixture exercised the actual
GameScene/FieldEffectManager: normal playback traversed all eight frames in
0.72 seconds and six camera origins; reduced motion held its rest frame and
visibly grounded the aura beneath Kaelion with clean transparency/crop.
Both runs exited normally, cleaned their owned effect and preserved saves and
settings. Normal-motion screenshots did not capture every short-lived frame,
so per-frame pixels and full campaign/menu usage remain unclaimed. New
authoring controls still require combined-build native acceptance.

The shared inn-stage selection now explicitly chooses either a leader shot or
an exact party descriptor. Party matching uses actual live member identities,
including reserves, independently of order; there is no implicit fallback
from a party shot to a leader shot. Optional missing/ambiguous graphics may
bind later on the same rest clock without changing payment, recovery or
cleanup ownership. Focused Engine checks pass 233 / 1,442. The subsequent
post-window-contract full Unit suite passes 6,609 / 440,739, exit 0, on macOS
PHP 8.5.11; this does not claim native authoring or other-platform acceptance.
Editor descriptor controls, live references and source freshness now pass the
combined authoring verification above; their native workflows remain open.
Parsing a descriptor alone is not authoring completion.

Runtime and MapWorld now share the complete glyph/tile/shadow layer painter,
not merely tile composition. The retained contract explicitly leaves a blank
glyph with a null background transparent. An actual Engine-composed synthetic
fixture checks shared glyph selection, clipped projection and tile pixels
across cameras/scales; renderer aggregate tests pass 245, with four existing
opt-in cases ignored, and strict library Clippy passes. Existing dependency
future-compatibility notices remain visible. The October 9 GUI migration now
mounts this complete retained content in both the main map canvas and placement
preview, using current resolved bounds instead of previous-frame or hardcoded
camera sizes. The separate tile passes and duplicate runtime glyph painter/row
storage are removed. A shared per-cell exclusion leaves authored replacement
previews, including blanks, to the GUI without changing prepared world data,
tile covering, shadows or overhang. All 126 GUI checks pass; renderer library
checks pass 249 with four existing opt-in cases ignored, and strict library
Clippy passes. An initial invalid synthetic overhang tile was corrected to the
existing size contract; validation was not weakened. Existing dependency
future-compatibility notices remain. These 126 GUI checks precede the later
shared modal-width correction below; that correction has its own focused test.
At this checkpoint no native pixels or resolution of the previously blank
placement modal were claimed. The later trace inspection below observed the
main map but measured a zero-width placement preview.
The normal GUI release rebuilt successfully after these changes; its existing
macOS bundle still points at that managed release executable, SHA-256
`66fb7a1e95b105c6b1842e2c54e306a16284a97f661fe9e26ab3b6cca44ca38b`.
No alternate runtime copy was created. The completed serialized build leaves
11.06 GiB of managed Rust cache on the secondary drive, below its 15 GiB cap.
The shared stamped-piece `keeps` contract preserves independent underlays
such as walls beneath windows; focused Engine piece checks pass 78 / 616 and
scoped PHPStan level 3 passes. The October 9 Editor consumer integration now
preserves kept cells during role painting, movement and erasure, includes kept
cells in role identity, and validates cell-scoped underlays without exempting
unrelated stale tiles. Focused Editor checks pass 174 tests / 872 assertions;
scoped PHPStan and syntax/whitespace checks pass. Both existing Game window
bindings now request `keeps: walls`; the subsequent focused Game check passes
4 tests / 166,322 assertions. These checks do not claim native window-placement
acceptance or a full-suite rerun. Existing map layouts and collision are unchanged.

Shared route authoring now edits the Engine's steps/waypoints/retrace union
without persisting the GUI's mode selector, fabricating omitted axes or deriving
identity from current selection. Recorded routes now belong to every actual
event execution session: ordinary NPC scripts, inline map events, standalone
common events and cinematics. Referenced common events share their caller's
session; unrelated invocations do not share history. No-session runners and
staged actors remain refused. Successful inverse steps, exact subject/map/stage
generation/endpoint binding, collision, one-use consumption and cleanup remain
the existing runtime authority. Editor listing and applying route references
use the same actual command owner and reachable common events, excluding
sibling NPCs, dialogue variants and unrelated scripts. Focused October 9 checks
pass 211 Engine tests / 1,366 assertions and 161 Editor tests / 1,225 assertions;
scoped PHPStan and syntax/whitespace checks pass. These results postdate the
full-suite totals above, which were not rerun or relabelled. October 9 the GUI
picker now transports the exact row frame/marker/path and inspected command
owner, rather than the selected map or open command frame. Other reference
pickers retain their existing context. All 126 current GUI tests pass, including six
owner-transport cases; focused real PHP host and route checks pass 27 tests /
387 assertions with synthetic projects unchanged. The test command reports
the existing deprecated PHPUnit no-cache option; no warning is suppressed.
The combined release build above is complete; native route selection remains
pending.
The GUI edits authored waypoint axes
and shows targets without claiming collision-aware paths. Changed incomplete
routes remain editable drafts but refuse actual map, record and paired-cinematic
save before backup/staging; untouched legacy paths survive unrelated edits.
Inn descriptors and rest/Sleep controls use live actor and scene references
through those same source-preserving owners. Source-freshness checks now live
at the loaded document/source-set boundary, before authoring and disk writes,
not only in map placement. Save/undo/reload, pending-state preservation,
expression refusal, missing/new-file recovery and unrelated Terminal edits are
covered by synthetic tests and the full current-Game run above. No graphical
workflow was added to the TUI. Native inspection of these combined controls is
still required; no Game story, layout or artwork was changed by these lanes.

October 9 bounded native checks in a muted synthetic project verified NPC Inn
actor selection and Undo, stage selection and save, refusal of a missing stage
reference without changing source, then stage Undo/save back to its original
value. In the earlier release, the placement dialog loaded its map but remained
blank: a definite canvas-height correction alone did not resolve it. GUI
tests pass 112 / zero failures or ignored cases, and the managed offline
release build passes; neither proves the missing pixels. An opt-in trace now
reports the actual loaded/prepaint/paint bounds and inherited clipping masks.
The subsequent trace launch was stopped before dialog inspection because the
Mac locked; its empty trace is not diagnostic evidence. Only the owned GUI
and child host were terminated and verified gone. The subsequent shared-painter
release was inspected when the desktop became available: the main map rendered,
but the placement preview's loaded world had resolved bounds `[353,305,0,370]`
and a matching zero-width clipping mask. This identifies a shared dialog-layout
width failure, not a missing map or renderer fallback. The fixed-width dialog's
padded body now supplies a full-width flex column, retaining in-flow height and
the shared painter. Its focused style-contract regression passes one test;
the normal release above rebuilt successfully. Corrected native pixels remain
unverified because the desktop locked again. Only the exact owned GUI and child
host were terminated and verified gone; no test window remains.
The 232 KB disposable fixture is retained on the secondary drive for the same
pending native check, not a second Game runtime. After Undo/save, all 33 fixture
files match their original hashes; none is missing. Save/reopen, party-rest and
stale-source native workflows remain open; focused source tests cover those
boundaries. No Windows/Linux/WSLg acceptance is claimed.

Physical controllers, analog input, diagonal movement and enhanced terminal key
reporting remain the explicitly planned future input work, not implicit new
requirements of this delivery. Four-directional walking, semantic actions and
terminal event-only behavior must remain intact. Build and scratch work uses the
secondary drive. Completion requires the checklist above, not a percentage based
on elapsed time or on implementation which predates this goal.

October 9 NPC and staged walking-sheet authoring now share the Engine sheet
layout and current-file dimension contract. NPC index/layer controls preserve
source, refuse unsupported expressions and round-trip through undo/save/reopen;
clearing the sheet removes only its dependent graphical controls. Four standing
direction crops also appear for actual inline/cast/envelope staged actor owners.
Missing, undecodable or invalid crops show a diagnostic, not an entire-sheet
fallback. GUI thumbnails use the existing `CanvasPainter`, preserving crop
aspect and runtime sampling guards rather than exposing guarded raw images.
Visible rows own their painters; stale completions and retired rows are cleared.
Terminal glyphs, NPC positions, collision and gameplay identity are unchanged.
Focused live-Engine Editor tests pass 139 / 838 assertions and scoped PHPStan
level 3 is clean. Current GUI tests pass 133; eight focused Renderer canvas
checks pass, including source-edge sampling. These are focused PHP/Renderer
receipts, not new full-suite totals. The normal GUI release built successfully
on the secondary cache, SHA-256
`1bc2a69b0938311e733b85b35a47a0d60e7d3a8d68b781c121e82b648af378fd`;
the existing macOS app bundle points to it. Native inspection still reports the
Mac locked, so no new native launch or pixel/held-input acceptance is claimed.
No Windows/Linux/WSLg run was performed. All source changes remain local and
uncommitted; no ref or publication action occurred.

Later October 9, after Andrew confirmed the desktop unlocked, the same release
was inspected in one explicitly muted synthetic project. Native observation
verified a visible camera pan, timed narration, forbidden-Skip refusal and
authored Skip from paused and running playback. The authored finalizer transferred
to a visibly distinct destination, reattached the camera and cleared presentation;
the running Skip completed at 0.8 seconds rather than its natural 24.3-second end.
Native restart acceptance remains open: Start again reports 0.0 seconds paused
while GPUI retains the completed destination picture. Terminal shows the correct
initial state, and switching tabs reconnects GPUI; that is diagnostic evidence,
not a fix or accepted workflow. The Editor owner received this reproduction and
the shared cinematic/effect-field map-load diagnostic visibility gap. A real
Sleep-trigger decode failure was fixed at the shared `InnOffer` boundary:
decoded record objects are normalized without loosening party/actor list
validation or replacing typed selections. Focused checks pass 116 tests / 1,145
assertions; a fresh PHP preview independently loads valid harbour terrain without
cinematic failure. This does not claim native rest visuals after the fix.
Two isolated walking runners exited cleanly without physical input or arrivals;
held/release/focus acceptance remains open, not inferred from transport counts.
All owned windows and hosts exited cleanly, normal saves/settings stayed untouched,
and the disposable preview project was removed. Small receipts remain on the
secondary drive at `g4-native-acceptance-20261008-234126/native-controls-20261009.json`.
No new build, full-suite rerun, publication or other-platform acceptance occurred.

The later licensing-assessment drop report exposed a shared same-map placement
gap: `move_player` changed the player position without updating the camera or
recomposing the field. `GameScene::relocatePlayer()` now owns that handoff for
script placement and inn wake-ups. It cancels held walking and obsolete
interpolation/arrival work, synchronizes the viewport, snaps only an attached
camera and uses the canonical field compositor; detached cinematic framing,
map-transfer geometry ordering and finalizer transform commits are preserved.
Focused macOS PHP checks pass 354 tests / 2,861 assertions across eight files,
with syntax and scoped whitespace checks clean. The later bounded native GPUI
cinematic check above also observes same-map scripted relocation with attached
camera, authored transfer on Skip and same-grid restart. This is synthetic
shared-path acceptance, not a native replay of Andrew's exact assessment;
no subsequent full suite, new build or other-platform validation is claimed.
Tests were write-sandboxed to the secondary drive; normal settings and unrelated
Engine work stayed unchanged. Two normal autosaves changed concurrently outside
that sandbox and were neither written nor reverted by this lane. The bounded
receipt is `g4-player-relocation-20261009-180615/receipt.json` on the secondary
test cache. This fixes the reported shared behavior; it does not complete G4's
remaining native walking or Editor acceptance.

The October 8 Engine JUnit artifact contains XML-illegal control characters in
styled dataset labels. Its original bytes are preserved; the clean process exit,
stdout totals and report summary header establish the results above, not a claim
that the raw artifact passed standard XML-parser validation. No test behavior or
third-party report writer was changed to hide this report-format limitation.

October 6 urgent shared-composition corrections interrupt G3, not extend its
scope: canvas text batching now occurs after live overlay composition to prevent
the reported shop-exit 64-layer overflow; battle entry atomically replaces old
field glyph ownership; stacked dialogue/reward modals use newest-first input
ownership and bottom-to-top graphical composition without duplicating menu-owned
modals. The approved battle treatment was present but incorrectly gated by the
ordinary doorway setting. Independent battle enablement is now exposed and Game
enables it without changing doorway policy. These corrections have headless
retained/runtime and entry coverage, not a replay of Andrew's exact native shop
exit or ordinary encounter pixels. Untiled maps intentionally retain readable
field glyphs until authored tiling exists; clearing stale battle glyphs does not
remove that fallback.
The subsequent full Engine Unit run passes 5,970 tests / 391,224 assertions
with one existing skip at the CI-configured 1G memory ceiling. Scoped source
static analysis passes using the secondary-drive cache. Independent affected
Game title/notification checks pass 42 tests / 2,804 assertions. Full Game
verification retains separate live-map reachability and old home-layout
assertion failures; its full suite is not reported clean.

The following Editor observations are historical checks at the named revisions;
their locked-desktop and unverified-resize status is superseded by the October 7
closure record below, not a current blocker.

G3 Terminal-tab readability now has a shared Engine projection rather than a
GUI-specific ANSI parser: Console snapshots, incremental rows and isolated
previews use `TerminalPresentationComposer`. Battle preview frames expose a
text-only `terminalCanvas` alongside unchanged formatted `terminalLines`, so
coloured per-cell rows retain their logical width without raw escape bytes
shrinking the preview. Focused conversion/preview/Console checks pass 77 tests /
679 assertions, scoped source analysis is clean, and the full Unit run above
includes them. Claude's local Editor/GUI changes now paint battle,
effect and cinematic Terminal tabs from `terminalCanvas` through the shared
preview painter; raw ANSI-line font fitting is removed. His focused GUI checks
pass 52 tests, including actual Engine-produced JSON, and Editor preview checks
pass 13 tests. Editor is integrated on normal local develop at 58c47ed; the
coordinator's full Editor suite against current workspace Engine/Game passes
1,626 tests / 79,072 assertions, with temporary projects on the secondary drive.
Andrew subsequently instructed "Merge the UI branch into develop"; GUI develop
is now 07865d5. The first inspected macOS bundle was built at ad0eb32, before
the latest side-panel hit-area correction. The coordinator used a disposable muted
synthetic project at 1440x932. Initial opening, renderer-tab changes and Undo
now keep the tab, timeline and actual picture aligned. The selected-track
inspector's fields are readable after removing repeated row actions, and the
reduced-motion control stays visible beside the long contact readout. Preview
and timeline remain simultaneously visible; their horizontal splitter works
and persists across restart. Three attempts at the inspector's vertical edge
did not resize it, so shared side-panel hit testing/routing remains with Claude.
Screenshots were inspected inline, not saved as a capture package. Those owned
Editor/host runs are closed and their disposable project/runner are removed.
An October 7 rebuild and silent synthetic check of 0ee93f7 verified ordinary
clicks and Terminal-tab picture changes, with preview and timeline visible
together. Dragging the main Inspector, database Inspector, records-panel edge
and horizontal preview splitter did not move their boundaries this run. The
pointer appeared near the grab points afterward, so the observation does not
establish an application-only cause; Claude must trace shared drag initiation,
delivery and routing rather than repeat a hit-area patch. Hover-only behavior
was not observable through the available tool. The current owned Editor and
host exited cleanly, and its secondary-drive fixture and small runner are removed.
Claude traced the short-drag failure to shared drag initiation: GPUI consumes
the first move to start the drag, leaving a press/move/release check without a
resize move. GUI 07865d5 applies the release position through the same resize
path on the window and modal backdrop. Its release build passed; the next owned
muted synthetic run could not be inspected because the Mac was locked. Only
that test's GUI process was terminated, its host stopped and its disposable
fixture/runner were removed. This is not native verification of the new fix.
These are shared-editor observations, not actual D'jin battle acceptance or
qualification of other platforms. Art's latest grounded 14-second proof has
been delivered on the secondary drive; final motion review and runtime admission
remain open. No new paid pilot or runtime admission has occurred.

The shared cinematic camera/cover correction is implemented in live Engine
develop. Shake no longer detaches the camera or overwrites its ownership history.
Cinematic sessions supply continuous graphical black fades/wipes through the
same scene-overlay path for retained fields and opaque canvases, preserve a
settled cover across transfers, and leave notifications above it. Terminal and
lower-capability covers remain intact. Eight affected suites pass 281 tests /
2,131 assertions, including normal/reduced motion, follow/detached ownership,
cancellation, pause, composition and teardown. Scoped analysis passes at the
repository's configured level; an optional stricter level-6 check still reports
19 existing diagnostics in those files and is not claimed clean. The actual
escort walk/transfer has not been re-observed natively after this change.
Ordinary blocking doorway transitions retain their separate existing path.
Story integration and ritual artwork are independent follow-ups, not G3 credit.

October 6-7 transition profiling identified two shared CPU bottlenecks: composite
polygon/stroke rasterization before native frame acceptance, and device-pixel
resampling during paint. Conservative scanline work, prepared brushes and bounded
row processing now retain the original pixel math, resource limits and authored
820 ms Gilded Sweep timing. Typical cover/reveal compositor work fell from
57-58 ms to about 3-4 ms in the recorded replay; 24 sampled PNGs match the
baseline byte for byte. In three silent native retained-protocol passes, median
CPU paint fell from 20.39 ms to 2.46 ms (final p95 8.45 ms). All 149 candidate
frames were accepted without errors or dropped diagnostic records; GPUI still
coalesces intermediate paints. Final preparation p95 was 17.12 ms, with an
80.23 ms outlier, so this is not a sustained-60-fps or GPU/display-completion
claim. Rust release tests pass 213 cases with four opt-in checks ignored; the
explicit compositor replay and strict clippy pass. The final renderer was
installed through the normal local updater (SHA-256 0a3479ce1c94c742fd65214f2808cf46138d203a0c61713fc291d0d3d5626486).
The subsequent shared header-freshness correction below is also installed and
retains these raster changes; fresh Game launches use that newer release.
Andrew's open game, settings and saves were not disturbed. Receipts stay on
the secondary drive. Full player playthrough,
visible motion acceptance and Windows/Linux qualification are not claimed.

### G3 Acceptance Checklist

Andrew started G3 on October 6: prove one D'jin cinematic before producing the
remaining Lika'mi cinematics. The production direction is at least eight
summons, potentially twelve to sixteen, but that does not authorize a batch of
unreviewed designs or provider jobs. Each definition keeps stable identity,
assignment, targeting and outcomes independent of replaceable presentation.
The terminal remains a simple step/effect/result/return experience; a richer
GUI preview must not impose graphical dependencies or editing on the TUI.

| Requirement | Current closure evidence |
| --- | --- |
| Shared summon data and playback | Renderer-selected paired timelines share stable summon identity, validation, image/camera/cover projection, playback and outcome policy. Compiler v4 gives authored stages cover ownership without rewriting common legacy fades or independent Terminal cadence. Fractional image placement, atlas crops/flips, mutable artwork and reduced-motion rests remain supported. The accepted 30-track D'jin sequence uses these generic contracts, not a summon-specific renderer. Prior full Engine and focused shared-stage checks are recorded below; native closure is now recorded separately. |
| Exactly-once battle execution | Actual integrated Traditional/ATB casts select all four living enemies, reduce each target's HP once, spend 12 MP once and return the summoner to formation. Normal and reduced native runs both complete without missing art, early outcomes, stranded controls or stage leaks. Shared synthetic actual-engine checks cover Graphical/Terminal, lethal/nonlethal outcomes, pause and interruption before/after contact, repeated exit and failed eligibility. Preview remains non-mutating and silent. Graphical damage waits until whiteout clears; Terminal keeps its own cue/cadence. |
| Shared preview and GUI authoring | Paired source-preserving sequences, shared playhead, stage subjects/points, camera/cover lanes, image placement, save/reload and Undo/Redo are implemented. Current production Editor verification passes four checks / 213 assertions; no-op round trips leave the actual PHP source unchanged. A fresh GUI release built at 6a12c82 passes all 55 release tests. Native inspection verifies the main Inspector, database Inspector, records-panel and preview/timeline splitters, plus independent Terminal/GPUI tabs. Earlier native ad0eb32 evidence retains picture/playhead/Undo alignment. The latest synthetic resize fixture intentionally lacks a battle scene and displays an explicit preview refusal; it does not fabricate one or claim new preview-pixel verification. Later GUI 1963ccf is not the inspected build. |
| Approved D'jin stand-in | Andrew's October 7 instruction accepts the existing Natural-Entry-Path sequence as a rough final-output stand-in. The revised shot order is retained: hellscape/portal foot close-up, head-down full reveal, charge/readiness, actual battle side-profile beam, whiteout, clear arena and damage. The silent 14-second painted-2D sequence uses replaceable body/effect layers. Leading-boot yaw, armour continuity and stepped articulation remain later animator polish, not a delivery gate or authorization for more provider jobs. Revoked Pilot 2 is not admitted. |
| Production assets and lifecycle | Only the accepted 29 decoded runtime PNGs, totalling 19.1 MB, are admitted. Videos, proof arena, originals and render scratch are excluded. Admission digests are historical provenance, not runtime locks. The graphical apply_djin_damage cue is frame 323, when the whiteout is fully clear; actual arena restoration starts at 228. Original Terminal source, 12 fps/108 frames/cue 71 and common 450/300 ms fades are preserved. Shared interruption, pause, replacement and cleanup remain covered. |
| Integrated acceptance | Selected normal native frames visibly show the portal/boot/reveal, side-profile beam over the actual arena, whiteout and cleared-arena damage. Corrected continuous normal and reduced runs complete both battle engines; reduced rest artwork was also visually inspected. Native presentation counts are transport evidence, not frame-perfect GPU/display timing. All owned launches were muted before project load and closed cleanly, with saves/settings untouched. Focused checks and macOS observation do not establish Windows/Linux/WSLg support or a full release playthrough. |

Historical October 6 refinement context, superseded for stand-in acceptance by
Andrew's October 7 instruction below: his review accepts its direction, not final
motion or runtime admission: "Nice. The direction is correct... D'jin doesn't
look like he's stepping out of the portal correct. He seems offset from the
center. Otherwise keep refining." Art has this feedback within the existing
entrance refinement. The current authored portal layers and registered body have
independent stage positions; their opening, body axis, leading-foot trajectory,
landing and rim occlusion must be reconciled together. Preserve the approved
camera sequence and shared body registration; do not hide the mismatch with
per-pose sliding, transparent-bounds centering or a D'jin-specific renderer rule.
The subsequent grounded refinement reconciles the opening and support-foot
contact and removes the stray armor contour. Its silent complete proof decodes
to 336 frames at 24 fps, 1280x720, and 14 seconds, with no audio; the coordinator
verified the delivered digest and inspected decoded frames/contact sheets.
Entrance leg cels remain stepped while the upper body is held, so natural
whole-body weight transfer is still unfinished. Andrew explicitly authorized
the coordinator to send that refinement and separate covered, non-graphic
wrist-bite poses to the existing Art producer; the handoff succeeded and Art is
working. No additional paid job, final motion acceptance or runtime admission
is implied.

### Historical Delivery Checks

The candidate-only, final-motion, locked-desktop and pending-integration statements
in this historical sequence describe their dated checks. They are superseded by
the October 7 closure evidence below and must not be reported as current blockers.

The October 7 paired-integration cover conflict is corrected in compiler v4:
the rejection of a staged selection with common nonzero legacy durations is
removed, and only that selected stage compiles zero effective legacy fades.
Common source type/color/easing/mask/duration metadata stays unchanged;
non-staged Graphical and independent Terminal retain their legacy treatment.
Fifteen new synthetic cases protect ownership, independent cadence/cues,
source round trips, stale/wrong-lane caches, restoration and pause/cancellation.
Twenty-four focused Engine suites pass 1,202 tests / 133,115 assertions; scoped
analysis is clean at the configured level-0 floor, not full-Engine coverage.
The coordinator's shared Editor API check preserved common 650/500 ms fades
and Terminal source through graphical stage save/reload/Undo/Redo; restoring
the non-staged lane restores its legacy fade treatment. The temporary runner
and fixture are removed. Eight relevant Editor suites pass 65 tests / 355
assertions with one production-content check skipped because no Game checkout
was pinned. Current GUI release tests pass all 53 cases. These are headless
checks, not native drag or D'jin motion acceptance. The separate shared PNG
freshness correction now observes the current 24-byte header before PHP cache
reuse; 26 resource suites pass 1,259 tests / 142,815 assertions. Its measured
warm-query median rises from about 4-5 to 25-26 microseconds, not a native frame
measurement. The matching native cache now probes the complete 33-byte signature/
IHDR: equal-length, equal-full-mtime header replacements update decoded images,
crops and colour variants together, preserving older immutable snapshots.
The former restart requirement for header changes is removed; same-header pixel
edits also preserving length/mtime still require a new session. Renderer release
tests pass 214 cases with four opt-in replay/benchmark cases ignored; all-target
strict analysis and formatting pass. The normal local updater installed the
verified macOS release and removed package scratch on the secondary drive.
No native visual, other-platform or full-Engine acceptance is claimed for this
resource slice, and it does not increase G3 completion.

The current GUI 1e3e181 release was rebuilt offline/locked through its existing
managed secondary-drive cache, retaining the normal bundle's executable link.
All 54 current GUI release tests pass with no failures or skips. Source remained
clean on the same ref before and after; Renderer ce9e094 and its existing WIP
were retained. The build reports upstream future-Rust compatibility warnings
for block 0.1.6 and proc-macro-error2 2.0.1, not test failures. No dependency
changes, application launch or user settings/save/audio operation occurred.
This removes the stale-build preparation gap, not the unobserved native drag
check. The October 7 03:32 Editor piece commit 33442ca is independent of G3.

A read-only October 7 check of Art's current candidate composes all 336 normal
and 336 reduced frames through the shared stage path, with at most six images
and two composites. Typed round-trip compilation is unchanged. The original
Terminal lane retains its 108 frames/12 fps, cue 71 and 450/300 ms fades. The
candidate still lacks the Game definition's required outcome cue and correctly
fails real-definition compilation; its proof-only end policy does not authorize
changing gameplay timing. Candidate frame 228 restores the arena with foreground
continuing, frame 288 is white-covered, and frame 323 has no images/covers.
Final visual clearance/outcome binding belongs to accepted-art Game integration,
not an invented last-frame cue. Art continues refining the entrance. The native
inventory still reports the Mac locked, so no new window was launched. GUI
develop is now 1e3e181, including the 07865d5 drag fix; Editor develop is 9092b92
after independent map work. Fresh native verification must identify its built
revision. No motion, runtime admission, resize or actual battle acceptance is
claimed from this candidate check.

The October 7 action audit found that Djin targeted one living enemy despite
the approved all-enemy beam direction. After notifying Game ownership, the
coordinator corrected only its authored scope to Enemy / All / Alive and the
singular description in normal Game develop. Costs, formulae, repeats, Petition
eligibility and acquisition gates are unchanged; no extra cinematic damage is
introduced. Lore's advisory supports this from the supplied approved brief,
not a repository-wide canon audit. Four actual queued casts through Traditional
and ATB, normal and reduced motion, each select all four living enemies of the
current fixture troop, lower each target's HP once and spend 12 MP once. Their
resolved total loss matches the action result, the summoner returns to formation,
and saves/settings remain untouched. This headless check uses the existing
presentation and explicitly reports its missing graphical image effect; it
does not admit Art's new candidate or connect its absent damage cue. Final
accepted visual-clearance timing, native battle observation and broader
multi-target balance playtesting remain open. No commit or publishing occurred.

Art's newer Natural-Entry-Path candidate is a silent 1280x720, 24 fps, 336-frame,
14-second video. Its digest and complete decoding were independently checked;
all decoded overview cells and selected full-size landing/reveal/release frames
were inspected. Eleven entrance drawings keep the approved shot order, but
leading-boot yaw/armor silhouette still changes abruptly across frames 93-95
and the landing transition. Fixed registration and monotonically measured toe
height do not prove natural anatomy or motion. This is a drawing-continuity
limitation, not a reason for renderer sliding/warping or a new individual scale.
Art has finished its best complete proof and replaceable layers with explicit
limitations; its authoritative task is now idle, not still rendering. The final
mapping matches the exact already-probed source after its assembly-owned version
header, and six critical manifest members were independently checked. Art's full
integrity receipt verifies 4,832 files; a separate representation-comparison fix
closes that checker discrepancy. These are historical delivery receipts, not
runtime gates on future artwork replacement. The coordinator has asked Andrew
whether to prepare an external animator handoff for his choice of artist/budget
or pursue another production route. No hiring, spending, further similar redraw
loop, final motion acceptance or runtime admission is implied. An actual coherent
entrance is still required; this is not another request to approve correcting it.
The dedicated Engine worker's exact new 30-track probe passes 1,420 checks:
29 PNGs validate/decode, typed source/compiled round trips match, and all 672
normal/reduced frames stay within canvas budgets. Actual Terminal compilation
matches its original cadence, cue 71 and common 450/300 ms fades. Normal image
nodes clear at 288 and covers at 323; reduced presentation clears at 228. These
are fresh measured payload boundaries, not approved contact or native timing.
Actual Game compilation still correctly rejects the missing apply_djin_damage
cue. No policy, source, outcome cue, action scope or asset was changed by the
probe. This closes candidate compatibility checking, not motion, Game binding
or real battle acceptance; older 26-track results are not substituted.

Each request must retain an owner and a next integration action. Delivery,
dispatch, compilation and acknowledgement are intermediate evidence, not
closure. A runtime request closes only when it is wired into the live normal
checkout, its player-visible behavior is verified, and the report states what
was observed and any remaining limitation. Headless checks alone cannot close
an artwork or motion acceptance request. Game-specific content requests belong
in the existing private Game roadmap under its owner, not a second public plot
ledger. Unrelated urgent work neither disappears nor changes the G3 denominator.

Andrew's October 7 delegation direction assigns Engine implementation and tests
to a dedicated worker; the coordinator retains Claude/Art communication,
integration, decisions and final verification. The worker's shared staging fixes
preserve omitted independent transforms and inherit omitted subject-bound roles
per renderer, removing forced defaults rather than adding Game overrides.
Claude verified 16 affected Game staging/escort checks and removed the redundant
position/art workarounds. His full Game receipt reports 1,898 passed with the
separate counter/service reachability failure still open. Andrew subsequently
specified talk reach across one counter cell; Claude has reworked Game counters
to one row. The worker's shared interaction/reachability correction now passes
519 relevant tests / 5,752 assertions and scoped configured analysis. It explicitly
removes historical multi-cell counter reach. Claude verified the current Game
services with 563 affected checks; native service interaction and source-preserving
GUI authoring remain unobserved. No new counter decision is pending.
Fixed-pose atlas loops
are now headless-ready through the existing field clock, including dialogue,
suspension, reduced motion and permanent cleanup (1,057 relevant tests pass).
Claude migrated the current relay pulse to that loop; source-preserving GUI
authoring and native acceptance remain open. Event/cinematic
dialogue emotion now passes 482 relevant tests / 3,292 assertions and scoped
configured analysis through the existing dialogue context and expression catalog.
Claude authored the First Bargain's Vampiric Bloodlust/Vampiric expression change;
a catalog-backed picker, source-preserving round trips and native portrait
acceptance remain open.
These urgent field/story repairs remain outside the G3 estimate.

### October 7 Closure Evidence

| G3 requirement | Completed evidence |
| --- | --- |
| Workable D'jin stand-in | Andrew's October 7 acceptance supersedes the final animation-polish gate and production-route question: use the existing sequence now to communicate the rough final output. Boot-yaw and armour continuity are later animator polish, not current G3 blockers. No further paid generation or hiring is authorized. |
| Accepted layered runtime assets | Coordinator admitted the selected 29 decoded, manifest-verified runtime PNGs (19.1 MB) into normal Game develop, excluding the review arena, videos, originals and renders. The paired authored timeline preserves the original Terminal lane and common 450/300 ms fades. Graphical apply_djin_damage is bound to frame 323, the first frame with whiteout fully clear over the restored actual arena. The historical admission receipt does not lock mutable artwork hashes at runtime. Actual integrated acceptance passes. |
| Live summon acceptance | Normal native run: 594 presented frames; reduced run: 103 presented frames. Each run completes Traditional and ATB casts, all four targets lose HP once (272 total each cast), 12 MP is spent once, impact is not early, and actor return/cleanup pass. Normal uses all 29 selected assets, reduced uses five rest assets; missing-art lists are empty. Native selected frames are inspected as described above, not a claim to have watched every presented frame. The actual Game normal-motion regression passes 21,678 assertions after the acceptance observer is corrected to recognize staged image IDs and the plan's optional title phase. |
| Shared GUI usability | The fresh inspected GUI 6a12c82 build includes 07865d5's shared release-position resize correction and passes all 55 release tests. Native short drags visibly move all four boundaries; tabs select their independent timeline lanes while preview stays pinned above the timeline. Source-preserving production Editor checks pass four tests / 213 assertions. The disposable fixture changes no normal project files. |

Native acceptance exposed a shared wire-precision defect: default JSON number
decoding changed a valid near-edge PHP binary64 coordinate enough to overflow the
strict native canvas bound. Enabling serde_json's existing float_roundtrip feature
at the shared Renderer dependency fixes both direct runtime and GUI-mediated
decoding, without clamping coordinates or weakening validation. Two synthetic
regressions preserve valid boundary bits and still reject genuine overflow;
all 16,263 captured actual PHP rectangles pass the corrected decoder. Renderer
release checks pass 216 tests with four existing opt-in checks ignored. The normal
local updater installed the verified macOS renderer, SHA-256
2530b26b60dcb8889876d139295798498d96dbd28b54f062ba35ca5e95d7da67.
All owned test windows and hosts are closed. Builds and fixtures used the managed
secondary-drive cache; disposable local helpers are removed. No new provider job,
commit, ref move, remote branch, push or release was made by the coordinator.

The final focused Engine rerun passes 165 tests / 3,224 assertions in 3.74 seconds
with no failures or skips: actual queued Traditional/ATB commands in independent
Graphical/Terminal lanes, normal/reduced motion, lethal/nonlethal resolution,
pause/interruption and shared stage-cover ownership. This is a focused rerun,
not a new full-Engine or interactive Terminal visual playtest.

October 7 continuation: Andrew explicitly approved the integrated Djin sequence
and requested production of the remaining three in the current four-summon set.
This is a new content-production slice using the completed G3 foundation, not
an unfinished Djin gate. Art production and Lore/Game coordination have begun;
new appearance proposals, asset admission and real cast verification remain
required for that set. The accepted rough-output criterion remains in force,
and no additional paid-provider budget or publishing permission is inferred.
The private Game roadmap owns identities, treatments and production status.
Andrew also approved all-living-enemy scope for Kanyoni, Donna and Torro, retaining
their existing costs and damage formulae. This is authored Game action data using
the shared scope mechanism, not a renderer or summon-specific Engine override;
new appearance proposals and actual artwork acceptance remain separate.

This is the G3 reporting scope. Unrelated story work, a future 3D port and later
summon production are outside the estimate. An approved concept or a pending
provider job is not a completed cinematic; an Engine API is not completed GUI
authoring. Current implementation is local/uncommitted, not a release or
permission to publish.

October 7 acceptance update: Andrew instructed, "We don't need perfect animation
for now. We just need a decent stand in so we can have the rough idea." His
animator is occupied with the opening cinematic for months; "Please proceed with
what we've got so far and don't stall any further. The acceptance criteria for
these summons is something workable enough to give us a rough idea of the final
output." This explicitly supersedes the earlier candidate-only/final-motion and
external-animator decision status above. Existing Natural-Entry-Path artwork is
now accepted for the rough stand-in and integrated locally. Shared correctness,
independent Terminal, reduced motion, GUI usability and real battle verification
remain required; animation perfection does not. Desktop inventory is accessible
again. No new provider job, spending, commit, ref move or publishing occurred.

### G2 Acceptance Checklist

This is the fixed G2 reporting scope, not a new roadmap or a percentage derived
from elapsed time, test counts or unrelated deliveries. Implementation evidence
and finished acceptance are separate. An unobserved check is not reported as
passed; the detailed evidence below and in the effect contract remains
authoritative. Andrew's October 6 shipping direction requires non-critical gaps
to be recorded without repeatedly blocking the implemented delivery.
The Kaelion-Fira story correction is separate: it neither advances nor blocks G2.

| Requirement | Current evidence and remaining acceptance |
| --- | --- |
| Shared command lifecycle | Traditional and ATB execute advance, pose, announcement, source, target, recipient feedback, cleanup and return through one timeline. The real twelve-command queued native sequence is visually verified in normal and reduced motion, including exactly-once outcomes and cleanup. |
| Exactly-once gameplay | Queued command, cost, targeting and result-lifetime checks pass. Render inspection and dropped updates do not resolve combat; no gameplay ownership is moved into the renderer. |
| Battler poses and resting states | Explicit actor/enemy roles and animated-frame timing are implemented. Andrew approved all reviewed revisions on October 5 and Game integrated them. Native pixels verify all 80 party roles, 40 resting-state views and eight complete attacks. The ordered source-pixel consistency audit is complete for all eight actors, with per-batch approvals applied through the shared editable body-span contract. Lunges, crouches, kneels and KO remain poses rather than being forced to standing height. These are current static-art acceptance checks, not a claim of future animated character artwork. |
| Source and target effects | Directional blade/blunt effects, repeated strokes, distinct recipients and authored ground/pivot attachments use the shared timeline. Six-effect continuous native checks verify single blade, two opposite-angle dual strokes, blunt impact, Burn, magic burst and grounded preparation/healing. Reduced motion shows authored static rest effects without shake, flash or acting underline. Queued commands/counters verify weapon overrides and Liora's distinct staff impact. This is sampled native sequence observation, not frame-perfect GPU timing measurement. |
| Recipient results | Damage, overkill, healing, MP changes, miss, KO, revival and own-turn damage ticks use shared typed feedback. Graphical KO captions/result text are removed, not relocated; Terminal feedback and reaction/result timing remain. Native normal/reduced queued sequences verify damage, fallen art, inventory-backed revival and healing/recovery in both engines, including lethal counters and lethal own-turn poison. Seven current Game command acceptance tests pass. The real-scene fixture starts each battle from GameScene, holds the actual victory/defeat state after its lethal tick finishes, then verifies victory returns to the same field and defeat reaches GameOverScene. Independent normal/reduced CPU checks pass two tests / 26 assertions across all four outcomes per motion policy, without saves/settings writes. October 6 continuous native runs additionally show tick feedback before actual victory/defeat, graphical victory and level-up pages, fallen art without KO captions and graphical Game Over. The intermediate defeat message retains terminal styling, recorded under G5 rather than misreported as graphical completion. |
| Terminal and reduced motion | Simplified terminal step/effect/result/return and reduced-motion outcomes/rest art are implemented with regressions. Complete commands pass through production battlefield composition in tracked/untracked and normal/reduced modes: one held step, source, directional strokes, results, return and exact cleanup; identical redraws emit no console output. Graphical pose interpolation, shake, screen flashes and acting underlines are removed from terminal command presentation. Reduced-motion GPUI command/effect/counter sequences are visually verified. Native interactive Terminal sequence/authoring observation remains open because its automation was safety-denied; no alternate route bypasses that gate. |
| Lifecycle and interruption | Automated pause, cancellation, failed emission, re-entry and scene-owned cleanup checks pass. Native command and ordinary-encounter functional runs close cleanly without altering saves/settings or playing audio. |
| Reference scale and grounded artwork | Runtime and previews share Kaelion-reference body calibration, with optional slot-owned perspective depth applied once to all poses/base art about the same ground pivot. Andrew's 5% front-slot treatment is authored in Game, not per actor. The reviewed party/contact/KO and creature revisions are approved and integrated. His later bat wingspan and rat overall-width choice replaces the inflated wingless-body interpretation; the five temporary larger-bat anchors are not retained. The earlier 1,920 party/128 troop probes established canvas fit only, not UI/overlap clearance. Current-pixel shared diagnostics are wired into Game, Editor and GUI, including authored party/enemy regions, overlap and cursor fit. Missing pixels retain an unscaled name anchor; valid pose-only art keeps its calibration. No new encounter is admitted by an art preview. |
| Authoring and safe round trips | Shared battler records, validation, identity rename and actor/enemy GUI controls are on local Editor/GUI develop. Actor unsaved previews, source-preserving conversion, exact preview/write confirmation, stale-reply refusal and grouped ground-point undo/redo pass independent checks. Native actor/enemy ground editing, undo/redo, database saves and fresh-session reload are verified. Native conversion preview/write/Undo/Redo is verified: Undo restores the complete original source hash tree and removes the new timeline; Redo recreates both converted files byte-for-byte. The native check exposed dirty-category ownership and Save All omissions, fixed on Editor 79a472d and GUI d1e2ce7; a native battle-art edit is now counted and Save Project persists it and clears the count. Terminal graphical editing categories stay hidden. |
| Existing-project migration | A tested lossless source converter and explicit-timing, transactional Editor/GUI conversion now exist. Last Legend's seven numeric animation records are timeline-only; all declared terminal/graphical effects load, and its field consumer uses its distinct exact-cadence timeline. This game migration is complete, not retirement of the reader for other existing projects. Conversion inventories field consumers but does not automatically repoint them; authors use their existing effect picker. Editor b06ef53 fixes terminal focus visibility and full proposed-source scrolling; e6591e1 fixes deeply indented source wrapping. Independent affected checks pass 42 tests / 345 assertions through fitted panes at three terminal sizes. Native interactive authoring inspection remains open. The [approved compatibility policy](../effect-animation.md#compatibility-policy-approved-october-5-2026) supports the reader throughout the current major, documents migration/deprecation and defers removal to the next major without blocking G2. |
| Counterattacks | Andrew approved explicit actor/state/nonmagic-ability opt-in on October 5. Both engines and simulation share typed landed-physical-hit eligibility, one response per recipient, after-return playback and execution-time revalidation. Counters do not chain, consume a normal turn/gauge or tick responder state durations; absent configuration remains off. Editor validation excludes summons through the shared catalog. Continuous normal/reduced native checks each finish six originals plus six responses across Traditional/ATB, verifying both directions, original return before response, lethal fallen poses, staff/blade distinction and cleanup. Opt-in stays in disposable instances; the ordinary twelve-command default is unchanged. |
| Integrated acceptance | Full Engine verification is recorded below; focused Game regressions pass, but full Game CI still has its separate NPC-reachability failure. Native acknowledgements are functional evidence, not proof of visible phase order or cadence. |

October 6 current delivery supersedes the prior locked-desktop and native
results-screen status. The full normal/reduced real-scene runs complete all four
outcomes each in 50.36/40.46 seconds, with no saves/settings writes or audio.
Continuous native capture and selected pixel inspection show the approved
normal entry wipe, command/poison feedback, fallen party art without KO labels,
actual victory/level-up pages, intermediate defeat and graphical Game Over.
The final capture request timed out after each preview had closed; both owned
processes independently exited successfully. No locked-desktop claim or failed
gameplay run is inferred from those final timeouts. The earlier 51.10-second
normal run also completed, but its shorter capture did not cover all outcomes.

G2 is complete locally as of October 6. The final current-tree Engine run passes
5,585 tests, with one existing skip and 383,251 assertions, after fixture-cleanup
changes. The checklist above accounts for each runtime requirement; animated
sheet timing is covered with replaceable synthetic assets, while native party
checks use the currently approved static role artwork. This is not a claim that
future animated character artwork has been produced.
Live Terminal cadence/authoring remains
unobserved because automation was safety-denied; ordinary walking-triggered
entry pixels also remain unobserved, although its production handoff passes.
These are explicit testing limitations, not claimed passes. Andrew's October 6
instruction is to ship substantive work and record non-critical gaps without
blocking unnecessarily; no further identical inspection loop is scheduled.
The terminal-styled intermediate defeat message is recorded as a shared G5
coverage gap. No preview remains open, no source/settings/save state was changed
by these runs, and this delivery report does not authorize a commit or push.

The effect plan's Phase 3 is not claimed complete. Its remaining glyph painter
with onion skins, real-arena silhouette preview and per-track mute/solo are
Editor enhancements beyond G2's original Phases 0-2 scope. Existing timeline,
cue and reference authoring, preview controls and safe round trips are delivered.
These remaining authoring features stay in the authoritative effect plan rather
than silently being counted as implementation or added as a new G2 gate.

October 5 23:33 historical acceptance superseded earlier open sequence statuses:
all eight party-role batches, twelve queued commands, six effects and six
original/counter pairs now have native pixel observation. The queued, effect and
counter runs each cover normal and reduced motion and close cleanly with saves
and settings untouched and audio muted. Graphical KO captions and transient KO
text are removed through typed feedback filtering; 652 affected checks and the
full Engine suite (5,583 passed / one existing skip / 383,241 assertions) pass.
Current Game command acceptance passes seven tests / 50,678 assertions, including
lethal own-turn poison ending in victory/defeat. Continuous normal/reduced native
poison checks finish all four outcomes at 19.05/19.02 seconds; their pixels show
tick damage and fallen art with no KO captions. Those native runs use a stand-in
scene that does not draw results. The new opt-in real-scene subjects in the
field-encounter fixture independently pass normal/reduced CPU checks (two tests /
26 assertions): own-turn lethal tick, completed reaction, actual victory/defeat
state entry, result hold, then same-field return or Game Over. No saves/settings
are written. Native results-screen transition pixels remain open.

Ordinary native encounters complete twice per run under project transition
settings (54.71 seconds) and an isolated wipe override (46.81 and 70.79 seconds).
The latest run captured field pixels, but not entry/results transitions. Optional
`--wait-start` now holds before fixture startup so inspection can bind to the
owned window, then starts from explicit stdin input or native Enter without
altering production time or default cadence. Its 30 focused tests / 209
assertions and scoped source analysis pass. The initial new-test failures were
incorrect peer-capability and shutdown-wire assumptions; both are corrected,
not weakened renderer validation. A readable app inventory did not establish
native inspection availability: selecting the exact owned preview subsequently
reported a lock error. Held previews were closed on EOF before gameplay began.
At that point ordinary entry and actual results-screen pixels remained open,
alongside safety-denied interactive Terminal inspection. The October 6 current
delivery above supersedes that results-screen status; no alternate input/capture
route bypasses the Terminal gate. All owned previews were closed; no commit or
push was made.

October 5 19:45 conversion follow-up supersedes the open Undo/Redo status below:
native conversion Write, Undo and Redo are now verified in a fully muted disposable
project. Undo restored the complete original source tree exactly and removed the
new timeline; Redo reproduced both converted files byte-for-byte. The owned Editor
was closed and its temporary project removed. No normal game files, settings or
saves were changed. Battle observation remains unfinished: the Mac subsequently
locked, and an incorrectly noninteractive paused-preview launch exited on closed
stdin before verification. That attempt adds no visual acceptance; the next
interactive launch must keep stdin open.

October 5 18:40 native follow-up: the exact owned silent renderer now supplies
actual screenshots, not just acknowledgements. Held Traditional frames show
advance/attack, a blade stroke on the enemy, return to formation, ground-based
magic preparation, Liora's damage and KO pose, revival and healing feedback.
The completed six-command run resolves once and closes cleanly. Held ATB frames
show original return before the enemy response, enemy impact, a lethal counter
with Seraphis's KO pose and Liora's staff counter with blunt impact. All three
originals and three responses finish with no chain. These held observations do
not establish uninterrupted cadence or reduced-motion native acceptance.

The disposable native Editor verified actor and enemy ground edits, grouped
undo/redo and save/reload through fresh Editor sessions. Exact proposed legacy
source and timeline were inspected and written without moving other records.
The zero-unsaved count exposed a shared database-category collision that also
caused Save All to omit actor edits; Editor 79a472d corrects category lookup and
GUI d1e2ce7 counts the whole project's dirty list. Native recheck now counts a
ground edit, Save Project writes it, and a fresh session reads the saved point
with no unsaved changes. Native conversion Undo/Redo remains unfinished: the Mac
locked before that last check. An earlier expired-window Undo reached the normal
Editor and reported "Nothing to undo"; it reversed no user edit and was reported
to Andrew immediately. No further controls were sent to that window.
The final owned Editor process was then closed and the disposable project
removed; no inspection window is left waiting for unlock.

The optional shared battle-preview inspection clock starts paused, supports
bounded stepping/play/pause and has a thirty-minute wall limit. Steps traverse
every simulation frame; pause redraws do not rerun the fixture or change combat.
The preview clock/driver group passes 34 tests / 9,924 assertions and scoped
source analysis is clean. Full Engine passes 5,566 tests / 383,119 assertions
with one existing skip; diff checking is clean. No new commit or publication
was performed. The normal Game checkout, settings and saves were not changed
by these isolated inspections.

The October 5 follow-up also passes full Engine source static analysis, not
only the preview-driver files. Native inventory still reports the Mac locked
after Andrew's unlock message; no replacement test window was launched by that
recheck. The remaining visual acceptance above is unchanged and is not inferred
from this source-analysis result.

October 5 current acceptance: reviewed art and counter policy are approved, not
pending user decisions. All admitted assets are in the normal Game checkout;
the Game owner then applied Andrew's more recent 150-pixel bat wingspan and
approximately matching rat width. The temporary larger-bat formation adjustment
is superseded, not silently retained. Counter authoring is on Editor `21633e3`;
there are no automatic Last Legend counters. A live missing-art regression now
passes all 88 Game graphical tests / 43,006 assertions. Full Engine verification
passes 5,462 tests with one existing skip / 377,755 assertions. Changed-source
analysis is clean. Shared read-only formation clearance and layout checks pass
36 tests / 165 assertions, including explicit unavailable PNG-inspection reporting.
The final missing-art edge/selection regression pass covered 269 affected
formation/presentation/feedback tests / 5,360 assertions; scoped source analysis
and the 88-test Game graphical suite passed again after those changes.

October 5 follow-up: optional slot `displayScale` and shared `partyArea`
diagnostics are implemented. All 580 focused pose/formation/clearance tests pass
/ 97,285 assertions. The subsequent complete Engine suite passes 5,536 tests
with one existing skip / 382,938 assertions; full Engine source analysis and
diff checking are clean. Checks include every pose, still/animated crops,
normal/reduced motion, party-order changes, missing-base and legacy scale paths,
ground pivots, default enemy depth and unchanged serialized combat state.
Game has authored the front slot at 1.05 and the right-side party region.
The previous Game-only party-side arithmetic is removed in favor of the shared
checker. Independent post-integration GraphicalBattleTest and
BattlePoseArtworkTest pass 464 tests / 60,068 assertions. The Game owner's
14 affected suites report 1,068 passed and its live read-only formation probe
reports zero issues after the front-slot change. Editor 36db607 and GUI e769faa
preserve authored slot display scale through placement edits and formation
drags; the owner's full Editor suite reports 1,553 passed and GUI 26 passed.
Both are on local develop, with clean worktrees at inspection. These are
implementation/test results, not native GUI or battle visual acceptance.

October 5 diagnostic follow-up: the shared clearance checker now accepts
semantic battler labels without changing image identities or diagnostic keys.
Runtime messages use display names and active party-slot/troop-member numbers;
authoring uses authored identities and original formation indices, preserving
the numbering when earlier artwork is unavailable. Duplicate enemies remain
distinguishable. No positions, scale, combat state or serialized image data
are changed by diagnostic labelling. The three affected Engine suites pass
298 tests / 9,415 assertions; scoped source analysis is clean. Independent
Editor formation checks pass six tests / 56 assertions against live Engine,
and the two Game battle/pose suites pass 464 tests / 60,068 assertions again.
The full 5,536-test Engine run above predates this diagnostic-only correction.
The Game owner has separately applied Andrew's exact choice, "Yes, scale
Kaelion's upright poses to idle height", to magic, summon, item and afflicted
scale spans. The other seven actors still require their ordered pose recheck;
the front-slot depth change is not evidence of that work being finished.

October 5 10:12 follow-up supersedes that remaining-actor status: the Game
owner completed all eight ordered pose audits, rendered each proposed change
for Andrew and applied his explicit approvals. The coordinator inspected the
current actor/pose records. The independent eight-suite run then passes 650
tests and fails three / 96,103 assertions: two reduced-motion effect previews
and one reduced-motion queued-command preview classify changes in full image
destination rectangles as movement. Approved per-pose size changes make this
insufficient evidence of battler movement; the fixtures must verify stable
ground anchors independently of pose geometry, without removing their
movement/return/cleanup requirements. At this point the failures were unresolved,
not reported as passing or ignored as expected.

October 5 10:27 follow-up closes those three failures: Game's two fixtures now
share an observation helper that derives the displayed pose's ground pivot,
with the base-art pivot used when no pose registers that image. Different
image dimensions, scale spans and pivots about one ground point are not movement;
an actual step remains movement. Normal motion must still step, reduced motion
must hold its anchor, and both must return to the starting anchor and artwork,
resolve once and clean up. Independent rerun of all eight acceptance suites
passes 653 tests / 96,518 assertions; the new synthetic anchor regression suite
passes three tests / eight assertions. The Game owner separately reports 774
tests across all 14 battle-related files. No art, gameplay or Engine behavior
was changed by the fixture correction; native visual acceptance remains open.

October 5 16:22 follow-up: Andrew approved the current-major compatibility
policy documented in the effect contract; removal in the next major is not a
G2 gate. Game's isolated current-art counter selection is now independently
verified in normal and reduced motion: five acceptance tests / 36,192 assertions,
and both actual preview-tool no-launch invocations finish six commands plus six
responses in 48.233 seconds. These are CPU/composition checks, not native pixels.
After Andrew returned home, desktop inventory responded but exact Editor app
selection explicitly reported the Mac locked. The owned muted process was
terminated, all disposable source hashes were unchanged and its project removed.
Manual unlock is requested for the remaining native checks; no test window is
left waiting. No staging, commit, push or release permission was granted by the
compatibility decision, and none was performed in this follow-up.

The current native GUI build completed offline through the secondary-drive
cache manager. Its missing target link was restored with the existing adopt
command; GUI source and dependencies were unchanged. A muted disposable
authoring project was opened, but the native app-observation call timed out
without any UI state or screenshot. The owned process emitted a macOS service
connection error and closed; its missing PID was independently confirmed.
No controls were exercised, so this does not establish native authoring
acceptance. The disposable authored sources were unchanged and its project
was removed. No inspection window remains running, no normal Game settings
or saves were edited, and no repeated identical native launch is counted as
progress.

October 5 10:53 native authoring follow-up: an owned Editor launch with explicit
macOS window-service access produced live UI state and screenshots immediately.
The muted disposable project showed the actor arena/party preview and shared
image, ground point, size and body-span controls. Its pose section exposed role,
image, pivot, columns/rows, frame sequence, fps, loop, rest frame and body span;
the constrained role dropdown was opened and inspected. The actor counterattack
picker showed no-counter and the synthetic physical Riposte, excluding the
synthetic magic Flare. No selection was changed or saved. The owned three- and
ten-minute inspection processes reached their limits and closed; the final PID
was independently absent. All authored fixture hashes remained unchanged, and
the disposable project was removed. This closes the claim that no current native
authoring controls have been inspected, but is only partial authoring acceptance:
ground-point editing, save/reopen, enemy controls, animation conversion and live
battle phase/effect inspection still need their own observations. A working
Editor screenshot does not retroactively accept earlier failed battle captures.

The next owned muted Editor launch on October 5 was stopped before interaction:
CUA explicitly reported that the Mac was locked and requested manual unlock.
The coordinator closed only the owned test process, verified unchanged fixture
source hashes and removed that disposable project. Andrew was asked to unlock
for the outstanding checks. Earlier successful partial inspection remains valid;
this attempt adds no native acceptance and leaves no test window running.

The latest twelve-command native run finished in 53.029 seconds and closed
cleanly: both engines, three enemy defeats, eighteen sound requests recorded
but never played, settings/saves untouched. One late completed-state screenshot
was observed; it does not establish the preceding moving sequence. The following
55-second all-eight-actor native preview verified 80 roles, 40 resting views and
eight complete exactly-once step/attack/return sequences (53.25-second authored
duration), then closed. Its two screenshot attempts timed out despite selecting
the exact owned silent window. This is a capture limitation, not evidence that
the Mac was locked, and not native visual acceptance. No test window remains.
No new staging, commit, push or publication occurred for these changes.

The next bounded silent eight-actor run again completed all roles/resting
states/attack-return sequences and closed (2,603 driver frames, 743 native
presented frames). Capture investigation distinguishes a working Finder
screenshot from failure on the exact renderer app path: initial AX state names
the owned silent window, subsequent observation times out, and its exposed
Raise action reports the selected path is not active. Selecting the bundle id
is ambiguous among backup apps; the documented exact-path selection was already
used. No alternate capture/input mechanism was used, no unlock is inferred to
be required, and no further acknowledgement-only preview is counted as visual
acceptance. No test window remains open.

Earlier October 5 evidence, superseded by the approval/integration above:
Finder screenshot capture succeeded, but selecting the
owned renderer window timed out. The same silent command process was allowed
to finish: all twelve Traditional/ATB commands and three enemy defeats passed
at 53.003 seconds, with eighteen sound requests recorded rather than played.
The preview closed cleanly; settings and saves were untouched. No native pixels
were inspected, so the visual acceptance rows above remain open.

October 5 authoring verification: Editor `cb25b79` and GUI `b844065` are on
their local develop checkouts. Actor and enemy pages use the same embedded
battler records and shared formation composition; actor preview includes
unsaved edits. Legacy conversion preserves authored frames/cues/comments,
requires explicit consumer cadence, ticks and reduced-motion rest frame,
validates before writing, and writes/restores the new timeline and optional
battle binding as one source-set transaction. Shared confirmation now requires
the fingerprint of the exact reviewed source set; changed choices or files
refuse writing, and GUI replies from older dialog openings or choices are
discarded. One ground-point gesture is one undo/redo group for actors and
enemies. Independent affected Editor checks pass 25 tests / 223 assertions;
all 25 GUI unit tests pass offline, using the secondary-drive build cache.
These checks do not establish native GUI usability or final battle visual
acceptance. The separate Kaelion-Fira story work is excluded.

October 5 follow-up evidence: the completed art proposal was loaded read-only
through the shared runtime geometry, without changing Game assets or troop
data. All eight actors, ten roles, three party slots and eight arenas pass
placement checks (1,920 combinations). The 16 current graphical troops across
eight arenas initially expose one left-edge clipping issue: the mixed-pack
bat. Applying the package's proposed 24-pixel inward placement only to an
in-memory troop copy gives all 128 compositions with artwork and no layout
diagnostics. This is geometry evidence, not approval of new appearances,
encounters or locations. Those proposed revisions still require acceptance
before integration.

The subsequent silent native command preview completed all twelve commands
and three enemy defeats in 53.045 seconds, recorded rather than played its
eighteen sound requests, preserved settings/saves and closed cleanly. Native
app selection explicitly reported the Mac locked; no renderer pixels were
inspected. No preview remains running, and these functional acknowledgements
do not close the visual acceptance requirements.

Editor `0561f01` added terminal conversion access through the same shared
converter and held source-set transaction. The independent fitted-pane probe
found clipped controls and unscrollable proposed source at 160x48, 120x40 and
80x24. Editor `b06ef53` corrects those defects through shared scrolling and
mode-specific input: complete selected controls stay visible, review exposes
every proposed file, Esc returns to choices, and Enter writes the held plan.
Independent affected checks initially passed 41 tests / 339 assertions,
including exact source restoration through undo and stale-file refusal. A
subsequent bounded probe reproduced a shared wrapper defect: source with 24
leading spaces wrapped at 16 columns produced 25-column lines that fit as
spaces only. Editor `e6591e1` corrects the shared wrapper's display indentation
without changing held source bytes or the transaction. Independent verification
passes 42 tests / 345 assertions, with complete deeply indented source visible
through fitted panes at all three sizes. The direct 16-column reproduction now
fits every text line and retains all non-whitespace content. Native interactive
usability remains unobserved.

Complete terminal command composition is independently verified through the
production `BattleScreen`/`BattleFieldWindow` drawing path, not a stubbed field.
Four tracked/untracked and normal/reduced cases observe advance, announcement,
source, directional target strokes, results, return and finish, with one
gameplay impact and an exact baseline after cleanup. Graphical-only shake and
screen flash do not enter the terminal output; identical redraws emit no bytes.
Six related Engine files pass 291 tests / 31,511 assertions; scoped analysis of
the composition test and its support classes is clean. These captured
console-output checks do not claim native window, colour or key-repeat QA.

Latest G2 delivery (4 October): Andrew's approved local-only directional
contract commit is Engine `7db56dc` (51 focused tests passed); dependent timeline
editing/preview is integrated on local Editor develop `9fd749f`. The unused
seven-frame explosion is converted and independently verified at 10 fps/0.7
seconds, retaining its originals and remaining unbound and uncommitted. Other
G2 work remains uncommitted. The shared battle-phase cadence now preserves
consumer-owned source/target timing during migration, and image-only GPUI
targets load their authored terminal counterpart. Timeline-only numeric bindings
no longer add an empty compatibility delay. Game's two numeric records now
delegate to their timelines: Hit Spark has a battle-paced terminal sequence;
Healing Aura's unused legacy cells/cues are removed without adding a flash to
its accepted healing treatment. Four affected Game files pass 187 tests / 50,717
assertions independently. Constrained Editor cadence authoring is now integrated
on local develop `57e21b1` and verified against the shared live Engine tree.
Andrew rejected holding development on another repository's commit or release;
the timing-API commit dependency gate and permission request are withdrawn.
No Engine commit or publishing permission is implied. This historical
cell-format retirement status is superseded by the October 5 approved
current-major compatibility policy; next-major removal does not block G2.

Animated poses now share the timeline's stateless frame-boundary calculation,
instead of using a separate raw floating-point frame counter. Exact playhead
boundaries select the new frame without advancing just-before-boundary samples;
authored rates, looping, final holds and reduced-motion rest cells are unchanged.
Synthetic checks cover every supported pose rate (1-120 fps), using independent
integer expectations. Full Engine verification passes 5,123 tests, one existing
skip / 350,990 assertions; the ten focused pose/effect/terminal/field/summon files
pass 938 tests / 207,003 assertions. Full sequential source analysis is clean.
No native visual acceptance follows from these CPU checks, and the source change
remains local and uncommitted. The subsequent desktop-inventory checks timed out
without returning any windows; no new preview was launched, and no locked-Mac
diagnosis is inferred from that inspection failure.

The formation editor now has a shared, read-only Engine composition query,
`BattleFormationLayout::compose`. Runtime base/pose placement uses its same
geometry helpers. The query supplies scene choices, canvas dimensions,
available backgrounds, ordered party/enemy instances, current idle crops,
ground points and calibrated body spans; it does not construct combat state
or write a preview arena into a troop. Neutral stand-ins use the shared body
axis, not a second sizing rule or a guessed body outline. Missing/invalid art
retains diagnostics and the runtime's existing fallback behavior. Synthetic
query/runtime equivalence and related regressions pass 228 tests / 4,259
assertions; full Engine passes 5,133 tests, one existing skip / 351,053
assertions. Full sequential source analysis is clean. Claude integrated the
query in Editor `f90bf11` and GUI `f95f20c`: stand-ins use the returned body axis
and ground point, and the real-art option uses runtime idle bounds. Preview
arena choice remains separate from troop data; dragging changes only graphical
placement through shared history. Cropped/flipped artwork still needs the
native renderer's existing bounded preparation exported to the GUI, not
duplicate image logic or an invented Editor sizing rule.
Independent Editor source-preserving writer and animation round-trip checks
pass 50 tests / 365 assertions with five optional tests skipped against live
Engine. The subsequent full sandbox run has 1,449 passed, 55 skipped and one
process-inspection failure in the fake-console cleanup test. That exact test
passes separately outside the sandbox (one test / seven assertions), without
launching the actual game. This is combined evidence, not one all-green full
run. Animation records now use the shared schema (`a152ac5`); the old terminal
frame painter and bespoke preview were removed while legacy fields remain
preserved/read-only. The bounded second review reproduced two shared structural
gaps: numeric creation could reuse a removed ID, and copying could duplicate an
exclusive animation role. Editor `a0c6d7a` corrects both shared paths; independent
numeric-gap/copy probes and eight role/round-trip tests pass (51 assertions).
Copying now omits exclusive roles instead of duplicating them, preserving the
effect reference and other fields. No Game data was edited for this correction.

The G2 counter requirement remains uncovered: neither battle engine currently
produces a counter command. A gameplay decision is pending for opt-in counters
to landed physical hits, after the original actor returns, without counter
chains. No counter rates or default retaliation are assumed. Ordinary enemy
turns and multi-hit actions are not counter support or acceptance evidence.

Editor no longer holds either dependent database change off develop. Its
source-preserving actor Attack Style selector and equipment type-icon schema
are integrated on local develop `ab2dd9c`. Equipment icons are no longer
editable per item; weapon/armor rows retain the old icon as read-only legacy
data, while consumable icons remain editable. Read-only rows keep their field
identity for GUI consumers without becoming editable. Claude reports the full
Editor suite at 1,447 passed, 55 skipped against the live Engine; the coordinator
verified the integrated commits and clean Editor checkout. No Game data was
written, and no publication is implied.

Native pixel evidence is still partial: Seraphis's queued ATB knockout pose,
a held production single-slash frame with Kaelion's attack pose, the first
dual stroke with Drazek's attack pose, and Burn with Liora's casting pose were
inspected. The helper now selects exact command tracks, not a shared image file.
The second dual-stroke capture timed out; held frames do not prove effect
cadence. The full pose/action sequence and ordinary encounters still need native
acceptance. Andrew deliberately ended the earlier preview to correct the work
style; that interruption is not a renderer failure. No preview is left open,
no saves/settings changed, and nothing was pushed or published.

Both normal/reduced CPU queued-command runs pass after the cadence change:
twelve real Traditional/ATB commands and three defeats, with exact costs,
outcomes and return cleanup. Earlier native queued runs returned 884/404
presented-frame acknowledgements; that is transport evidence, not visual
cadence acceptance. Andrew removed the acting underline on October 4 because
advance/action/return already identifies the actor. Shared skinned and primitive
composition no longer draws the marker or an "Acting" fallback label, and the
obsolete theme role is removed. Target selection, poses, movement and terminal
presentation are preserved.
Post-removal focused verification passes 547 Engine tests /32,896 assertions
and 96 Game graphical-battle/results tests /40,850 assertions; scoped renderer/
skin analysis is clean. This does not replace native visual acceptance or the
separate full Game CI reachability check.

Synthetic scene-lifecycle checks now repeat three complete entry/return cycles
with normal transitions, transitions off and reduced motion. They require a
fresh battle composition and result state, reveal before combat updates, and
resume of the same field without stale canvas/transition state. The entry suite
passes 10 tests / 207 assertions. Separate independent Game CPU runs now drive
real field input through EncounterManager, SceneManager, BattleScene and menus:
normal motion completes two encounters in 58.69 seconds / 1,775 frames, reduced
motion in 35.63 seconds / 1,161 frames. Each returns the same field scene and
cell; neither assigns an outcome, writes a save or changes player settings.
Current maps, routes and troops are discovered, not pinned in tests. CPU runs
cut directly between scenes and do not prove the graphical handoff.

The new shared `tools/gpui-scene-preview.php` attaches Game's RendererRuntime
before starting the fixture, then presents its actual current scene, including
retained world/sprites and owned transitions. It checks effective music/sfx/master
mute before native process creation and during playback, bounds the session,
stops scene-owned work, closes its own renderer and disposes fixture state on
success or failure. Production wall-clock fixtures are paced on the CPU too.
The new driver and related runtime/entry checks pass 69 tests / 605 assertions;
driver/test static analysis is clean. The full Engine suite passes 4,687 tests,
one existing skip / 174,623 assertions, and full source analysis is clean.
The existing canvas-only battle preview
cannot prove an ordinary field handoff; no replacement field canvas is painted.
The real Game factory verifies negotiated capabilities, graphical battle canvases
and transition activity when a runtime is attached. The October 4 native attempt
exposed a shared preview-driver startup error: attaching the runtime changes
Game's logical viewport, but the driver launched with the earlier terminal grid.
The driver now starts with the resolved post-attachment Console dimensions;
a synthetic auto-sized Game regression reproduced the failure before the fix.
The subsequent handoff failure was a fixture expectation mismatch, not a proved
production failure: the effective transition setting was `none`, while the
fixture required a handoff merely because the project registered a treatment.
The shared driver now passes an optional `--transitions=project|on|off` policy
to trusted fixtures, rejecting invalid or unacknowledged selections before any
scene/native launch. Game's isolated fixture applies explicit on/off only to its
temporary configuration and verifies transitions against that effective policy.
Project mode preserves the existing preference; reduced motion still cuts
directly. No normal source/player setting is changed.

The focused preview/runtime/launch/entry suites now pass 113 tests / 779
assertions; four independent Game isolation checks pass / 31 assertions.
Scoped sequential Engine-driver and Game-fixture static analysis is clean.
The corrected CPU path completed two real encounters. Three muted macOS native
runs then completed two victories each and returned to the same field/cell:
transitions on in 50.89 seconds (20 observed handoff frames per battle),
transitions off in 41.29 seconds (zero handoff frames), and reduced motion in
48.83 seconds (zero handoff frames despite the enabled transition setting).
Every battle presented a graphical canvas; the fixture verified no save/settings
changes and no audio backend. All three owned processes closed cleanly. This
establishes ordinary-encounter functional verification, not native pixel or
complete pose/effect-sequence acceptance. CUA app selection timed out during the
off run, while inventory did not expose a selectable live renderer; no new
locked-Mac conclusion is inferred. No-PTY runs emitted terminal-size probe
warnings on stderr; those warnings do not prove a battle or renderer failure.

Full Game CI now completes after migrating the simulator subclass to the shared
roster contract and updating authored effect-attachment assertions. The latest
4 October run has 1,862 passed, one failed / 856,151 assertions, simulations
excluded. Moving enemy actions into the shared catalogue exposed an invalid
test restriction: it prohibited explicit effect IDs on every BasicSkill,
including the channeler's Burn. Runtime correctly honors that authored binding.
The restriction and frozen balance/occasion/grant assertions are removed;
before/after snapshots now protect every current skill, inventory definition,
party member and command option from presentation-side mutation. Every current
non-summon battle skill must still resolve its explicit effect or semantic
default. The complete binding file passes 85 tests / 4,643 assertions, its
analysis is clean, and Game lint checks 589 PHP files. No authored data or
combat rules changed. The full Engine regression run and sequential source
analysis also pass after the pose-only calibration validation correction.
The remaining reachability check reports the Garden Route Controller,
Happyville Innkeeper and Happyville Shopkeeper cannot be spoken to in current
authored maps, the same outstanding trio reported before this G2 slice. The
Game owner confirms the shared counter lookup works, but Game currently maps
no glyph to COUNTER, so its counter boxes remain solid walls. Any content
authoring correction/decision remains separate; no old positions are restored,
checks excluded or balance changed solely to make tests pass. Full Game CI is
not green.

Andrew's 4 October report of enemy attacks appearing for the whole party
exposed a runtime ownership error: Attack enumerated the shared catalogue,
and Skill implicitly granted the catalogue when the actor's book was empty.
Both implicit grants are removed. Actor definitions now select their inherent
battle-usable BasicSkill via `attackSkill`; only explicitly learned alternate
attacks and special abilities join their respective menus. An omitted inherent
attack retains the built-in AttackAction, including alongside learned attacks.
Last Legend's eight actors explicitly retain their current authored Attack.
Current definitions own that choice across save restoration; learned BasicSkills
now round-trip through AbilityBook and can be reviewed in the field menu too.
Enemy action patterns and explicit sandbox loadouts retain their own grants.
Ownership checks cover every current actor fresh and restored, through the
terminal context window and graphical HUD. The focused Engine run passes
82 tests / 810 assertions; the focused Game run passes 146 / 27,228.
Full Engine passes 5,165 tests with one skipped / 351,261 assertions, and scoped
source/test analysis is clean. Full Game has 1,863 passed, one failed / 856,343
assertions (simulations excluded), with the same three unreachable NPCs above.
This fix is local and uncommitted; no native visual test was launched for it,
and it does not establish final G2 acceptance.

The subsequent shared resource-result correction records actual clamped HP/MP
losses and restorations per effect, including caster drain gains, without
folding command MP cost into effect feedback or cancelling opposed effects.
Items now supply typed results too. The command runner owns the result from
its current resolution rather than replaying a reusable action's history when
execution is refused. The 24 resource-feedback cases pass across both engines,
terminal/graphical and normal/reduced modes; 16 further queued cases reproduce
and protect the result-lifetime correction. No authored skill, item, targeting,
effect amount or cost changes are made by these presentation/result fixes.
Claude's per-record skill migration preserves all 47 current skills by value
and order, including the live effect bindings; the shared ownership regressions
still pass (56 focused Engine tests / 438 assertions and 112 Game tests /
31,888 assertions). The preview preservation check now inventories the current
skill records instead of reading the removed magic.php. These are local CPU
checks, not native visual acceptance or an isolated clean-commit claim.
After the command-result lifetime fix and skill migration, the complete live
Engine suite passes 5,223 tests with one existing skip / 351,659 assertions;
scoped source/test analysis is clean. The current-record preview preservation
test passes 12 / 8,618 with warnings treated as failures. Full Game CI is not
rerun or represented as green here. Desktop inspection still reports the Mac
locked; no further preview was launched or user game controls sent.

Claude's inventory-record migration is on local Engine develop `998910c` and
the live Game database is converted, uncommitted: all 39 current definitions
retain their value and order, including Andrew's Leather Cap, scope, effects,
animation bindings and equipment metadata. The coordinator's preview safety
check inventories current Skills, Items, Weapons and Armors records rather than
only barrels; it checks non-mutation without freezing authored contents.
Independent post-migration verification passes 214 focused Game tests / 54,939
assertions, covering command/effect/result/preview, starter equipment and saves.
Full Game CI has 1,863 passed and the same one NPC-reachability failure / 856,703
assertions, with simulations excluded and warning/risky/empty-suite gates kept.
Full source analysis exposed an ambiguous spell-only constructor argument in
the shared SkillRecord factory. Its dispatch is now explicit, preserving the
record contract and rejecting spell-only fields on other skill kinds without
analysis suppressions. After that local correction, full Engine passes 5,232
tests with one existing skip / 351,695 assertions, full sequential source
analysis is clean, and the 214 focused Game tests pass again. The full Game
run preceded that dispatch clarification, not a second all-green full run.
The scoped preview/test analysis and 32 record/catalog/command checks also pass.
The coordinator changes remain local and uncommitted; no native visual or
non-macOS validation follows from these checks.

The subsequent cleanup audit reproduced command-lifetime failures during
terminal output errors: detaching playback could fail before its ownership was
cleared, while Console frame rollback could restore a flash after the command
had already been disposed. Console now supports explicit non-painting overlay
retirement, preserving normal atomic repaint/rollback behavior. Field cleanup
releases logical state before erasing pixels, and the command runner attempts
each owned cleanup independently. Both battle engines retire commands after
their atomic frame succeeds or rolls back, including failure of the completed
command's final emission; obsolete commands cannot erase a replacement owner.
The focused lifecycle run passes 462 tests / 97,126 assertions. Full Engine
passes 5,248 tests with one existing skip / 351,848 assertions; full source and
cleanup-test/support analysis are clean. The current queued acceptance fixture
completes all 12 commands in a CPU run at 52.95 seconds. A subsequent macOS
preview required desktop access after a sandbox window-service failure, then
could not be inspected because the desktop locked and renderer shutdown timed
out. Neither attempt is native visual acceptance. A later fully muted run of
the installed package completed all 12 real queued commands across Traditional
and ATB, presenting 984 native frames and self-closing cleanly at 52.976 seconds.
All 18 audio requests were recorded rather than played; music/SFX, settings and
saves remained untouched. CUA timed out while binding the preview window, so
this proves native command lifecycle/transport, not pixel or cadence acceptance.
All changes remain local and uncommitted; Game CI's previously reported
reachability failure remains open.

The October 4 damage-display correction removes the remaining-HP cap from HP
damage feedback. The shared typed target result exposes full resolved hit
damage (actual loss plus overkill), and the shared formatter uses it in both
battle engines and both presentations. HP bounds, actual-loss drain, command
costs, healing, MP feedback and simulation statistics are unchanged. The
eight-file scoped acceptance run passes 395 tests / 76,702 assertions on both
PHP 8.4 and 8.5, including real queued attack, physical/magic skill, item, drain,
repeat and group cases across terminal/graphical and normal/reduced modes.
Scoped source/support analysis is clean. This local uncommitted correction has
not been native visually tested; neither it nor the accepted Loch Ness asset
integration establishes final G2 acceptance or full Game CI.

The subsequent fully muted macOS command preview completed all twelve real
Traditional/ATB commands, including three enemy defeats, at 52.981 seconds.
The installed renderer presented 939 acknowledged frames and closed cleanly;
18 audio requests were recorded, never played, and settings/saves were untouched.
CUA selected the exact owned preview window, but both screenshot requests timed
out. This is fresh native sequence/transport evidence, not pixel or cadence
acceptance, and does not establish that the Mac was locked. No test window
remains open. No additional uninspected launches are needed while capture is
unavailable.

Claude's battle-test arena selection is integrated on local Engine develop
`a5ebe09` and Console `a2fb034`: the graphical troop picker cycles catalog arena
choices, and `ichiloto battle --arena <key>` supplies the same optional
`battleArena` setting. Terminal tests and simulations refuse that graphical
option rather than pretending to render it. The 29 synthetic arena checks pass;
Claude reports a full live Engine run of 5,334 passed, one existing skip /
353,755 assertions, with the coordinator's WIP preserved. This is owner-reported
full-suite evidence, not another independent coordinator full run. There is no
Engine commit/release dependency hold. The damage fix and other G2 WIP remain
uncommitted.

The October 4 acceptance audit found unaccepted art, pending counter rules and
unobserved native pixels. October 5 approval/integration and counter implementation
resolve the first two; full native visual acceptance remains open. Legacy cell
data already enters the shared timeline runtime; the October 5 approved policy
retains its importer in the current major and defers deliberate supported-project
migration/removal to the next major, without waiting on that release for G2.
The shared `LegacyAnimationMigration` source converter now preserves consumer
cadence, cell paint order, moving offsets, colours, blanks, sounds, flash tails
and explicit rest frames without file writes. Its 14 synthetic tests and the
related runtime checks pass: 513 tests / 115,299 assertions on each supported
PHP version, with scoped analysis clean. Editor transactional conversion and the
approved deprecation/removal policy are distinct from this API delivery; no
compatibility reader was deleted or project automatically rewritten.
Actor and enemy artwork authoring now shares `BattlerBindings`, which creates
the same typed artwork, poses and reference-scale values from declarative data.
`loadCode` lets Editor validate/preview unsaved bindings without duplicating
catalog path checks; duplicate code/data ownership is refused. Last Legend's
normal checkout now stores all eight actors, fifteen enemies and eighty party
pose roles in `Data/Presentation/battlers.php`. A one-off complete catalog-value
comparison confirmed no change to assets, pivots, profiles, roles, arenas or UI.
Pending anatomy/shadow corrections were not integrated by that migration.
Independent checks pass 296 Engine tests / 7,521 assertions and 584 affected
Game tests / 81,087 assertions on each of PHP 8.4 and 8.5; scoped analysis is
clean. The Game test's frozen wolf object-alias and obsolete arena-name
restrictions were removed, while current image/crop/pivot validation remains.
Editor authoring and identity-rename inventory work continues with its owner.

The latest fully muted native command run finished all twelve Traditional/ATB
commands in 53.02 seconds, returned actors to formation and closed cleanly
(952 presented-frame acknowledgements). CUA selected the exact silent preview
window, but screenshot capture failed with `timeoutReached`; no pixels were
inspected. App inventory confirms the renderer stopped. This is functional
evidence, not visual acceptance, and not evidence that the Mac is locked.
The migrated catalog also passed the same twelve-command CPU fixture without
launching a native window. No saves/settings were modified or audio played.
The same acceptance constraints have persisted across multiple goal turns;
independent damage feedback and approved lake integration are now delivered.

Party pose-scale acceptance is reopened following Andrew's October 3 live
screenshots: equal source-pixel scaling passed but did not establish coherent
head/torso/limb proportions across the supplied art. The existing Art owner
rechecked all 80 party roles and found incompatible KO torso/face ratios in
Kaelion; zero uniform-resize replacements are ready. Andrew clarified on
October 4 that his existing instructions already authorize source-proportion
correction, including repaint; the duplicate permission request is withdrawn.
Art delivered eight rebuilt party KO images on October 4. The coordinator has
inspected the comparison at shared battle scale and surfaced the finished-art
review here; the candidates are not integrated. This is review of the delivered
images, not another request to authorize Andrew's already requested correction.
Source anatomy must not be disguised with whole-image shrinking. The October 4
correction removes individual widths and formation-driven sizing from Last Legend:
one catalog reference uses Kaelion's approved standing height, with calibrated
relative body units for every registered actor and creature. Animated cells,
all pose roles, base-only degradation, previews and reduced motion use that same
contract. Formation owns position/movement space; Art owns anatomy, composition
and ground contact. Editor identity inventories must preserve the reference actor
and actor-profile keys in source-preserving repairs. No terminal or gameplay
geometry changes. The earlier removal of the tiny bat override alone was not a
solution: it exposed the competing formation-fit rule and made bats too large.
Andrew's October 4 Sewer Rat report reopens the complete visual calibration,
not just that enemy. The source audit confirms eight registered actors with
80 pose roles and 14 enemy entries all have profiles in the shared contract;
coverage is not evidence that the authored ratios are correct. The rat's current
32.5-pixel torso span against Kaelion's 150-pixel reference, approximately
64 pixels across the full image including its tail and padding, came from an
unverified Art working estimate rather than an accepted scale comparison.
The existing Art owner has the exact report and is recalibrating all 80 party
roles and all 31 creature/human enemy images together against Kaelion, separating
runtime-bound art from unbound assets. Intended anatomy, species differences,
ground contact and legibility must be checked together at battle size. No
rat-only minimum, formation-fit override or whole-image resize substitutes for
that calibration; the current ratios are not represented as visually accepted.
The subsequent read-only audit exercises all 80 bound party roles in every slot
and arena, in normal and reduced motion (3,360 placements), plus all 30 registered
enemy troop occurrences across seven arenas (210 placements), without geometry
failures. At that audit, thirteen distinct enemy images were bound by 14 entries;
18 of the 31 available enemy battle images were unbound, including Loch Ness.
Its subsequently approved integration is recorded below. This establishes
mechanism coverage, not visual proportions. Shared catalog
validation now rejects uncalibrated pose-only identities and competing pose-only
widths, closing a gap in both party and enemy registrations. Calibrated pose-only
art and projects without reference scale remain supported. Four focused Engine
files pass 490 tests / 91,949 assertions; scoped analysis is clean. The rat's
current ratio is unchanged pending the complete Art comparison. Three affected
Game battle-presentation files pass 101 tests / 41,005 assertions; this is focused
verification, not a new all-green full Game run or native visual acceptance.
Natural KO source corrections must respect a west-facing party hit from the west,
not sleeping poses or forward collapses implying a hit from behind/east.
Andrew's October 4 shadow review covers all battle artwork, including these
pending KO candidates: shadows must be visible at shared runtime scale on the
existing battle backgrounds and suit each body's support, stance and perspective.
Every grounded sole or body contact must meet a local contact shadow; airborne
limbs and creatures must retain plausible separation from their ground shadows.
The existing Art owner has the live screenshot and is reconciling source shadows
across all poses and creatures, preserving approved designs, pivots and scale.
Art delivered the finished local shadow/KO candidate package on October 4:
72 standing party poses, eight rebuilt directional KOs and 28 creature shadow
corrections, with three creature projections retained. The coordinator inspected
all 28 current-scale after boards covering every cast role and runtime-bound
enemy image on three textured backgrounds, plus the formation comparison.
Female chest coverage remains opaque; standing contacts and backward/eastward
KO direction are checked in those rasters. These are candidate-art inspections,
not human design acceptance, native GPUI verification or runtime integration.
The coordinator subsequently inspected all seven creature source-after boards
and the three retained airborne/aquatic projections, completing source-level
shadow review of the available creature inventory. Unbound source previews do
not establish runtime scale or placement. The three current-bound and six
proposed full-cast comparison boards were also inspected at logical battle size.
The actual Rat + Bat grass formation still loses the rat's silhouette with
Art's proposed 40-pixel body span; that enlargement is not an accepted solution.
Copper Scarab, Copperbill Weaver and Pulseback Gecko also lose readable form in
their unbound working ratios. Art has the concrete findings for the existing
correction, preserving species/design and the shared scale contract rather than
adding renderer minimums, changing formations or declaring geometry coverage to
be visual acceptance. No proposed ratios or candidate artwork are integrated.
Art's source-readability follow-up is now complete. Its separate brown-fur
tonal candidate preserves the original rat geometry, alpha and contact shadow;
neither that candidate nor the 40-pixel trial resolves shaded-grass readability.
The coordinator inspected the exact-region comparisons across all seven arenas
and the three current rat formations. These are artifact rasters, not new native
captures. Art recommends retaining current rat design/calibration and reducing
competing fine texture and contrast consistently across playable arena ground,
preserving broad terrain forms, habitat and distant scenery. This is a design
tradeoff awaiting Andrew's direction, not approval to repaint arenas or integrate
the unsuccessful rat candidates. No new arena production batch was executed.
Andrew subsequently clarified the intended fantasy scale on October 4: no
enemy should be proportionally smaller than an adult African hare, and both
rats and bats should be around that body size. This supersedes the suggestion
to retain undersized ratios, not the shared Kaelion-reference scale contract.
Art completed the whole-enemy hare-scale comparison: all 31 source images were
assessed, with 40 comparisons inspected by Art. The coordinator inspected the
eight full-cast boards and the rat/bat/Kaelion and grass-formation comparisons.
The coordinator's subsequent contextual re-review withdraws the recommendation
that the hare-scale proposal is ready for acceptance: the rat still reads as
having substantially less body bulk than the hare, despite equal 55-pixel
longitudinal spans. Equal spans across horizontal and upright silhouettes are
not equivalent visual creature size. Art has the existing authorized correction
back for a coherent whole-inventory calibration, checking orthogonal body
dimensions, visible bulk and actual arena readability without introducing a
second Engine scale authority or automatic pixel-area rule. This is unfinished
production correction, not a further permission Andrew owes for work he already
requested. Andrew's subsequent 22:17-22:20 screenshots and direction supersede
the hare comparison as the minimum-size authority: "Nothing should should ever
be smaller in width than Kaelions stance or shorter than half Kaelion's height."
Art must measure the approved standing reference and reconcile both visible body
axes across the complete inventory. Preserve each character's physical anatomy
through crouched, attacking and fallen poses; do not enlarge a KO silhouette to
a standing-height floor or independently fit every role to a rectangle. The
current 150-unit standing reference implies a 75-unit standing-height minimum;
the reference stance width must be measured, not inferred from the PNG canvas,
weapon, shadow or slot width. These are Game-owned art-direction/calibration
criteria, not fixed Last Legend thresholds in generic Engine rendering.
Fresh coordinator runtime inspection confirms all eighty current party roles
use a constant pixel factor per actor; there is no role-specific contain fit.
All roles share the idle source-span registration, which alone does not prove
consistent body proportions in independently produced images. Drazek's 152-unit
and Seraphis's 156-unit registered body units are intentions, not proof that
the art reads consistently. Their all-role proofs and the whole cast must be
re-reviewed by Art using body proportions rather than image extent or a single
face-distance proxy; normalize technical source scale where sufficient and
correct inconsistent anatomy at its source where resizing is insufficient.
Current Kaelion/Drazek idle hashes still match the original pre-correction
sources, not delivered shadow candidates. Andrew accepts the bandit shadow
examples but reports the party shadows still wrong; preserve those examples
and reopen both-foot contact checks on the actual arena. Do not describe the
standing shadow candidates as accepted or the live party shadows as fixed.
Art's October 5 source audit identified a shared compositing defect: restoring
translucent body-edge pixels after applying the shadow erased shadow coverage
beneath those edges, producing pale gaps at ground contacts. The earlier
standing-contact review is therefore withdrawn, not evidence that this candidate
package is ready. Art is correcting the shared composition step across the
inventory while preserving original body layers and the accepted bandit shadows.
Corrected outputs still require contextual inspection at battle scale; neither
numeric calibration nor a source contact marker establishes visible ground
contact. These corrections remain outside the normal Game until reviewed.
The coordinator inspected the fresh Kaelion/Drazek source-over sole comparisons,
all eight revised party boards covering eighty roles, all eight available-creature
comparison boards, and the rat/bat and trapper road formations. The pale sole
rims are removed in those source artifacts; the revised Seraphis board improves
visible role consistency. This is bounded artifact review, not native capture
or final acceptance. The requested standing minimum must also be checked against
all eight party identities, not only the enemy calibration; that Art check is
still in progress. No corrected image or proposed scale is integrated by this
review, and the accepted bandit shadow sources remain unchanged.
The directional-KO candidates remain a separate delivered-art review. No
renderer clamps, formation fits or arena
repaint are authorized as substitutes. Andrew separately
approved Loch Ness and Secret Lake, now integrated in the normal Game checkout:
transparent east-facing emerged creature, left water/right shore twilight arena.
The approval excludes the separate party-shadow and hare-scale candidates.
The combined proposal raster is not native playtest evidence.
Several Liora poses needed their actual sole-depth order corrected rather than
a uniform shadow template. Rat readability and complete visual size calibration
remain unresolved; the delivered shadow package does not close those issues.
Andrew's 10:15-10:17 screenshots still show rear-foot separation and report it
across other actors. Art has those exact examples and has reopened assembled
visual contact checks; earlier preservation passes do not establish acceptance.
Nonzero shadow alpha alone is not visual acceptance. The coordinator's source
audit found the standing-art path draws the complete PNG at full opacity and
moves the body and baked shadow together; no per-actor shadow offsets or extra
Engine-drawn ovals are being introduced to hide source-art contact problems.
The current macOS native unit suite passes 199 tests, with four explicit ignored
native/benchmark cases, and all-targets source analysis passes. Its PNG/crop/flip
checks retain pixel alpha; these headless results do not establish shadow
visibility, native visual acceptance or Linux/WSLg behavior.
The scale migration's focused Game checks pass 486 tests/58,844 assertions;
Engine battle regressions pass 536 tests/32,725 assertions and source analysis is
clean. The isolated muted macOS pose run completed all eight actors and eighty
role views with 743 native acknowledgements, but CUA could not select the process
for pixel inspection. Native visual acceptance and acceptance/integration of
Art's delivered directional KO images remain open. Editor actor-reference repair preserves the new scale identity keys;
GUI editing of reference height, body ratios and pose calibration remains an
authoring capability to add, not an outcome established by those repair tests.

The October 4 ring-placement correction separates image pivots from subject
attachments in battle image tracks. Blue casting and green healing rings now
attach their normalized ring centres to the moving caster/target ground point,
not a posed rectangle's centre. Viewport clipping preserves that attachment
instead of shifting effects into view. Terminal sequences and approved PNGs are
unchanged. Full Engine verification now passes 4,758 tests/176,021 assertions,
with one existing skip; full source analysis is clean. Four focused Game suites
pass 550 tests/71,407 assertions, retaining sheet/crop/cadence/outcome checks
while asserting authored attachment/pivot against independent ground points.
Sixteen real queued-command cases additionally protect lethal own-turn poison
and KO visibility before resolution in Traditional/ATB, terminal/GPUI and
normal/reduced motion. Native visual acceptance is still open. Desktop inventory
and a read-only screenshot of Andrew's running game became available again;
no controls were sent to it. Two fully muted disposable command runs completed
all twelve commands after this correction and closed cleanly (964/962 presented
frame acknowledgements). Selecting the separate preview failed, including a
temporary test-app identity pointing to the same binary; the latter inspection
call hung and returned no pixels. Those runs prove execution, not the ring's
visual placement or continuous cadence. No further acknowledgement-only run is
planned. Claude's source-preserving Editor choices and
whole normalized-point codec were integrated at `0d5960d` and remain on local
Editor develop `ab2dd9c` (Claude reports 1,447 tests passed, 55 skipped against
live Engine); GUI sheet-pivot selection
remains authoring work. Field subcell image pivots need a proper graphical drawing contract;
the existing whole-cell protocol cannot consume these keys and refuses them.

Andrew's separate October 3 frontline-wipe correction removes automatic reserve
cycling from the default Party behavior. BattleConfig now owns a shared runtime
roster; the explicit per-battle `reservePolicy` (`none` by default, or
`replace_after_wipeout`) controls replacement after the final KO presentation,
not asset reads or drawing. Traditional/ATB, targeting, terminal/GPUI and the
simulator consume that roster. Scripted `start_battle` forwards the same option;
normal defeat remains Game Over and explicit continue-after-defeat stays separate.
The Editor owner added a constrained per-encounter Reserve Policy choice to
both editors on local Editor develop (`e569d72`); omitted/cleared values remain
`none`, and no Last Legend encounter has been opted in. Engine full regression
passes (4612 tests, one existing skip), as do Game graphical/pose checks (109)
and static analysis. This correction has no new native playtest or publication.

Battle ownership correction (2026-10-02): scenes supply name/background/skin,
troops supply per-enemy graphicalPlacement, and the shared canvas layout owns
party slots, UI geometry and feedback safe area. Troop-keyed scene fallback
and arena-side formation overrides are removed. Explicit battleArena or the
catalog's declared default selects the scene. See the existing
[contract, migration conflict and audit](graphical-battle-g1.md#troop-owned-graphical-formation).
Claude owns source-preserving Editor placement/role round trips and arena
reference validation; runtime integration does not complete those workflows.

Andrew approved Loch Ness and Secret Lake on 4 October. Integrating their
mixed-map gameplay entry exposed a missing encounter-context selection path,
not a reason to restore troop-keyed arena defaults. Weighted map rows now accept
`['weight' => n, 'battleArena' => key]` alongside unchanged numeric weights.
The selected row outranks the map's common arena; other rows, scripted callers
and battle tests retain their own scene choices. Synthetic parser/dispatch and
event regressions pass 105 tests / 669 assertions, with source analysis clean.
The second bounded Engine round, including graphical battle presentation, passes
320 tests / 4,847 assertions. Game integrated the approved assets, shared
Kaelion-relative emerged-body calibration and water-contact pivot, with only
the Loch Ness entry selecting Secret Lake. Its graphical battle suite passes
88 tests, including current encounter discovery, targeting, source budgets,
terminal parity and unchanged combat outcomes. The bounded five-file Game
battle regression round then passes 595 tests / 76,141 assertions on each of
PHP 8.4.21 and 8.5.11. The battle-simulation group is excluded; current regional
pressure cases in those selected files do run. Both-version syntax and scoped
whitespace checks pass. The existing private Game integration roadmap records
the accepted assets and completed binding in place. No full Game suite, new
native-window playtest, commit or remote publication is claimed for this
integration.
Claude implemented source-preserving row/weight/rename round trips,
map and per-row GUI arena pickers and arena-reference validation on local Editor
develop (`f5a9508`, with explicit-null refusal in `4dafbea`). The terminal editor
preserves authored arenas without offering graphical controls. Claude reports
1,509 passing Editor tests and clean analysis; current Game integrity checks
pass read-only. No commit or release dependency was imposed on development.
See the existing graphical battle contract for the schema.

Battle testing now accepts isolated per-member command, skill and summon
loadouts, including summons before campaign unlocks. Automated `--runs` remains
attack-only and refuses these loadouts explicitly. Its remaining shared action
policy must exercise real commands, skills, summons and authored enemy patterns
through the normal combat rules; accepting loadouts without using them is not
test coverage. See [the existing tooling roadmap](../roadmap.md).

## Queued character progression design

Requested on 2 October 2026 as a separate goal. Queued, not started; it does
not replace or expand the active G2 delivery. Design and author review come
before implementation.

- Base advancement on the project's established card-suit system, consulting
  Lore and the existing private progression decisions rather than copying
  another game's grid or inventing ranks. Each character can advance within
  different suits and unlock skills and magic through that progression.
- Give players meaningful growth choices, with role-guided paths that make
  each character's party role understandable without silently prescribing
  every choice. Costs, prerequisites, cross-suit limits, branching, reversibility
  and their relationship to character levels remain design questions, not
  approved mechanics.
- Design one progression manager and shared rules, ownership, unlocks and save
  state. Games own their paths, suit definitions and balance. Reconcile existing
  spellbooks, ability books, class grants and learning requirements instead of
  adding a second source of skill ownership or conflating spell grades with
  discipline ranks.
- Produce distinct Terminal and GPUI UX proposals. Terminal navigation must
  clearly expose suits, ranks, available paths, requirements and rewards without
  depending on a graphical grid. GPUI can visualize card paths and connections.
  Both need readable comparisons, locked/available/unlocked states, purchase
  confirmation, semantic navigation and identical progression outcomes.

When activated, coordinate Game, Lore and UI through the existing owners and
bring the consolidated rules and both UX proposals to Andrew. Record game
design decisions in the existing private roadmap; this entry tracks the shared
capability and its queue status, not a parallel game-design authority.

## Platform feasibility and release gates

The target direction is desktop distribution through Steam, Android and iOS,
with Nintendo Switch 2 and PlayStation 5 later. Release order does not mean
feasibility order: mobile runtime proofs and console constraint discovery start
early, alongside desktop delivery work, before production depends on an
unproved host feature. This section is the shared technical plan, not a claim
that these platforms are supported or authorization to begin every port.
Game-specific device choices, budgets, approvals and execution receipts belong
in the game's existing private roadmap, not a competing platform plan.

### Starting boundary

| Target | Current evidence or constraint | First proof required |
| --- | --- | --- |
| Steam desktop | Local macOS development installation exists, not a complete player package. Linux is unqualified. Native Windows renderer transport rejects startup. | Clean-machine launch of the packaged game, runtime, renderer and audio on each advertised OS. |
| Android | No game app host, runtime port or device qualification exists. | Bundled PHP and actual gameplay dependencies running in an Android app, followed by rendering, input, audio and lifecycle tests on a physical device. |
| iOS | No game app host, runtime port or device qualification exists. | Bundled interpreter and gameplay running inside the app sandbox without a desktop child-process or downloadable-code dependency, followed by physical-device tests. |
| Switch 2 | No SDK assessment, runtime port or qualification exists. Nintendo's public access notice currently closes new development-environment requests. | Authorized SDK/toolchain access, then a runtime and rendering compatibility assessment before any support commitment. |
| PS5 | No SDK assessment, runtime port or qualification exists. Development resources follow partner approval and agreement. | Authorized SDK/toolchain access, then the same runtime and rendering compatibility assessment. |

Public platform requirements were checked on 30 September 2026; recheck them
before choosing supported OS versions, applying for access or submitting builds.
Unknown SDK requirements remain unknown. A public registration page, emulator
run, library demo or successful cross-compilation is not shipping evidence.

The inspected implementation has concrete portability gaps:

- Engine depends on modern PHP and Composer libraries. Audit the resolved
  production dependency tree, extensions, build options and redistribution
  licences; the root PHP constraint alone is not the effective minimum.
- `ProcessRendererTransport` uses POSIX child-process pipes; removing its
  Windows guard would not fix the unsupported nonblocking I/O.
- `RendererRegistry` creates the desktop process runtime. An embedded/mobile
  host needs a verified entry and transport path, not just a new renderer ID.
- GPUI is pinned to 0.2.2. Upstream desktop support or a community mobile port
  does not prove that this pinned renderer, text system and image operations
  work on another target.
- Current audio depends on command-line players and process spawning. The
  [native audio plan](../native-audio.md) is a desktop component plan, not proof
  of mobile or console audio support.
- Physical controllers, touch input and whole-game lifecycle qualification
  remain incomplete. Graphical terminal fallbacks do not constitute a usable
  interface on a phone or console.

### P0 - Constraint and dependency audit

Engine coordinates with Renderer, Console, Game, UI and Art owners. Produce
one capability matrix covering runtime/build toolchains, required extensions,
graphics APIs and texture limits, audio, input, filesystem/save paths,
suspension, distribution/update rules and platform services. Every entry records
the source, date, tested version and either evidence or the unresolved question.

Propose representative minimum devices and OS/CPU targets, with access and
costs identified. Desktop candidates include native Windows, macOS and Linux;
Steam Deck needs its own handheld acceptance if advertised. Android and iOS
start with physical ARM64 devices, including an intended lower-end target.
Choose minimum versions from the actual dependency and device results, not
from a framework's headline platform list. Agree frame-time, memory, startup,
package-size and thermal/battery budgets before accepting later gates; 60 fps
preferred and a deliberate 30 fps lower tier are proposals, not established
performance or permission to change game pacing.

Audit store access, signing, ratings, privacy, dependency/asset rights and
required disclosures now. Include pre-generated AI content in the Steam content
survey review. No account signup, agreement acceptance, fee, tool installation
or hardware purchase is implied by this plan. Route those needs, with cost and
purpose, through the coordinator rather than separate task questions.

**Exit:** an evidence-labelled matrix and a bounded prototype brief. Classify
each requirement as proven, needs proof, or blocked, with owner and next action.
Do not mark a console feasible merely because access is unavailable.

### P1 - Prove the runtime and host before scaling the port

Run the most restrictive runtime experiments first: an iOS in-app interpreter
proof and an Android app proof, in parallel with the native Windows transport
and desktop packaging investigation. Lack of one device must not block the
independent audit or another target, but its gate stays open.

Use the current Engine and representative authored PHP data/scripts with their
real dependencies. Prove bootstrap, autoloading, timers, state transitions,
asset reads and a save/load round trip inside a read-only application bundle
and permitted writable user storage. Test with JIT disabled; do not make JIT,
shells, Composer, a terminal, a server or network access a player requirement.
A PHP hello-world, PHAR archive or remote gameplay server is not this proof.

Evaluate an embedded PHP host and bounded in-process/native transport where
desktop child processes are unsuitable. Reuse existing transport and semantic
contracts where sound; record concrete coupling before proposing new APIs.
Native window callbacks must not block while PHP runs a console loop. Prove
event pumping, cancellation and shutdown without creating a second gameplay
authority or assuming that platform UI APIs can run on any thread.

Compare the pinned GPUI path with a narrowly scoped alternative presentation
backend only if required by the target. Exercise sprite source regions,
retained state, text, clipping, blending and required effects, not merely a
blank window. Present compatibility and maintenance costs before choosing a
fork, dependency change or backend. For audio, assess how the separately owned
mixer could be hosted on restricted platforms; do not silently supersede the
standalone desktop audio decision or put audio ownership inside GPUI.

**Exit:** runnable runtime/host evidence on real mobile devices and desktop
proof results. If PHP, required dependencies or rendering cannot meet the
target, stop commitments that depend on them and present the smallest viable
alternative with migration cost. No silent rewrite, cloud substitute or dropped
target. Store compliance assessment and later submission remain separate from
a technical device pass.

### P2 - One representative cross-platform game slice

Use the normal Game checkout and shared content: title, field walking with an
NPC and interaction, portrait dialogue, menu navigation, a battle and results,
a cinematic, and save/quit/reload. Reuse existing scenarios; do not accumulate
independent game copies. Include representative planned peak artwork/effect
loads using bounded fixtures where the production feature is not implemented.
Such fixtures test resource budgets, not completion of that future feature.

- Keep collision, events, combat outcomes, scene timing and save identity in
  PHP. Compare semantic outcomes across hosts; differences in font rasterization
  need not produce identical screenshots.
- Run the complete slice without a keyboard on mobile, and with a physical
  controller on desktop/handheld. Test remapping, disconnect/reconnect, held
  controls, focus loss and release/reset; controllers are not fake key presses.
- Fit UI to phone, tablet, handheld and desktop surfaces: readable text,
  touch targets, safe areas, aspect ratios and input prompts. Choose orientation
  policy deliberately. Do not bake key labels into artwork or change world
  geometry to fit a smaller screen.
- Test native audio mixing, voice/effects/music, mute preservation, audio
  interruptions, headphones/device loss and recovery. Before every automated
  native launch verify music and effects muted; any audible acceptance test
  needs a separately agreed safe session.
- Test suspend/resume, OS termination and cold restart. Clear held input,
  retain owned pauses and avoid unintended elapsed-time catch-up, duplicate
  commands/rewards or corrupt saves. Suspension is not guaranteed time to save.
- Measure the whole application: PHP, transport, renderer, decoded artwork,
  audio and caches. Exercise worst-case concurrent scenes and sustained device
  load. An enlarged PHP memory limit or desktop CPU timing is not mobile proof.

**Exit:** the same playable slice passes the agreed budgets and correctness
checks on each initial target. Publish a concise compatibility decision for
Game/UI/Art: permitted formats, measured resource envelopes, layout/input rules,
required adaptations and remaining uncertainty. Only then lock platform-sensitive
production choices. Offline local saves are the baseline; cross-device cloud
saves, achievements and store purchases need separate scope and adapters.

### P3 - Harden desktop and mobile delivery

After P1/P2 settle the architecture, build reproducible per-target packages
containing the compatible PHP runtime, Engine dependencies, assets and native
components. Players must not install PHP, Rust, Composer, WSL/Termux, CLI media
players or development tools. The development renderer updater remains a
developer facility; player code/native updates follow the applicable store or
approved distribution channel, not source rebuilding during play.

Qualify native Windows first-class, macOS signing/notarization, and Linux
dependencies on clean systems. Do not count WSLg as native Windows or macOS as
Linux. Produce signed Android/iOS packages with current submission requirements,
including required native architectures, and exercise their actual install/update
paths. Bundle read-only content separately from writable settings and saves;
updates and uninstall behaviour must be explicit and preserve supported save
migrations. Review crash handling and diagnostics without exposing private
player data or requiring a developer console.

**Exit:** clean-machine/device install, offline gameplay, update, save migration
and regression evidence for every advertised target, plus submission checklists.
Local packages and internal testing are not store approval or release permission.

### P4 - Console access, feasibility and later delivery

Begin public constraint discovery in P0, not after finishing the game. Andrew
owns developer registrations, agreements and access decisions. Once authorized
SDK access is available, assess interpreter/runtime policy, C/Rust toolchains
and dependencies, GPU/audio backends, storage, account/controller lifecycle,
certification and platform-service requirements against the same matrix.
Keep confidential SDK evidence in approved private locations, never public docs.

Run P1/P2 equivalents on development hardware before promising a console port.
If the current PHP/runtime or renderer is not viable, surface that decision
early; preserving shared authored content does not prove the implementation can
ship unchanged. Later implement and certify only the approved port. Console
uncertainty must not be hidden behind a completed Steam or mobile release.

### Coordination and decision gates

Engine owns the matrix, cross-platform ownership contracts and consolidated
decisions. Renderer owns backend/transport proofs; Console owns developer
tooling and package assembly; Game owns the representative slice and outcome/save
checks; UI/Art apply the measured presentation envelope. These are proposed work
boundaries, not a statement that owners have been dispatched or started ports.

The first execution brief should authorize P0 and the bounded P1 proofs, not a
full mobile/console framework. Work stops for a decision only at an actual
architecture, access, purchase or scope boundary; independent authorized work
continues. Report what was tested, hardware/OS, build refs, unresolved blockers
and the next action together in the coordinator. Do not make Andrew reconstruct
status from several tasks. Before v0.6.0 release preparation, report P0/P1 status
and unresolved platform-sensitive decisions; a desktop development release must
not be described as validated mobile or console support.

This plan does not start prototypes, change dependencies, authorize hardware or
SDK acquisition, promise simultaneous releases, or grant publishing permission.
Normal review, repository ownership and Andrew's publishing rules still apply.

### Platform sources

- [Steam platforms](https://partner.steamgames.com/doc/store/application/platforms)
  and [onboarding/review](https://partner.steamgames.com/doc/gettingstarted)
  define distribution setup; their broad platform lists do not qualify Ichiloto.
- [Steam content survey](https://partner.steamgames.com/doc/gettingstarted/contentsurvey)
  covers ratings, mature content and shipped generative-AI content disclosures.
- [Apple App Review Guidelines](https://developer.apple.com/app-store/review/guidelines/#software-requirements),
  especially 2.5.1/2.5.2, govern API use, sandboxing and executable-code delivery.
  They do not by themselves prove or disprove a bundled interpreter port.
- [Android GameActivity](https://developer.android.com/games/agdk/game-activity)
  describes native lifecycle/input hosting, and
  [64-bit requirements](https://developer.android.com/games/optimize/64-bit)
  apply to native distribution. Audit other current Play requirements in P0/P3.
- [GPUI upstream](https://github.com/zed-industries/zed/blob/main/crates/gpui/README.md)
  documents desktop platform setup. Community mobile work is a candidate to
  inspect, not part of the currently qualified renderer.
- [Nintendo Switch 2 access notice](https://developer.nintendo.com/web/development/home/developing-for-switch2)
  currently closes new access requests; recheck rather than assuming availability.
- [PlayStation partner process](https://sonyinteractive.com/en/news/blog/showing-your-game-to-playstation/)
  explains approval and agreement before development-resource access.

<a id="approved-battle-results-integration"></a>
## Battle Results integration

The shared award boundary captures immutable EXP, level, stat, learned-skill,
rolled-drop and actual inventory/gold-retention facts. Playback owns only reveal
timing, pages and completion: repainting, re-entry and skipping never grant
rewards. The existing full per-travelling-member EXP policy includes reserves.

An optional Results skin projects Primary, Level Up, Learned Ability and
explicit Special Reward events over the final battlefield. It does not infer
rarity. Terminal results expose the same facts and order with explicit overflow
pages. Menu portraits and dialogue busts are distinct roles consumed from their
existing shared role catalogs, not registered again for Results; missing
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

Game Over now projects the existing scene's Load Game, To Title and Exit
commands onto a centred graphical panel with shared theme frames and buttons.
Its title uses `vocab.game.game_over`; a project without menu artwork retains
the shared graphical default. Save availability, focus, confirmation and scene
lifecycle remain owned by the existing menu and commands. The logical canvas
fits native window resizes independently of terminal geometry. Unsupported
capabilities or an invalid theme retain the terminal presentation with
diagnostics for presentation errors; terminal rendering itself is unchanged.
No new background artwork or gameplay state is required by this adapter.

Main Menu, Equipment and Status project existing state. Character focus covers
the entire card interior without a per-name cursor. Order's source mark remains
distinct from destination focus. Location and help share a measured bottom-row
height, growing together for long content. Four normal cards fill the current
party viewport; a partial last page keeps their sizes rather than stretching.

Equipment-slot icons belong to the slot and current character compatibility,
not the equipped item. A sole allowed weapon family or a compatible authored
basic weapon style supplies a stable type icon; otherwise the slot retains its
semantic icon. Shield, headgear, body armor and accessories use their shared
category icon, not a compatibility-family variant. Multiple accepted families are
listed rather than selecting the first arbitrarily. Every selected slot shows
its existing role-derived compatibility or accessory category in the shared
Info panel, and the graphical candidate header retains that guidance. Candidate
filtering uses `Character::canEquip` plus the selected semantic slot for every
category. `EquipmentIcon` owns canonical type roles (`weapon.sword`,
`slot.shield`, etc.) and terminal symbols. Weapon family determines a weapon's
visual type; other equipment uses its semantic category. Armor classifications
such as General Armor and Magic Armor govern role compatibility, not which
picture depicts a shield or helmet. All items of a visual type use the
same theme artwork in inventory, shops, checkout and candidate lists, and the
same symbol in Native Terminal. Independent armor-slot variants and equipment
per-item icon overrides are removed; legacy authored fields are preserved for
safe source round trips, not runtime overrides. Untyped legacy gear uses its
semantic slot, never a name-based type guess. Missing type artwork retains its
canonical symbol rather than borrowing another type's image. Replacing artwork
does not require hashes or copied dimensions. Consumable icons remain authored.
No inventory, stats or restrictions are changed by these projections. Last
Legend already binds all four weapon families it uses and all non-weapon
equipment categories; no new armor-family art or gameplay reclassification is
needed to implement type icons.

Fresh type-icon and menu regressions pass 182 tests with 3,549 assertions,
including Native Terminal labels and graphical symbol fallback. This removes
item-specific equipment icon variation rather than changing consumable icons.

`MenuPagination` measures stable, complete-record pages from the start of the
list. Main Menu replaces its focus-following sliding range and floating range
counter with these pages and a themed Previous / Page N of M / Next pager inside
the help footer. The pager does not subtract a counter row from the card viewport.
Wrapped names, role values, help and hints are measured before paging; an
unsupported oversized record is diagnosed, never clipped or silently shrunk.
Rendering only projects the existing PHP owner's selected/marked records.
Semantic page actions browse without changing the selected command or party
membership; vertical selection crosses pages naturally. Cross-page ordering
retains the marked source, and Equipment/Status return remembers character
identity even after character cycling or reordering. The terminal workflow is
unchanged. Hidden hints do not hide the pager. Pointer/touch activation and
physical controllers remain input-backend gaps, not implemented pager features.

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

### Shop

Shop now uses the same optional menu canvas lifecycle and replaceable theme.
Its existing Info, command, balance, merchandise and possession panels retain
their hierarchy; no second shop controller or game-specific drawing is added.
Buy / Sell / Cancel use centred, steady buttons. Prices and possession values
remain right aligned, stock follows the existing PHP selection, and full Info
descriptions use the shared semantic reader. Quantity mode replaces the list
content in place, and supported alerts retain the themed shop beneath them.
Checkout display now rounds the total once, matching the existing transaction
charge rather than truncating it. Selling the final eligible stack returns to
an empty sale list instead of passing a null description to Info. Missing theme
or renderer capabilities retain the terminal shop and its existing layout.

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
graphical GUI authoring of themes and shared artwork-role bindings, not a
mandatory graphical workflow in the terminal editor. Runtime composition does
not establish authoring support. The [layered-tilemap correction roadmap](../layered-tilemaps.md#graphical-correction-roadmap)
owns the six-stage field-map work, concrete GUI gaps and removal of the
graphical-marker/crop workflow from the TUI while preserving terminal editing,
shared gameplay identity, existing data and source-safe round trips.

## Dialogue And Skits

Shared `DialogueSnapshot` composition now presents the current TextBox page,
typing cursor, speaker and Auto state without changing playback ownership.
`Data/Presentation/dialogue.php` binds a replaceable dialogue theme, explicit
speaker identities/aliases, portrait and bust roles by expression, and contextual
skit backgrounds. Ordinary field dialogue uses retained transparent
`canvas_overlay`; static skits use the existing exclusive canvas surface.
The field overlay paints after field/HUD without resetting the world or camera.
Removing it removes only canvas entities. Missing capability/artwork retains
useful terminal behavior, including above-menu fallback instead of hiding it
behind the old opaque canvas. Last Legend supplies artwork, not drawing code.

The stage displays title/location and contained borderless busts. Redundant
above-bust names and markers are removed; the dialogue names the active speaker.
Inactive artwork is slightly smaller and dimmer with alpha unchanged, providing
both size and colour emphasis through configurable shared skit styling.
`canvas_image_tone` negotiates RGB brightness; older backends retain size emphasis
and diagnose unavailable dimming. Casts larger than three use stable groups
containing the active speaker. PHP still owns voice, Auto, completion
and input. Lip/eye animation, Log/Skip, notifications
above opaque panels and GUI artwork-role authoring remain future work in
[the authoritative skit plan](../skits.md). A bounded silent renderer-only
preview tool is available; headless composition, native screenshots and full
gameplay acceptance remain distinct evidence.

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

The [input contract](input-sources.md#controller-ready-input-and-normalized-movement)
owns requirements. PHP owns semantic actions, binding contexts, movement and
timing; native sources report normalized key transitions and focus/reset events.

Delivered: the negotiated `key_transitions` subscription, bounded held state
in `InputManager`, RPG Maker MZ four-directional held walking (`PlayerWalk`)
paced by the one `FieldMetric`, and `field_motion` presentation, in which the
renderer slides field sprites between cells over each step's duration and
moves a following camera with the player on one clock. Terminal event-only
input, route timing and retained-cell composition are unchanged.

Remaining: one bounded native acceptance pass of held walking in the ordinary
Game; diagonal movement (four-directional movement is the current rule);
physical controllers and analog magnitude; enhanced terminal reporting; and
whole-game input/platform qualification.

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
The local G2 `BattleCommandPlayback` advances poses and effects through the
shared non-blocking session; it replaces battle's blocking command adapter.
The [runtime boundary](../effect-animation.md#local-battle-runtime-october-2026)
records current acceptance gaps. Production bindings, graphical-summon content
acceptance, attachments and bloom are not implied by that runtime.
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
  current cell-aligned slice. Staged actors, NPCs and the player slide between
  cells over their own step timing through `field_motion`; free subcell
  placement, precise attachment points, per-instance alpha and
  canvas-over-field composition remain separate gaps.
- Completion, legal skip, partial start, failure, transfer and shutdown release
  owned cast/bindings/effects. Preserve guards and deferred autosave. Test resize,
  re-entry, repeated NPC IDs across maps, pause, reduced motion and exactly-once
  outcomes; never undo legitimate story state.
- Real-subject movement needs map/session-bound transform recovery. Captured-
  entry walking/return must not become teleportation or per-start-cell scripts.
  Retry guards distinguish recorded choices from completed outcomes.
- GUI field-effect and cinematic previews need one seekable, isolated field-world
  session using the runtime interpreter, map presentation, camera and dialogue
  ownership. Terminal preview already runs silently in its isolated session;
  graphical field preview is implemented locally, with remaining representative
  native acceptance tracked in G4 above. Missing maps must show diagnostics,
  not an unrelated battle arena or an unexplained empty field. Replacing a
  preview host retires its renderer session even when the grid stays the same.

Remaining representative integrations follow the established ownership seam.
Runtime tests need ordinary, reduced-motion and legal-skip paths, matching
terminal presentations and scene-specific native observation. Source inspection,
headless tests and art previews are distinct evidence, not interchangeable
proof of complete gameplay or platform support.
