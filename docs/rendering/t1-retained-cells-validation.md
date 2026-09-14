# T1 Retained Cells

Status: implementation and deterministic/performance validation complete on macOS.
Ordinary Native Terminal gameplay passed in a controlled PTY. Visible terminal-app
smoothness remains unverified; this is not whole-programme or platform acceptance.

## Baseline

Engine develop `68db397451afeecf380722d242d8b8dc24794b8a` is clean before T1.
Accepted overlay/ASCII-width revision `ffd2aa24251e7a313bcc71a5306ad63707acfb00`
is an ancestor. No duplicate cherry-pick or worktree reset is needed. Existing
detached auxiliary worktrees are preserved. Ordinary Game checkout is
`a89961d4aab51a272b42156b26377d2adc3ae40f`; its local configuration is not changed.
The frozen measurement fixture remains Game `47b38a2ba5058aaa59b01cfb736b298c1828acca`.

Engine lock SHA-256: `a36d91c1283cf21b9bba08c228e068fdfce1301439f3d65dec5c0563d1bb0a64`.
No dependency updates: Symfony Console v8.1.4 at `68efa2ebfd9a362951eb5a8b09fd177c66ddec24`,
FIGlet 1.2.1 at `e599b5cac85722eba864d3e2b955d632566ac01d`, PHPStan 2.1.44,
Pest 5.1.1. Full dependency revisions remain in that baseline lockfile.

Historical accepted field preparation medians are horizontal 30.61-30.71 ms and
vertical 23.06-23.21 ms. These are memory-sink diagnostics, not gameplay FPS,
terminal throughput, CPU accounting, or physical input latency. The rejected
26/20 ms fitting experiment is not an accepted baseline. Existing reports and
evidence remain authoritative. The matched T1 rerun below does not replace them.

## Representation

`NormalizedRow` holds immutable styled glyph strings, explicit wide-continuation
cells and separate logical-symbol offsets. It does not allocate an object per
cell. Camera owns its authored input, detaching external PHP array references,
and retains normalized rows lazily for the current map only. Source replacement
clears those rows and updates geometry. A real composite-width policy change
clears normalized rows without reapplying author formatting.

String-authoring APIs remain supported. They retain the established fitting and
control-boundary behavior, then converge on `Console::writeNormalizedRow()`.
Already-separated map rows bypass row serialization, formatting and tokenization.
Camera pans and MapManager's incremental background restoration share that path.

Console has one authoritative cell framebuffer. Live overlays remain independent
surfaces composed over current scene cells, not stale saved rectangles. Diffing,
wide-edge repair, layer ownership and output use these cells. String getters are
derived views. Plain/styled snapshots consume cells directly; structured style
export still parses each distinct cell string within its local snapshot cache.
This is not a claim that every SGR parse in the engine has disappeared.

Existing symbol caches remain bounded. The row cache is bounded by the current
authored map, not an accumulating history of maps or full frames. Output remains
bounded to the existing partial-write path; failed output retains pending repair
spans rather than claiming delivery. Graphical sessions do not paint the terminal.

## Matched Measurement

The original temporary harness was gone. Its field workload was recovered from
the recorded execution evidence and checked against historical frame/payload
hashes, using the frozen Game revision above. No Scrap rerun was performed.

Both arms used PHP 8.5.10 on macOS, the same 4,230 dependency files and a 135x36
viewport. Each process reused one Camera: three horizontal runs followed by three
vertical runs, each with 12 warmups at `(0,0)` and 36 measured steps. Horizontal
positions were `(i,0)` and vertical positions `(0,i)`, for `i = 0..35`. Thus the
first measured frame deliberately remains unchanged. Each sample measured only:

```php
$camera->position = new Vector2($horizontal ? $i : 0, $horizontal ? 0 : $i);
Console::recomposeFrame($camera->renderMap(...));
```

Output was a blocking `php://temp` memory stream, truncated/rewound before each
sample. No complete game simulation, input, audio or sleep was timed. Canonical
buffer, styled snapshot and payload hashes were taken outside the timing window.
Cold setup and first render were recorded separately. A separate instrumented
arm counted actual memory-wrapper writes and stage probes; headline timings do
not include that instrumentation. Its outputs also matched the headline arm.

Values below are milliseconds, in run 1 / run 2 / run 3 order. Median averages
sorted samples 17/18; p95 is sorted sample 34; maximum is sample 35 (zero-based).

| Arm | Median | p95 | Maximum |
| --- | --- | --- | --- |
| Baseline horizontal | 33.083 / 35.989 / 31.911 | 56.566 / 72.040 / 60.719 | 66.169 / 82.229 / 72.537 |
| T1 horizontal | 0.576 / 0.575 / 0.580 | 0.620 / 0.609 / 0.657 | 0.667 / 0.637 / 0.763 |
| Baseline vertical | 24.510 / 27.105 / 25.740 | 30.910 / 55.971 / 47.583 | 41.669 / 81.186 / 56.832 |
| T1 vertical | 0.565 / 0.521 / 0.491 | 0.611 / 1.375 / 0.559 | 0.645 / 1.427 / 0.587 |

The single-digit-ms preparation target is met, with roughly 98% lower matched
medians. Runs were sequential, not randomized; background system load was not
controlled and baseline tails varied. These are elapsed preparation/output-
generation measurements, not gameplay FPS, physical throughput or Scrap parity.

All **216 measured frames** match historical canonical and terminal-payload
hashes exactly. All **216 styled snapshots** match baseline/candidate, including
the separate instrumented arms. Each horizontal run emits **236,667 bytes / 72
memory-stream writes**; each vertical run emits **244,072 bytes / 80 writes**.
Write counts are measured wrapper calls, not estimates or physical terminal I/O.
No equivalent-but-differently-encoded payload exception was needed.

### Stage And Memory Costs

Candidate run-1 medians below are cumulative stage time per measured frame from
the separate trace arm. Timers can nest: selection includes first-use
normalization. Do not add these as exclusive costs. Baseline has no T1 probes,
so its stage breakdown is unavailable, not zero.

| Stage | Horizontal (ms) | Vertical (ms) |
| --- | --- | --- |
| First-use normalization | 0 | 0.059 |
| Visible selection | 0.094 | 0.153 |
| Cell composition | 0.067 | 0.068 |
| Diff | 0.243 | 0.192 |
| Serialization/output generation | 0.124 | 0.104 |

All horizontal measured runs perform zero row normalization, tokenization or
formatting. The first vertical run normalizes 35 newly exposed rows once; later
vertical runs perform none of those passes, with selection around 0.094 ms.
Diff is the largest remaining measured warm stage, already below the target;
another optimization programme is not justified by this result alone.

| Cold/process memory indicator | Baseline | T1 |
| --- | --- | --- |
| World loading/tokenization | 9.832 ms | 9.468 ms |
| Setup through first-frame readiness | 34.979 ms | 31.757 ms |
| First process-cold render | 29.390 ms | 3.269 ms |
| First-render used-memory increase | 92,176 B | 1,039,520 B |
| Setup used-memory increase | 2,771,512 B | 3,473,416 B |
| Process allocator peak | 8 MiB | 10 MiB |
| Used memory at last vertical sample | 5,111,568 B | 7,301,360 B |

Retaining rows trades about 2.1 MiB additional final used memory for eliminating
repeated conversion in this fixture. These are process indicators, including
classes, caches, buffers, output and observation arrays, not a per-cell allocation
census. Later runs reuse the Camera and must not be called process-cold runs.

## Correctness And Integration

- Final full Engine on PHP 8.5: **1,668 passed, one existing skip, 8,212 assertions**.
- Final full Engine on PHP 8.4: **1,668 passed, one existing skip, 8,214 assertions**.
- PHP 8.4 syntax checks pass for every changed/new PHP file; `git diff --check`
  reports no errors.
- PHP 8.4 PHPStan: **no errors** (`--debug --memory-limit=1G --no-progress`).
- Independent graphical/retained/Unicode selection: **217 passed, 1,174 assertions**.
  Both reproduced review findings were fixed: externally aliased map data and
  terrain replacement widths after reset-separated combining characters.
- Console's six portable scripts pass on PHP 8.4 and 8.5 against the live sibling
  Engine, confirmed by reflection. Its two optional real-game integrations also
  pass on PHP 8.4; Console PHPStan reports no errors. No Console edits were needed.
- Bounded Game integration: **104 passed, 336,359 assertions**. Selection:
  `SaveCompatibilityTest`, `GraphicalTerrainTest`, `GraphicalPlayerTest`,
  `GardenOutdoorPresentationTest`, `MacroEntranceMovementTest`,
  `MapPresentationTest`, `CrosswindRelayInteriorTest`, `MilestoneThreeSliceCTest`.
  The broad Last Legend suite was not completed because of the unrelated
  180,000-battle simulation baseline; it is not a T1 acceptance requirement.

`RetainedCameraTest` uses the real Camera/Console path and diagnostics counters,
covering idle frames, both pan axes, player/background restoration, live and
moving notifications, dismissal, menu return, graphical snapshots/exclusions,
source replacement, geometry and width-policy changes. Existing Unicode/control,
formatter, SGR, opaque-blank, layer, transaction and partial-write regressions
remain intact. Tests now seed private framebuffer fixtures with cells; public
string-buffer expectations are preserved rather than weakened.

The first automated PR review found one additional centered-map margin case:
negative world coordinates could select the first retained tile. A failing
regression reproduced it. Background restoration now checks authored bounds
before selection and restores a blank outside them, covering all four margins
without caching nonexistent rows. The full suites and PHPStan above were rerun
after this correction. No repeat automated-review request was sent.

## Ordinary Native Terminal Pass

The ordinary CLI/bootstrap ran Last Legend at 135x36 against the live candidate,
in an isolated copy of the real project with a legitimate existing checkpoint.
It used normal Load Game and the real save-compatibility pipeline, not edited
story/save state or an alternative game bootstrap. Private checkpoint details
remain private. Original saves and both pre-existing dirty game configurations
were unchanged; any normal quick-save/autosave writes stayed in the private copy.

The bounded PTY run exercised both field-camera axes, ordinary movement, a
notification over moving scenery, region-map notification dismissal, main-menu
open/close, resize to 150x40 and back to 135x36, and normal quit. The capped
viewport repainted centered at `(7,2)` in the larger PTY. Plain-grid replay after
the region-map toast dismissed equalled a close/reopen replay. Horizontal
region-map panning occurred; vertical inputs reached its existing content bound
and are not claimed as observed vertical region-map movement.

The process exited normally, emitted terminal restoration sequences and left an
empty error log. No test process or manual-input window remains waiting.
Terminal.app computer-use access was denied, and no alternate UI route bypassed
that denial. This is captured PTY/runtime evidence, **not visible smoothness,
emulator pixel/reflow acceptance or physical input latency**. Those observations
remain a manual validation gap. Movement speed, focus and pacing were unchanged.

## Evidence Identity

The matched measurement and ordinary PTY pass used implementation commit
`9f18b3b62dfbe6f4fbdca63e1e58794e2268a9d5`. The subsequent review correction
only adds world-bound checks to incremental background restoration; the timed
`renderMap()` path is unchanged. A post-correction 216-frame replay also matches
all canonical/styled/payload hashes and bytes. That extra run overlapped test
processes (H medians 0.647-1.063 ms; V 0.585-0.856 ms), so it is a correctness
confirmation, not a replacement matched timing comparison. Final Camera hash:
`8439ccb75c1607a7dbd07fd59aca16a284024078071db46dfdafd39086e98ad1`.

Matched source tree SHA-256 (ordered relative-path/file-hash manifest):
baseline `45645360475aa3cec41bcbb2a70f2dd083df0d742d00f1be1825fbd92aba2b60`;
candidate `7234306b2607247c8276cb73d0661d21019b3ecfeb3d14b38c0d2efef9ac542e`.
Both dependency manifests hash to
`76c159f54fcd85a5f65b808184f989ea436454eef96fed05e8dabc35cfd91206`.
The frozen map hashes to
`18ed87001f004285c892b48ea6570f58fe00b0580155d5e37604ebb8d490b543`.

Local raw evidence is under `/private/tmp/ichiloto-t1/`; these temporary files
are not a permanent public artifact store. The workload and results above are
the durable record. No private map/checkpoint payload is included in this repo.

| Evidence file | SHA-256 |
| --- | --- |
| `measure-recovered.php` | `fb647e2fe3d838085f18e8bd7a5b1c369317a3c06d310c8ae6a857414c28d536` |
| `measure.php` (out-of-clock observations and separate trace arm) | `f410d7822d320ce65377024b16fb970263b7ce2871b0da7e4143c221e981c068` |
| `summary.json` | `95ba73467aae33afc247c94e32d456f91d39bbeabffd2c74f8c13374bb740e75` |
| `baseline-current.json` | `c4150ce0a80e84823d110d23659a6d7e091e4771d5a97d424e8b1eaac6ed9783` |
| `candidate-current.json` | `d959dcfdb37f75da2aad3fa06dba84e9207e9109d1f28703584d1e86137909c7` |
| `baseline-current-trace.json` | `0151ec341db4d99565e861d27a9513116425d16bf1fc46093b9583cae8e28b75` |
| `candidate-current-trace.json` | `e541cd8081e7cbc798e7d87a9b33be4907c92f6ba939f580427fd0bbf68e016a` |
| `candidate-reviewed.json` (post-review output confirmation) | `96f72a1ae75fc67ea74fc58f090baaf0f88310a1c81ab6742f5fdb20150ea55d` |

## Boundaries

No game content, save state, art, renderer protocol, Rust gameplay, repeat
filtering, simulation replay or platform port belongs to T1. Graphical snapshots
and named exclusions remain compatible; graphical layouts need not use cells.
No renderer protocol or game-content changes were part of this slice. The
dark-player-on-dark-field issue is an art/background concern, not changed here.
Linux/WSLg remains untested; hosted unit-test CI is not graphical platform or
physical terminal acceptance. No protected-branch bypass or release operation
is authorized. G1 shared boundaries were coordinated with the Renderer owner;
G1 implementation and the later programme entries have not begun as part of T1.
