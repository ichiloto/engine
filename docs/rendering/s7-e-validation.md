# S7-E validation: presentation fidelity

**S7-E accepted as a renderer spike on 2026-09-12 following direct user
playtesting. No S8.** Known repaint/occlusion, richer input-repeat semantics and
additional native visual acceptance remain deferred follow-up/optimization items,
not solved or passed checks.

## User acceptance decision: 2026-09-12

The user reports: "I played the slice and there's marked improvement. This is a
spike so we can optimize later. Please proceed." This explicitly authorizes
finalization, one conventional commit on Engine `develop`, and a push after the
final non-native checks. No release branch or S8 work is authorized.

### Verified and accepted scope

- Protocol v2 Engine integration, with explicit v1 compatibility retained.
- Structured foreground/background colours, ANSI16/ANSI256/RGB extraction and style-only frame updates.
- Sparse layered Console presentation: world -> graphical sprite -> UI, opaque UI blank cells and graphical Player substitution.
- Expanded native key identities and frame-aware transient presentation before authored holds/removal.
- Console renderer selection, automatic GPUI startup through the Engine registry, the packaged-renderer resolution boundary and terminal fallback.
- The fixed Engine pre-sleep frame-delivery delay and initial latency diagnosis.
- Renderer native window fitting and resize transform, with fixed logical Camera/grid dimensions.
- Measured release-renderer performance improvement and release-build packaging guidance.
- Ordinary native movement/menu/input evidence, terminal regression evidence and automated/static validation, with the exact limits of each recorded below.

### Deferred, not passed

The following are **deferred after user acceptance of the spike**:

- Sustained stale-surface/repaint diagnosis, including possible macOS occlusion behaviour.
- Physical held-key/release gameplay characterization and richer input-event/repeat semantics.
- Eligible native T skit recheck and the exhaustive final C/M/T/Tab/Shift+Tab/F5 matrix.
- Real native battle popup timing, colour, removal and resulting HP/phase observation; terminal battle feedback recheck.
- Additional real-game resize recheck and further renderer optimization.
- Linux/WSLg and native Windows validation.
- Configurable window/fullscreen/scale policies.

The release renderer still produced a post-replacement stale/repaint symptom in
at least one acceptance run. Engine frame delivery and renderer snapshot
replacement were confirmed. macOS GPUI display-link suspension for occluded
windows is a source-supported hypothesis, but the exact cause was not proven.
The user accepted the current spike based on direct playtesting and marked
further optimization/investigation as follow-up work.

Engine still consumes one renderer identity per normal input iteration.
Synthetic/deterministic tests prove that FIFO backlog can occur when arrival
outpaces consumption. No arbitrary deduplication, repeat filtering, batching,
invented key-up or simulation replay was introduced. The user reports marked
real-play improvement with the release renderer; richer held/repeat semantics
can be revisited if actual gameplay warrants it. The FIFO limitation is not
claimed solved.

### Outside the completed slice

S7-E does not deliver tilemaps, graphical NPCs/battlers, dynamic logical
resolution, renderer-driven Camera resizing, a window preference system or a
rich key-up/repeat protocol. It does not establish cross-platform native
acceptance or guaranteed macOS occlusion/repaint handling.

## Final non-native validation: 2026-09-12

Run from the exact production/test code prepared for the S7-E commit, after the
user's acceptance. No additional native/manual test was launched.

| Check | Final result |
| --- | --- |
| Full Engine suite | 1250 passed, 1 existing skip, 4415 assertions; 8.66 s |
| SaveCompatibilityTest + SaveManagerTest | 14 passed, 77 assertions; 0.19 s |
| Explicit transport v1/v2, input, S4-S7 presentation, timing/transient, launch and error-handler subset | 402 passed, 1473 assertions; 6.39 s |
| PlayerPresentationConfigTest in isolation | 11 passed, 46 assertions |
| PHPStan, serial `--debug --no-progress --memory-limit=1G` | No errors |
| PHP syntax, all 800 src/tests/resources PHP files | Passed |
| All eight Console test scripts against local Engine, with S6 game fixture enabled | Passed, no integration skips |
| `git diff --check` | Passed |
| Renderer `cargo build --release --locked` | Passed; existing future-Rust compatibility warnings only |

The existing skip is `EnemyTest::it can create an enemy`. Counts above are the
actual final-run results; earlier assertion totals remain in their historical
run records. Console reflection resolved `Game` to the sibling Engine source,
not the vendored release. Console emitted two unrelated RVM sandbox `ps` warnings
but all eight scripts exited successfully.

Renderer is clean and live-remote synchronized at
`6556e414c819f4cbd71b0a9865c4e9824e111426`. Its release executable and the ignored
Engine installation both still match SHA-256
`eda9ec05b582bcbbe90a67b19910fdb7d6e4a0ec9f7907b013a58fa7ce7cbdd9`.
Console is clean and live-remote synchronized at
`4c45f3428214ec3737c9f24644c8c87813e9f4e9`. Neither repository was modified.

S6 Last Legend remains clean at `d1c1993c2f67773ce4b9b5cb1d60ff3d65d85eb0`, with
unchanged config SHA-256
`9979d045bdb88ad8308faf006b8072ea701c801704ceb09bf463c55cbee608db`.
The original game's user diff still hashes to
`21bff5439f7fed658f1320ee1a61119fa27953eabf55bee850c5c7cc44fc06c9`.
No renderer-specific game bootstrap, bindings, story fixtures or shared settings
were added. No production source depends on temporary evidence paths; local
renderer binaries/manifests remain ignored and outside the commit.

Final local automated logs are in `/tmp/ichiloto-s7-e-accepted-20260912/`.
They are evidence only, not committed artifacts or runtime dependencies.
The Engine commit message is
`feat(rendering): complete GPUI presentation fidelity spike`. The final delivery
report provides its SHA and confirms remote synchronization and worktree cleanup.

## Historical evidence boundary

The sections below preserve the original runs, including unsuccessful or
incomplete checks. Their "pending", "unaccepted" and commit-blocking statements
describe the decision at that time, not the current accepted-spike status.
Acceptance changes the disposition of those gates; it does not change their
observations or turn incomplete checks into passes.

## Release acceptance attempt: 2026-09-12

### Baseline and performance

Renderer develop is clean/live-remote synchronized at
`6556e414c819f4cbd71b0a9865c4e9824e111426`, including `b1a6425` and `e7f550d`.
`cargo build --release --locked` passes. Reviewed and Engine-staged binaries match
SHA-256 `eda9ec05b582bcbbe90a67b19910fdb7d6e4a0ec9f7907b013a58fa7ce7cbdd9`.
Use release executables for gameplay/performance acceptance and packaging; debug
executables are development/diagnostics only. Do not commit the local executable
or machine-specific ignored install manifest.

The earlier 100-185 ms debug foreground waits are closed by the renderer's matched
release experiment: accepted-to-replaced 0.015-8.807 ms, paint-stage elapsed
approximately 7.897-9.926 ms. These are not GPU/pixel-completion measurements.
Earlier ordinary single-input PHP acceptance median/max 13.069/23.047 ms and
acceptance-to-enqueue 3.874/5.239 ms retain their original run context. Structured
snapshots were roughly 2-2.4 ms. The separate proven Engine pre-sleep delivery
delay is fixed by a bounded zero-wait transport pass after changed-frame enqueue.

### Physical input and stale surface

Ordinary Console `play --renderer=gpui --no-tmux` used private muted project
`/tmp/ichiloto-s7-e-final-20260912/runtime`. No phpdbg, custom game bootstrap or
protocol key injection was used. New Game completed the authored Home prologue;
Player reached `(4, 5)`, event ownership false, queue empty before the manual gate.
The explicit two-minute one-second-Right-hold request received no physical
callbacks or release confirmation. All 13 recorded inputs preceded that request.
The physical gate is pending, not failed. No input-architecture blocker is proven.
Keep FIFO unchanged. The automated positioning burst reached depth 5/oldest age
98.724 ms; final depth 0, oldest age null. This is not held-release evidence.

Release target-window captures remained stale while PHP progressed. All 176
queued frames were received, accepted, submitted, dequeued and replaced. No
outbound Engine frame remained and diagnostics dropped zero records. There were
11 complete callback/paint occurrences across nine distinct snapshots. Frame 100
waited 28.064317 s after replacement for its callback; frame 130 waited 243.463056 s.
Final frame 176 was replaced but had no callback before shutdown ~1.783 s later.
Transport delivery is established; final visible-state acceptance is not.

The Renderer task independently measured received-to-accepted median/max
0.566/3.106 ms and accepted-to-replaced 0.055/1.313 ms. Observed handoff depth never
exceeded 1 of capacity 2. Paired paint-stage spans were 8.708-28.778 ms, not an
explanation for the multi-second gap before callback entry. No channel, repeat,
image-cache, polling or painting rewrite is justified by these observations.

Pinned GPUI 0.2.2 macOS code stops its display link for occluded windows; focus or
display events can produce one synchronous draw. This is a source-supported
**hypothesis**, not this session's proven cause. Native occlusion/platform frame
requests were not traced, and window-target captures do not establish desktop
visibility. The next distinguishing observation needs verified uncovered
foreground input/repaint versus an explicitly covered interval. Keep this
post-replacement symptom separate from the closed debug pre-replacement diagnosis.

### Regression work

Focused validation exposed a test-order dependency: Player presentation tests
omitted their required PlaySettings. Setup now supplies its own 20x10 settings;
existing ConfigStore teardown restores prior state. Isolation passes.

Direct terminal startup exposed a shared error-handler defect: suppressed
`/dev/tty` open warnings invoked Game's fatal handler before Console's existing
fallback could run. The handler now respects PHP's active error mask. Subprocess
tests prove both `@` and explicit masking continue without crashing, while an
unmasked warning still logs and exits 1. No renderer/sandbox-specific workaround.

| Latest check | Result |
| --- | --- |
| Full Engine after fixes | 1250 passed, 1 existing skip, 4417 assertions; 8.72 s |
| Explicit save/transport/input/S4-S7/timing/launch/error subset | 416 passed, 1554 assertions; 6.25 s |
| Player presentation isolation | 11 passed, 46 assertions |
| PHPStan serial/debug | No errors |
| PHP syntax, all src/tests/resources PHP files | Passed |
| git diff --check | Passed |
| All eight Console scripts with local S6 game fixture enabled | Passed, no integration skips |

Ordinary terminal launch loaded the existing private save into Town Center.
PTY output retained yellow selection, green vegetation and blue water. M opened
the map; C returned; Equipment Tab changed Kaelion to Liora, Shift+Tab returned to
Kaelion; F5 quick-saved; Q/confirm exited 0. No GPUI/audio child was present.
After the handler fix, direct `php last-legend.php` reached the title and exited
0 via Exit. Real terminal battle feedback was not exercised in this attempt.

Terminal/direct stty states differed only by macOS PENDIN (`0x20000000`, pending
input state). An engine-free `stty cbreak -echo`, one-byte read, restore sequence
reproduces the identical difference. Echo/canonical configuration is restored;
byte-identical terminal state is not claimed for those runs. Native stty states
were byte-identical.

### Cleanup and commit decision

Native close exited the wrapper/Console chain 0 with empty error.log at close,
shutdown anchor and zero-drop summary. PIDs 69835 (wrapper), 69838 (Console),
69851 (shell), 69852 (Game), 69862 (GPUI) were absent afterward. Audio was muted;
no audio process was present. The normal window-close action follows the
renderer's close_requested path, but this trace does not independently log that
event or a separate renderer exit code. Process/terminal cleanup is observed.

Console is clean/live-remote synchronized at `4c45f3428214ec3737c9f24644c8c87813e9f4e9`.
S6 game remains clean at `d1c1993c2f67773ce4b9b5cb1d60ff3d65d85eb0`; config SHA
`9979d045bdb88ad8308faf006b8072ea701c801704ceb09bf463c55cbee608db` is unchanged.
The original game's user diff hash is unchanged. No shared source, config,
bindings or saves were edited; no renderer-only gameplay fixture was added.

Remaining gates: physical held/release decision; eligible native T skit; final
native C/M/T/Tab/Shift+Tab/F5 matrix; real native damage text, red colour, authored
hold/removal/HP result and observed battle phases; verified-visible stale-surface
diagnosis; real-game resize recheck; terminal battle feedback; and final post-fix,
post-acceptance native cleanup and full checks. Earlier renderer resize acceptance
remains valid (fixed logical grid, downscale, 1x cap, centered larger viewport,
no PHP/protocol resize), but is not this unperformed real-game recheck.

All test windows are closed; no user input is outstanding. Engine develop stays
uncommitted. No push, release branch or S8 work. Evidence root:
`/tmp/ichiloto-s7-e-final-20260912/`; raw native trace is
`runtime/logs/latency.ndjson`, analysis `evidence/native-analysis.json`, current
automated logs `evidence/*-final.log`. Temporary evidence is not a dependency.

## Preserved baselines

- Engine: local `develop` at `922c7e63e766f06bea7281ae25f82b94b47fe06f`, initially
  clean and two commits ahead of origin. This includes the music integration
  following published S6 `c794ef5d66781df8bde13a56ce56ee2e6069a936`.
- Renderer: clean `develop` at
  `52951b45d04d70180d60a0507b28b1dcd3f972b9` (S7-R). An explicit S7-E
  `cargo build --locked` passed (0.89 s). Cargo reports existing future-Rust
  compatibility warnings for `block` and `proc-macro-error2`, not build failures.
  No renderer source changes belong to S7-E.
  Debug and native-app-bundle executables are byte-identical, SHA-256
  `e68e8e9da7daa47bb107698bc93a97d65ad468d940adf8af694be878fded5c02`.
- Last Legend: `examples/last-legend-s6`, clean local `develop` at
  `d1c1993c2f67773ce4b9b5cb1d60ff3d65d85eb0`, preserving music integration after
  published S6 `c1ed9b0b971ad9686569d46aa5a7071ab79e414e`.
- The original `examples/last-legend` feature branch and user config are untouched.
  Original config SHA-256:
  `77a0b26533c8246062182748e46bed4bd651ae24bc1b43ebbebbeb1e6b8177f4`.
  S6-worktree config SHA-256:
  `9979d045bdb88ad8308faf006b8072ea701c801704ceb09bf463c55cbee608db`.

The attached S7-E brief, engine AGENTS.md, S2-S6 code/records and S7-R contract
were read before implementation. No game source changes are required to select
v2: the existing GPUI launcher does not pin v1.

## Automated evidence

Apple Silicon macOS, PHP 8.5.10, Pest 5.1:

| Gate | Result |
| --- | --- |
| Before, first full run | 1138 passed, 1 failed, 1 skipped; existing randomized SkillTest damage assertion expected 430, got 372 |
| Before, repeat without edits | 1139 passed, 1 skipped, 3936 assertions; 8.03 s |
| After, final full suite | 1205 passed, 1 skipped, 4223 assertions; 8.85 s |
| Explicit SaveCompatibilityTest + SaveManagerTest | 14 passed, 77 assertions; 0.20 s |
| Full PHPStan | No errors using `--debug --no-progress --memory-limit=1G` |
| PHP syntax, all src/tests files | Passed |
| `git diff --check` | Passed |

The renderer-selection follow-up adds a new baseline and final run below;
the table above preserves the earlier presentation-fidelity measurements.

PHPStan's ordinary parallel invocation was denied its local listening socket by
the sandbox. Its supported debug/serial mode completed analysis without changing
configuration or disabling checks. No separate Linux execution was performed.

Local logs: `/tmp/ichiloto-s7-e-before.log`, `-before-repeat.log`, `-full-3.log`,
`-full-final.log`, `-save-tests.log`, `-phpstan.log`, `-syntax.log`. These are evidence artifacts,
not runtime dependencies.

### Contract and behavioral coverage

- Real PHP-only peers cover v1/v2 hello, ready, frame, key, close, error and
  shutdown. Both directions of mixed-version application traffic are rejected.
  Large-message backpressure verifies byte hashes/order in both versions.
  A v1 pre-ready error can reject v2 startup, but v1 keys and post-ready errors
  cannot enter that session. Existing diagnostics/partial-I/O/cleanup tests remain.
- Explicit v1 and v2 runtime lifecycle tests run through a real PHP child.
  V1 still emits plain `text`; v2 emits `textLayers`. Runtime defaults to v2;
  low-level session/message construction defaults to v1.
- Immutable colour/run/layer/frame/snapshot tests cover typed ranges, UTF-8,
  controls, grid fit, aggregate bounds and caller reference isolation.
- Canonical extraction covers ANSI16, legacy intensity, bright backgrounds,
  ANSI256 endpoints, RGB including zero components, Symfony true colour,
  0/22/39/49 resets, blink-plus-colour and malformed extended colours.
- Colour-only changes enqueue frames, including retry after failed send. Layer
  identity, priority, run ordering and sprites participate in suppression.
- Sparse provenance tests cover world underlay, excluded Player, HUD/modal
  priority, explicit three-space opaque UI runs, transparent holes, same-glyph
  writes, anonymous invalidation, wide/multi-row footprints, nested same-layer
  scopes, clear/resize and recomposition rollback. All S6 masking tests remain.
- Actual Player/runtime composition retains the terminal glyph but sends its
  PNG with the field action prompt and modal above it. Generic sprite DTOs
  allow signed i32 layers; automatic Game composition reserves UI ranges.
- V2 C/c, M/m, T/t, Tab, Shift+Tab and F5 pass through RendererClient,
  RendererInputSource and InputManager to existing key/binding queries. Tests
  distinguish letter case without adding production action bindings.
- Temporal action tests use production popup storage/formatting/drawing and the
  real ActionExecutionState stat-change path. Presented structured frames contain
  damage `48` in ANSI16 9, healing `+48` in 10 and MP `-48 MP` in 14 during the
  hold; a later frame no longer contains the popup. Announcement/Turn-over state
  is observed before its clear, not merely mocked as a method call.
- Focused temporal tests cover AnimationPlayer's three frames, summon frame/cue
  order, all transition shades at layer 3000, BattleStart intro frames, direct
  modal/select/typewriter content before dismissal, and update/draw/present order.
  UIManager priorities and NotificationManager's existing render boundary are
  also exercised. Blocked ticks do not recursively update scenes.

## Production sleep audit

All production `usleep()` calls were inspected individually. Locations below
name methods rather than unstable line numbers. Tests and deterministic process
peer delays are not production presentation pacing.

| Location | Old behavior | Classification | Changed? | Reason |
| --- | --- | --- | --- | --- |
| ActionExecutionState::pause | Bare delay after actor/action/popup phases | Visible authored hold | Yes: Timers::wait | Present every shared phase before it can be erased; timing values and battle rules unchanged |
| AnimationPlayer::play | Draw frame, sleep, advance | Frame-based visual playback | Yes: Timers::wait | Every PHP-selected frame reaches presentation; same FPS/duration |
| SummonCutscenePlayer::play | Draw/cue, sleep, advance | Frame-based visual playback | Yes: Timers::wait | Preserve intermediate frames and cue order |
| ScreenTransition::play | Draw shade, sleep | Transition cover | Yes: Timers::wait | Present cover above UI; convert the same authored duration to seconds |
| BattleStartState::playIntroAnimation | Clear/draw intro, sleep | Battle-intro frame | Yes: Timers::wait | Present intro before next clear |
| Modal::open | Blocked tick, draw modal, sleep | Blocking UI/typewriter loop | Yes: Timers::wait draw callback | Background update precedes fresh modal drawing, presentation follows it before sleep |
| SelectModal::open | Handle input/update selection, sleep | Blocking selection loop | Yes: Timers::wait draw callback | New selection becomes visible before another blocking input step |
| Game::run | Main-loop frame sleep | Main pacing | No | Normal render/presentation already precedes this delay |
| Game::showSplashScreens | Terminal-only splash delay | Terminal fallback | No | Graphical runtime already takes the frame-aware Timers branch |
| Console::writeToTerminal | Retry a stalled terminal write | Output backpressure | No | Not a complete-frame boundary; recursively presenting here is unsafe |
| TerminalInputSource::readInputSequence | 1 ms input-byte assembly | Low-level input I/O | No | Must collect one terminal escape sequence, not update/present the game |
| Timers::wait | Internal frame sleep | Frame-aware pacing | Retained, bounded by remaining hold | Update/draw/present occurs before it; short authored holds are not inflated to a whole frame |
| TitleScene::start | 300 microseconds between header and menu construction | Sub-millisecond construction staging | No | Not a deliberate visible animation hold; completed title is presented at the normal boundary |
| TitleScene::resume (two calls) | 300 microseconds before/between title reconstruction | Sub-millisecond construction staging | No | Avoid presenting partial reconstruction; no gameplay/transient state disappears in these delays |
| GameOverScene::start | 300 microseconds between header/menu construction | Sub-millisecond construction staging | No | Completed scene is presented at the normal boundary |
| GameOverScene::resume (two calls) | 300 microseconds before/between reconstruction | Sub-millisecond construction staging | No | Not authored animation timing; partial window construction is not an acceptance frame |

`Timers::setFrameTick()` now accepts an optional completion/presentation phase.
Wait order is background update, optional caller draw, presentation, bounded
sleep. The final caller draw is presented without another simulation update.
Public Game::tickWhileBlocked still performs update and presentation together.

## Native Last Legend evidence

The renderer task exclusively owns native input and captures. A private project
at `/tmp/ichiloto-s7-e-native-project` uses the real assets/vendor and the
byte-identical existing launcher. Only private settings/saves differ: audio is
muted (`master_volume=0`, music/SFX false), normal slow battle pacing selected,
fresh gameplay saves. Shared game configuration and source are not edited.
Geometry is fixed at 135x36, cells 10x20.

### Established gates

- Title and game-authored yellow selection text appear. Default-v2 startup is
  established by the unchanged launcher/configuration and deterministic real-peer
  lifecycle tests; this launcher has no raw IPC trace, so native wire capture is
  not claimed. S7-R independently validated native v2 emission.
- Home movement, four directional PNGs and blocked-wall facing retain S6 behavior.
- Real quit-confirm modal at Home (8,5) paints its border over the lower PNG while
  the uncovered portion remains visible. At (8,7), blank interior cells occlude
  the body fully. Evidence: `/tmp/ichiloto-s7-e-modal-before-8-5.jpg`,
  `-modal-border-8-5.jpg`, `-modal-blank-before-8-7.jpg`, `-modal-blank-after-8-7.jpg`.
- Native C cancels the modal; M opens the real map and C returns; F5 displays
  Quick Saved and creates the private quick-save file. Captures:
  `/tmp/ichiloto-s7-e-M-map.jpg`, `/tmp/ichiloto-s7-e-F5-quicksave.jpg`.
- Two native-close passes exited 0, restored identical stty state, left no
  PHP/renderer/audio child and preserved all 41 original config/input/launcher/save
  hashes. Second-pass audit: `/tmp/ichiloto-s7-e-recheck-cleanup.json`.

### Open investigation and remaining gates

Initial native tests reported a stale Equipment surface while terminal output
and native key handling continued. A fresh run could remain on Continue even as
PHP entered Home/menu/Equipment. Renderer sampling showed an alive stdin reader
waiting for bytes, not an active paint loop or EOF; all paired pipe descriptors
were open. PHP sampling showed continued snapshot-related work and normal sleep.

An unchanged private launcher under phpdbg did not immediately reproduce the
stall. At Equipment, the live canonical snapshot differed from the last frame;
v2 frame 13 encoded to 8729 bytes, queued successfully, drained to zero pending
bytes on the next iteration, and appeared natively. Evidence:
`/tmp/ichiloto-s7-e-debugger-after-equipment-send.jpg`. Debugger pauses are not
authored-timing acceptance. No speculative production fix was made from stale
screenshots alone. With breakpoints removed, native Tab displayed Liora/Oracle,
Shift+Tab restored Kaelion/Vanguard, and Escape returned to Home. Native T then
opened the real Breakfast Banter skit after Mother's quest dialogue. Town green
trees, cyan labels/NPCs and blue water remained coloured. Captures:
`/tmp/ichiloto-s7-e-debugger-Tab.jpg`, `-debugger-ShiftTab.jpg`,
`-debugger-T-skit.jpg`. These establish real game behavior under the debugger,
not ordinary-CLI timing or a fix for the earlier intermittent stall.

The earlier stale CLI sessions used a PTY with stdout/stderr redirected to regular
log files, `php tools/gpui-last-legend.php --renderer=<bundled executable>`, no
per-launch environment overrides. Debugger output remained on its PTY. Neither
opcache CLI nor JIT was enabled. Independent global foreground/unoccluded state
was not captured for the stale sessions; future repro must check this explicitly
rather than equating AX key delivery with native redraw visibility.

The Mac locked during the normal shop errand. No further native UI actions were
attempted. All four genuine private quick/auto saves were preserved byte-for-byte
under `/tmp/ichiloto-s7-e-checkpoints/at-screen-lock/manifest.json`; no save payload
was edited. Latest `auto-03.iedata` SHA-256:
`5dc4f49b69f921ab4b2527434a3d66fc9f1beb21dc0702632a54d1da2d741995`.
The earlier after-skit checkpoint is also retained. The muted debugger was stopped
with Ctrl-C and emitted terminal restoration; its exit 1 is an interrupted
diagnostic run, not a native-close acceptance result.
Read-only cleanup verified no remaining game, phpdbg, GPUI or audio process and
all 41 original file hashes unchanged. Audit:
`/tmp/ichiloto-s7-e-at-lock-cleanup.json`.

Pending after the user unlocks: resolve/recheck stale-surface behavior, repeat
T/Tab/Shift+Tab with the ordinary launcher, real
battle popup duration/colour/removal, other explicitly observed battle phases,
final native close and ordinary terminal regression. Do not infer these from
passing unit tests or from earlier S6 acceptance.

## Renderer-selection follow-up

Console `develop` at `4c45f3428214ec3737c9f24644c8c87813e9f4e9`
(`feat(play): add renderer selection`) is now consumed by Engine. Console source
and its public flags were not changed during this follow-up.

`RendererLaunchIntent` captures `ICHILOTO_RENDERER` once at input-session startup.
Trimmed/lowercased `terminal` selects the built-in path; absent/empty values also
mean terminal. `gpui` selects the registry's factory, which builds the existing
process-backed runtime using project assets, resolved Game grid, 10x20 cells and
v2. Unknown IDs report the ID and both registered choices; unavailable/malformed
installations produce `RendererUnavailableException`, not terminal fallback.
An explicit `useRendererRuntime()` is authoritative and never creates a second
runtime, even with an invalid environment ID. Selection is before STDIN ownership.

The injectable `PackagedRendererExecutableResolver` owns one internal manifest
boundary. The validated macOS app was copied into the ignored installation
directory, with only a darwin-arm64 manifest entry. No binary or machine-specific
manifest belongs in a source commit. Its SHA-256 remains
`e68e8e9da7daa47bb107698bc93a97d65ad468d940adf8af694be878fded5c02`.
See [runtime](runtime.md) and the [packager boundary](../../resources/renderers/README.md).
There are no public executable-path flags/environment variables, renderer
downloads, Console runtime construction, or new protocol/resize features.

### Automated follow-up gates

| Gate | Result |
| --- | --- |
| Before renderer-selection edits | 1205 passed, 1 existing skip, 4223 assertions; 14.35 s |
| Focused launch/runtime tests | 50 passed, 198 assertions; 0.85 s |
| Full Engine suite after selection | 1237 passed, 1 existing skip, 4332 assertions; 9.82 s |
| Console suite against updated Engine | All 8 scripts passed, game-backed validation/battle integrations enabled |
| Full configured PHPStan | No errors, supported serial/debug mode |
| PHP syntax, all src/tests files | Passed |
| `git diff --check` | Passed |

The 32 new launch cases cover absent/empty/case/whitespace intent, the invalid
identity `0`, registry identity/uniqueness and factories, GPUI transport/cell
defaults, unavailable/malformed/platform-missing manifests, packaged executable
permissions and confinement, automatic attachment, explicit precedence, input
ownership, one-time intent capture after failure, terminal behavior, and
idempotent shutdown. Tests restore the previous environment and input state;
no unit test starts the real Rust renderer. Non-TTY terminal-mode checks emit
the existing stty diagnostics; real PTYs independently cover the actual modes.
Console's retained login-shell test wrapper emits the local RVM/ps sandbox
warning. Neither warning represents a failed test. No separate Linux execution
was performed.

Logs: `/tmp/ichiloto-s7-e-selection-before.log`, `-focused.log`, `-full.log`,
`-phpstan.log`, `-syntax.log`, and `-console.log`. A direct ordinary bootstrap
with `ICHILOTO_RENDERER=foo` also exited 1 and logged the unknown ID and valid
IDs via the existing Game crash path, before input ownership was claimed.

### Console end-to-end acceptance

All launches used the ordinary, byte-identical `last-legend.php`, not the
temporary `tools/gpui-last-legend.php`. Private copies kept audio muted and
original source/config/saves untouched. Console ran with `--no-tmux` in a real
135x36 PTY, preserving ordinary Game auto sizing without adding project config
or renderer-path options. GPUI's fixed grid was therefore 135x36 at 10x20 cells.

| Selection | Observed result |
| --- | --- |
| `--renderer=terminal` | Full terminal title; Up selected Exit, Enter exited 0 |
| Interactive Native Terminal | Actual selector, ordinary terminal title/input, Exit returned 0 |
| `--renderer=gpui` | One staged native app, full title, native Down selected Load Game, native close exited 0 |
| `--gpui-renderer` | Identical GPUI startup/input/close result, exit 0 |
| Interactive GPUI | Real selector Down/Enter, then identical native startup/input/close, exit 0 |

The renderer task explicitly raised and clicked the native titlebar before
capturing input response. Process trees established Console -> ordinary game ->
one staged GPUI child. All three native routes left no game/renderer/debugger/
audio process and made no new error-log writes. Explicit GPUI and alias restored
byte-identical stty values. Both terminal routes and interactive GPUI restored
all mode values with only macOS's transient PENDIN state bit changed
(`lflag` XOR `0x20000000`). A standalone stty cbreak/echo/byte-read/restore sequence
reproduced that exact difference without PHP or Engine. No speculative terminal
change was introduced for it.

Native evidence: `/tmp/ichiloto-selection-native-report.md`,
`/tmp/ichiloto-selection-summary.json`, per-route `.launch.zsh`, `.pty.log`,
`.stty-before`, `.stty-after`, `.exit`, `-title.jpg` and `-input.jpg`; prompt output
is `/tmp/ichiloto-selection-interactive.prompt.txt`. Evidence/source hashes are
in `/tmp/ichiloto-selection-evidence.sha256.json` and `-hashes.json`. These are
PTY/process/UI records, not a captured wire trace. Terminal evidence includes
`/tmp/ichiloto-s7-e-selection-terminal` and its adjacent stty/exit records.

All 41 original game-file hashes remain unchanged. The ordinary bootstrap hash
is `849454178c53b4261b60686e742504da8f507eb87f6a9e4564030fbd00b25e5b`.
Renderer and Console remain clean at their recorded commits; both original and
S6 Last Legend checkouts retain their prior state. No game-specific renderer
boot code, original save changes, or vendor edits were required.

This follow-up accepts selection/startup/input/close only. It does not close the
older battle timing, T/Tab/Shift+Tab repetition, or stale-surface investigations.

## Input-latency follow-up, 2026-09-12

The user reported noticeable input lag during real GPUI gameplay. No single cause
is assumed. At the start of this follow-up, renderer viewport/resize work had not
landed and the Engine-staged binary was still the S7-R baseline. The resulting
renderer commits and ordinary-CLI native checks are recorded below; phpdbg is not
used for final native acceptance.

### Method and deterministic findings

Opt-in `ICHILOTO_ENGINE_TRACE=1` adds process/iteration/input-correlated monotonic
observations. The input FIFO retains immutable RendererEvent objects, with trace
metadata in a weak map rather than protocol fields. Tests inject a deterministic
clock; no CI threshold depends on wall-clock speed. A real PHP child verifies
transport parsing through KeyboardEvent dispatch. Trace-disabled calls do not
read the clock or create logs. Runtime documentation describes all stages.

At a simulated 40 ms consumption interval, ten Right identities received at once
take 360 ms from the first to last consumption after the producer stops. Three
Rights followed by Enter delay Enter by 120 ms. All identities remain ordered,
and exactly one is consumed per normal input iteration. C/M/T/Tab/Shift+Tab/F5
are covered. This proves the backlog mechanism, not its frequency in native play.

Terminal input has the same one-key-per-poll contract and previous/current rules.
Adjacent identical samples dispatch KeyboardEvents but do not generate another
`isKeyDown` edge. Field axes and many menus query those edges during a single
gameplay update. Draining a batch into events while exposing only its last key
would lose ordered polling-based actions; replaying updates would violate the
one-update-per-frame constraint. No batching, arbitrary deduplication, repeat
filtering, key-up invention or binding changes were made. If native evidence
requires batching, this event-versus-polling conflict needs an explicit design,
not a RendererInputSource special case in gameplay.

### Confirmed delivery fix

Previously `RendererRuntime::present()` pumped before snapshot generation, then
queued its completed frame and returned. The next transport pump occurred only
after Game's frame sleep (about 16.67 ms at default60 FPS), or a wait boundary.
A real-child regression test left all418 bytes of a completed small v2 frame in
the outbound buffer when `present()` returned. It failed before the fix.

Changed frames now get one bounded zero-wait I/O pass immediately after enqueue.
The regression passes with zero bytes pending for that writable small frame.
A16-byte budget test leaves a remainder and drains only16 bytes on the next pump,
proving that delivery does not wait indefinitely or remove backpressure. Runtime
now drains already-pumped lifecycle events without performing a second implicit
poll. Unchanged frames use only the normal ingress pump, with no duplicate
snapshot/presentation. Full update cadence, authored waits and FIFO order are
unchanged. This removes a confirmed avoidable presentation delay; it does not
yet establish the cause of all perceived lag or prove a visible native repaint.

### Real-scene CPU profiling

The diagnostic harness `/tmp/ichiloto-engine-presentation-profile.php` uses the real
Last Legend bootstrap classes, project data, Game updates, UI, battle and runtime
composition, but fake IPC. Only a private muted project copy was used:
`/tmp/ichiloto-s7-e-latency-profile-project`. No shared game source/save/config
was changed. The final sample finishes the authored introduction via ordinary
Enter/update ticks before measuring Home movement. Earlier samples taken while
the story owned input are not evidence of free movement.

40 samples per scene,135x36. Home alternates Right/Left and demonstrably moves
between(8,4) and(9,4); main menu alternates Down/Up; Equipment alternates
Tab/Shift+Tab; battle uses the real Rat+Bat configuration and ordinary updates.
Battle initialization/state waits can add presentation passes. No native renderer,
physical key arrival or pipe throughput is measured by this harness.

Median times in milliseconds; maxima are retained in the raw artifact:

| Stage | Home | Main menu | Equipment | Battle |
| --- | ---: | ---: | ---: | ---: |
| Complete measured update/render work | 3.239 | 5.101 | 11.777 | 5.421 |
| Game update | 0.486 | 2.759 | 9.389 | 2.615 |
| Structured snapshot total | 2.051 | 2.292 | 2.221 | 2.395 |
| Console cell/provenance extraction | 1.329 | 1.651 | 1.583 | 1.602 |
| Run coalescing including style parsing, per layer | 0.601 | 0.619 | 0.612 | 0.641 |
| Style/scalar parsing, per layer | 0.015 | 0.044 | 0.041 | 0.042 |
| Sprite collection | 0.011 | 0.001 | 0.002 | 0.001 |
| Duplicate comparison | 0.005 | 0.001 | 0.002 | 0.006 |
| Encoding real cached payload | 0.012 | 0.020 | 0.017 | 0.021 |

Home has two measured text layers; other scenes have one in these samples. The
run timing includes style parsing and should not be added to it. Message DTO
preparation adds roughly0.007-0.011 ms median. Equipment cycling update reached
22.131 ms maximum; battle update reached112.898 ms with authored state waits.
These need native context before attributing them to interactive lag. No snapshot
cache/style optimization was introduced merely from these small CPU costs.

Raw accepted profiling evidence: `/tmp/ichiloto-engine-presentation-active-field.json`.
Earlier idle/intro-controlled samples: `/tmp/ichiloto-engine-presentation-before.json`,
`-active-before.json`, and `-active-state.json`. Their separate status is deliberate.
Transport encoding/queue/drain instrumentation is implemented. Real-game pipe
timings from the subsequent native run are recorded below; this fake-IPC profiling
harness is not presented as that evidence.

### Renderer coordination and native status

The renderer viewport/latency baseline landed on `develop` and `origin/develop`
at `b1a64256f22a34027e5b7328db27dd050f27b41f`
(`feat(renderer): fit native viewports and trace input latency`). Final debug
binary SHA-256 is `b5de5b6dbbd94c463a61cde31064fdb1bf9438abe69d23d1bc50465776ca4798`.
Final review independently reran all52 Rust tests and verified the evidence
checksums; the renderer reports13 native smoke cases and four interactive fixture
sessions passing with normal close/exit0 and zero dropped diagnostics. At that
review the Engine-installed bundle was still unchanged; the later diagnostic
build installation is recorded below. These are renderer fixture results, not
the pending ordinary-game acceptance.

Renderer evidence reports native callback-to-stdout-flush median/max:
single Right2.183/2.183 ms; Enter0.076/0.076 ms; rapid Rightx10 0.611/5.356 ms.
The rapid interval observed59.409 arrivals/s, peak pending queue1, and at most2
submission-through-flush events including the in-flight write. This is not a
sustained writer backlog. The user then confirmed a physical Right hold: IDs26-84,
59 events over5.230 s, callback-to-flush median0.203 ms/max2.788 ms, no pending
queue in the observed snapshots, and at most1 submission-through-flush event.
All held events also report `is_held=false`: the renderer task traced this to
pinned GPUI's macOS special-key input-context path constructing events with that
value. The first repeat gap was477.921 ms, followed by roughly83.4 ms spacing.
Physical user confirmation and the arrival cadence establish this held sample;
the metadata does not distinguish it from discrete taps. No repeat filtering or
dependency patch was introduced. These fixture observations do not establish
in-game movement/release behavior or end-to-end latency.
These are renderer-owned fixture results, not end-to-end Engine latency evidence.

Renderer tracing currently uses process-relative Instant timestamps. The installed
PHP8.5.10 headers were preprocessed with `cc`/`php-config`: its `hrtime` branch is
`clock_gettime_nsec_np(CLOCK_UPTIME_RAW)`, not the older mach_absolute fallback.
The separately reviewed diagnostic follow-up landed at
`e7f550d221a412fc34f46b92293653db7d161c20`: bracketed monotonic clock anchors and
frame receive/accept/replace/render-callback observations, without protocol changes.
All55 Rust tests passed in the independent review; final evidence checksums passed.
A callback is not physical display visibility; screenshots/focus state remain
necessary. The ignored Engine-installed executable was refreshed to its exact
SHA-256 `4ee59a0e0f34ec776af97120631ea4aea3c09de4d8a3786d4a8c664d4a824a58`.

The first ordinary CLI attempt from the execution sandbox failed during native
application initialization (2026-09-12 06:52:01 +0200, PHP14580, renderer14605,
exit134). Stderr reports an invalid connection to `com.apple.hiservices-xpcservice`.
The user's attached crash report independently matches those PIDs/time and shows
SIGABRT in HIServices `_RegisterApplication` / `NSApplication sharedApplication`,
before window creation. An approved outside-sandbox retry of the same binary
opened the title screen (PHP15281, renderer15319). Record the failed sandbox launch
separately from gameplay/stale-surface evidence; no renderer workaround was added.
Raw logs are in `/tmp/ichiloto-s7-e-ordinary-latency/logs`; this is a separate muted
copy, and the original game source/configuration/saves remain untouched.

### Ordinary native Home and menu pass

The approved retry subsequently completed the authored Home intro using native
Enter input. Traces confirmed `event_owns_input=false` before movement sampling.
The titlebar was clicked to activate the unoccluded window. CUA generated discrete
keys only; this pass is not physical held-key evidence. All67 renderer-written
identities matched67 parsed and consumed PHP keys in order. All236 queued frames
reached the renderer, with no pending outbound frames at shutdown and zero reported
renderer diagnostic drops. Matching startup/shutdown anchors yielded the offset
interval `[146236290722166,146236290722707]` ns (541 ns wide); midpoint values below
do not imply that timestamp precision proves physical display latency.

Twenty individual Right presses, with Left resets between them, each changed
Home player state from(8,4) to(9,4). Native screenshots in the task history show
the corresponding sprite/direction changes and final return to(8,4).

| Twenty single Right samples | Median ms | Maximum ms |
| --- | --- | --- |
| Native callback to renderer stdout flush | 0.049 | 0.126 |
| Native callback to PHP acceptance | 13.069 | 23.047 |
| PHP parse to acceptance | 0.024 | 0.254 |
| PHP acceptance to changed frame enqueue | 3.874 | 5.239 |
| Enqueue to fully drained outbound queue | 0.017 | 0.038 |
| Enqueue to renderer complete-line receipt | 0.033 | 0.051 |
| Native callback to resulting frame render callback | 27.778 | 39.491 |

Rapid Right/Left/Right/Left and Up/Down/Up/Down menu groups preserved every input
and final state. Their actual native arrival spacing was uneven, so they must not
be described as uniform-rate bursts. The PHP queue peaked at3 with oldest age
55.941 ms. The last movement identity was accepted12.041 ms after its native
callback; the last menu identity took6.796 ms. No sustained movement/navigation
continued after the sampled bursts, but downstream callback delays were longer.

For movement frames186-188, receipt-to-acceptance took0.810-1.508 ms and
acceptance-to-app-replacement took99.866-120.437 ms. For menu frames199-201 those
ranges were0.399-0.437 ms and154.611-184.948 ms. Frames186/199 were coalesced before
a render callback; the following frames reached callback within0.050 ms after
replacement. This localizes those sampled stalls to renderer acceptance versus
app replacement, not PHP frame delivery or PNG preparation. Renderer read-only
review confirmed that `accepted` precedes the reader's bounded-channel send and
`replaced` follows the GPUI foreground task's snapshot replacement. The interval
therefore includes channel backpressure, foreground scheduling and replacement;
it is not a CPU-hotspot measurement. In each delayed pair the app replaced the
queued snapshots about13 microseconds apart, consistent with draining queued
updates. Coalesced intermediate snapshots are not proof of a lost final state.

Importantly, a render callback is logged before element construction/layout/paint:
work after the preceding callback can delay the next replacement. The short
replace-to-callback interval does not exonerate rendering work. Arrival of the
fourth native tap was also delayed by153.230 ms for Home and219.783 ms for the menu,
so CUA/OS event delivery versus foreground app work is not yet distinguished.
The agreed next discriminating check is a short native process stack sample across
one identical, coordinated four-tap burst, correlated with the existing traces.
Renderer owns post-acceptance sampling/interpretation; Engine owns the separately
additive FIFO waits. No new window was launched or speculative fix made for this
read-only review.
Menu entry itself also took85.421 ms from PHP acceptance to frame enqueue in one
sample; that isolated transition is not a steady-state movement measurement.

Home-to-main-menu, Equipment entry, Tab to Liora, Shift-Tab back to Kaelion, C back
from the main menu, M map opening, and F5's quick-save notification were visibly
verified. C is not Equipment's back binding; Escape correctly leaves Equipment.
T reached PHP, but this fresh Home state had no eligible skit: full ordinary-launch
T acceptance remains open. The prior sustained stale screen did not reproduce in
these transitions; its investigation is not closed by this limited pass.

Normal native close exited0. `stty -g` before/after is byte-identical, and process
inspection confirmed PHP15281 and renderer15319 exited. No test window is left
waiting for the user. Analysis: `/tmp/ichiloto-s7-e-analyze-native.php` and
`/tmp/ichiloto-s7-e-ordinary-latency-summary.json`; raw trace and copied saves remain
inside the private project. Game and Console source worktrees remain clean.
Still pending: genuine gameplay held/release, eligible T skit, native battle popup
timing/removal, renderer delay characterization, and final full tests/static rerun.

Ordinary `ichiloto play --renderer=terminal --no-tmux` did pass in a135x36 PTY:
title rendered, Up selected Exit, Enter exited0. Source-poll duration was0.177 ms
for Up and0.050 ms for Enter; source-return to acceptance was0.010/0.002 ms. These
are PHP observations, not physical-key-to-PHP latency. Terminal modes restored
with only the independently characterized macOS PENDIN bit differing, as above.
Evidence: `/tmp/ichiloto-s7-e-latency-terminal.sh`, adjacent `.stty-before`,
`.stty-after`, `.exit`; input records in the private project's `logs/latency.ndjson`.

### Cross-platform acceptance boundary

Current native evidence is macOS/Apple Silicon only. The renderer's new initial
work-area fitting uses a macOS adapter; non-macOS native startup explicitly fails
until an appropriate adapter exists. A Linux or WSL graphical run is not validated
by the shared tests, macOS terminal regression, or this native spike.

Keep gameplay, the wire protocol, input identities and viewport transform shared.
Native display policy and diagnostic clock alignment belong at platform-specific
boundaries. In particular, this PHP build's CLOCK_UPTIME_RAW observation must not
be assumed for Linux clock correlation.

The intended WSL2 path is PHP and a Linux renderer inside WSL, with WSLg supplying
the graphical desktop connection, not PHP launching a Windows executable across
the boundary. Native Linux and WSLg each still need compatible renderer builds,
graphics/backend validation, platform-appropriate window fitting, and actual
resize, input/repeat, presentation, shutdown and terminal regression checks.
Native Windows without WSL is a separate target. No platform adapter or packaging
claim is added by this report, and unavailable GPUI must not silently select the
terminal renderer. See [Microsoft's WSL GUI documentation](https://learn.microsoft.com/en-us/windows/wsl/tutorials/gui-apps)
for WSLg prerequisites, not as evidence that this GPUI build works there.

### Automated gates

- Baseline:1237 passed,1 existing skip,4333 assertions,9.86 s.
- Focused cadence/client/input:58 passed,262 assertions,0.75 s.
- Full final repeat:1247 passed,1 existing skip,4403 assertions,9.42 s.
- The preceding full run hit the previously recorded randomized SkillTest failure
  (expected430, actual372). No latency-related failure; unchanged full rerun passed.
- Full configured PHPStan passed in serial/debug mode.
- PHP syntax checks across src/tests and `git diff --check` passed.
- All8 Console test scripts passed against the local Engine and S6 game.
  The existing login-shell RVM/ps sandbox warnings remain non-test failures.
- Ordinary terminal regression passed; native GPUI final gates remain open.

Logs use `/tmp/ichiloto-s7-e-latency-` prefixes; pre-fix delivery failure is
`/tmp/ichiloto-s7-e-flush-before.log`. Nothing is committed or pushed in Engine.
No S8 or renderer-driven Console/Camera resize behavior was added. Logical game
surface, graphical viewport and native terminal dimensions stay separate;
future resolution/window/scale/fullscreen preferences remain configuration work.
Fixed-size versus resizable is likewise a future selectable policy for developers
and players, not a permanent window lock. None of these physical window choices
changes logical Camera dimensions or large-map scrolling.

## Scope and handoff

See [styled presentation](styled-presentation.md), [runtime](runtime.md) and
[transport](process-transport.md) for the final API design. There are no save
format changes, new game bindings, renderer changes, tiles, graphical NPCs or
battlers. V2 omits non-colour terminal attributes; renderer typography/box-stroke
spacing and conservative text-only cinematics remain limitations.

Commit/push is intentionally pending all native gates. Once accepted, publish
one conventional S7-E commit on engine `develop`, including the already-preserved
music ancestry, and verify clean/synchronized state. No release branch or S8 work
is authorized by this phase.
