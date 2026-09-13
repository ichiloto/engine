# Ichiloto S7-E: ChatGPT Handoff

Status: 2026-09-12. **S7-E accepted as a renderer spike following direct user
playtesting. Do not begin S8.**

The user reports marked real-play improvement and explicitly authorizes
finalization, commit and push. Outstanding native/performance checks are
**deferred after user acceptance of the spike**, not passed or represented as
solved. The validation report preserves the unsuccessful/incomplete history.

## Engine and Ownership

The S7-E commit is based on Engine `develop` at
`922c7e63e766f06bea7281ae25f82b94b47fe06f`, preserving the two existing music
integration commits. Its conventional message is
`feat(rendering): complete GPUI presentation fidelity spike`; the final delivery
report identifies the commit SHA and confirms the push. Native follow-ups are
no longer commit blockers under the user's explicit acceptance decision.

PHP owns gameplay, bindings, logical Camera geometry and presentation data.
The separate Renderer task owns native input forwarding, windowing and painting.
Implemented Engine work includes v2 structured colours/text layers, opaque UI
spaces, Player PNG composition, expanded key identities, and transient battle/UI
presentation before authored holds erase it. Runtime defaults to v2; explicit v1
remains supported. This includes ANSI16/ANSI256/RGB foreground/background
extraction, style-only updates and world -> graphical sprite -> UI ordering.
Console renderer selection now launches GPUI automatically through the Engine
registry and packaged-executable resolution boundary; terminal fallback remains
available without renderer-specific game bootstrap code.

The proven pre-sleep delivery delay is fixed: each changed frame gets one bounded
zero-wait transport pass immediately after enqueue. No input deduplication,
batching, simulation replay, channel resizing or speculative cache was added.

The final acceptance attempt fixed two additional demonstrated problems:
- Player presentation tests now supply their own PlaySettings instead of relying on execution order.
- Game's error handler respects PHP's active error mask, allowing existing optional-I/O fallbacks to run. Suppressed warnings no longer crash direct terminal startup when /dev/tty is unavailable; unmasked errors still log and exit 1. Subprocess regressions cover suppression, explicit masking and the unmasked fatal/logging path.

Release flow stays develop -> main -> release/X.x.x. Only the user creates and
releases release branches.

## Renderer

Clean, live-remote synchronized develop:
`6556e414c819f4cbd71b0a9865c4e9824e111426`
(`feat(renderer): trace foreground work and document optimized builds`).
Includes viewport `b1a6425` and shared-clock diagnostic `e7f550d` commits.
`cargo build --release --locked` passes. Reviewed release and locally installed
Engine binaries match (neither binary is staged in Git):

```
eda9ec05b582bcbbe90a67b19910fdb7d6e4a0ec9f7907b013a58fa7ce7cbdd9
```

Release binaries are required for gameplay/performance acceptance and packaging.
Debug binaries are development/diagnostic artifacts. Do not commit the local
executable or machine-specific ignored manifest to Engine.

Renderer resizing remains accepted: fixed 135x36 logical grid at 10x20 cells,
uniform downscaling, centered larger viewport, automatic 1x cap. No Camera,
Console logical or protocol resize. The additional real-game resize recheck is
deferred after user acceptance of the spike, not recorded as passed.

## Performance and Stale Surface

Earlier ordinary single-input measurements: native callback -> PHP acceptance
median 13.069 ms/max 23.047 ms; acceptance -> changed-frame enqueue
3.874/5.239 ms; structured snapshot roughly 2-2.4 ms.

The old 100-185 ms pre-replacement stalls are closed as unoptimized debug
foreground work. Matched release accepted -> replaced intervals were
0.015-8.807 ms; paint-stage elapsed approximately 7.897-9.926 ms.
Do not attribute debug timings to production.

The last instrumented ordinary release run exposed a **different post-replacement symptom**.
All 176 Engine frames were received, accepted, submitted, dequeued and replaced;
no outbound Engine frame remained and renderer diagnostics dropped zero records.
Only 11 callback/paint occurrences across nine snapshots were recorded.
Frame 100 waited 28.064 s after replacement for its callback, frame 130 243.463 s.
Final frame 176 was replaced but not given a callback before shutdown ~1.783 s
later. Target-window screenshots remained stale while PHP advanced.

Accepted -> replaced stayed fast (median 0.055 ms/max 1.313 ms), observed handoff
depth at most 1 of capacity 2. Paint-method spans were 8.708-28.778 ms.
Those spans do not explain the multi-second gap before callback entry.
These are CPU-side elapsed observations, not GPU/visible-pixel timestamps.

Pinned GPUI 0.2.2 macOS code stops the display link when NSWindow is occluded;
focus/display events can cause a synchronous draw. This is a concrete,
source-supported **hypothesis**, not the session's proven cause. Window-target
captures and AX focus do not establish actual desktop visibility, and occlusion/
platform frame requests were not traced. The next distinguishing observation is
verified uncovered foreground input/repaint versus a deliberately covered interval.
No Engine snapshot/polling workaround or renderer queue rewrite is justified.

The historical stale screen cannot be definitively attributed to debug cost.
The release post-replacement symptom remains unresolved. The user accepted the
current spike based on direct playtesting and marked further optimization and
stale-surface/occlusion investigation as follow-up work. This is not a repaint fix.

## Input and Battle

The ordinary `ichiloto play --renderer=gpui --no-tmux` run finished the authored
Home prologue and reached Player (4,5) with an empty queue. A two-minute request
asked the user to hold Right physically for one second and reply "Released".
No physical input or confirmation arrived. All 13 recorded keys were preceding
automation. This incomplete observation is deferred after user acceptance of the
spike, not a failed responsiveness test or a passed physical-input check.

Automated positioning reached depth 5/oldest age 98.724 ms, then depth 0/age null.
The deterministic 10-key/40-ms-consumer example proves 360-ms FIFO backlog is
possible, not a native held-input defect. Engine consumes one renderer identity
per normal input iteration; arrival faster than consumption can create backlog.
No arbitrary deduplication, repeat filtering, batching, key-up invention or
simulation replay was introduced. The FIFO limitation is not claimed solved.
The user reports marked real-play improvement with the release renderer; richer
held/repeat semantics can be revisited if actual gameplay warrants it.
GPUI's macOS is_held=false is known unreliable; use actual native callbacks,
PHP consumption, coordinates and user-confirmed release. Do not invent key-up,
infer repeat from timing, drop deliberate taps or replay gameplay updates.

Earlier native C/M/F5 and Equipment Tab/Shift+Tab observations remain recorded.
The requested final native matrix is incomplete. T must open an eligible authored
skit, not merely reach PHP when none is available. The eligible T recheck and
exhaustive final matrix are deferred after user acceptance of the spike.

Real native damage acceptance was not completed: popup text, bright-red structured
colour, perceptible authored hold, removal, resulting HP and observed battle
phases. Automated popup frames are not visual acceptance. No popup value,
duration, healing scenario or battle phase is claimed from that run. This visual
acceptance is deferred after user acceptance of the spike.

## Final Validation and Prior Native Cleanup

- Final non-native Engine pass after user acceptance: **1250 passed, one existing skip, 4415 assertions**, 8.66 s. The skip is the existing Enemy creation test.
- Explicit SaveCompatibilityTest + SaveManagerTest: **14 passed, 77 assertions**, 0.19 s.
- Explicit transport v1/v2/input/S4-S7/timing/launch/error subset: **402 passed, 1473 assertions**, 6.39 s.
- Player presentation isolation: 11 passed, 46 assertions. PHPStan, syntax across all 800 src/tests/resources PHP files and git diff --check pass.
- All eight Console scripts pass with local Engine and the S6 game fixture enabled; no integration skips.
- Prior ordinary terminal: existing save loaded; ANSI terrain/selection colours, M/C, Equipment Kaelion -> Liora -> Kaelion, F5 and Q/confirm worked; exit 0. Real terminal battle transients were not rechecked and are deferred after user acceptance of the spike.
- Direct `php last-legend.php`: after the error-handler fix, title and normal Exit worked, exit 0.
- Native close: wrapper/Console chain exit 0, identical stty state, empty error log at close, shutdown diagnostic present; all recorded wrapper/Console/Game/GPUI processes gone. Audio was muted with no audio process present. Separate raw close_requested logging/renderer exit code was not captured.

Terminal/direct stty differed only by macOS PENDIN (0x20000000). An engine-free
shell stty/read/restore sequence reproduces it. Echo/canonical configuration is
restored; exact byte identity is not claimed for those two terminal runs.

All test windows are closed. No manual input is currently expected. No additional
native/manual testing is part of this finalization. Physical held/release,
eligible native T, the final native keyboard matrix, real battle colour/hold/
removal, stale-surface diagnosis, real-game resize and terminal battle feedback
are deferred after user acceptance of the spike. They do not block this commit
and push; the required final non-native checks passed as recorded above.

## Integrity and Platform Limits

Console is clean/live-remote synchronized at
`4c45f3428214ec3737c9f24644c8c87813e9f4e9`. S6 game remains clean at
`d1c1993c2f67773ce4b9b5cb1d60ff3d65d85eb0`; config SHA-256 is unchanged:
`9979d045bdb88ad8308faf006b8072ea701c801704ceb09bf463c55cbee608db`.
Original-game user changes are untouched. Only private muted config/private saves
were used; no renderer-specific game bootstrap, binding or story fixture added.

Native evidence is macOS/Apple Silicon only. Linux/WSL native and terminal
acceptance are not established here. The current usable-work-area adapter is
macOS-only; unsupported graphical startup fails explicitly. The intended WSL2
route keeps PHP and the Linux renderer together under WSLg, but is unverified.
Native Windows is a separate unverified target.

Deferred follow-ups are stale/occlusion repaint investigation, held/repeat input
semantics, additional native battle/skit acceptance, further performance
optimization, cross-platform native work and configurable window/fullscreen/scale
policy. None is represented as solved. The completed slice does not deliver
tilemaps, graphical NPCs/battlers, dynamic logical resolution, renderer-driven
Camera resizing, a window preference system or a rich key-up/repeat protocol.

Detailed evidence: `docs/rendering/s7-e-validation.md` and renderer
`docs/burst-investigation.md`. Last instrumented native attempt:
`/tmp/ichiloto-s7-e-final-20260912/`; raw trace
`runtime/logs/latency.ndjson`, analysis `evidence/native-analysis.json`,
automated results `evidence/*-final.log`. Final post-acceptance non-native logs:
`/tmp/ichiloto-s7-e-accepted-20260912/`. These temporary evidence paths are not
runtime dependencies or committed artifacts.
