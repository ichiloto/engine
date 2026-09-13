# Ichiloto and Scrap scrolling comparison

2026-09-13. The comparison itself was read-only: no Engine behavior changes,
installation, game launch or save writes. The subsequent implementation is
recorded in [terminal validation](terminal-overlays-validation.md), including
the final ASCII-width optimization. The measurements below retain the original
comparison's source revisions; the Engine fixes remain isolated from the
ordinary game checkout.

## Finding

Scrap has a substantially cheaper viewport-preparation path. Our recent Console
optimizations help, but do not close that gap. The first recording demonstrates
different camera workloads; the additional opening-menu recording does show
genuine scrolling behind an overlay. Neither apparent responsiveness nor the
isolated processing ratios should be presented as a gameplay FPS comparison.

### The supplied recording

The 103.618-second recording contains Ichiloto followed by Pokete. Both title
bars report 135x36. Ichiloto's large exterior visibly moves beneath the player;
in the sampled Pokete Route 0 sequence at 45-49 seconds the player moves while
the terrain remains stationary. Map transitions and battles are not scrolling
measurements. The supplied recording cannot identify exact build hashes or
physical keypress times.

This is consistent with the inspected source: Ichiloto's camera focus is centred,
whereas Pokete checks a six-cell edge margin before moving its viewport. Its
installed standard loop also sleeps 50 ms after updates. That is a configured
delay, not proof of an observed 20 FPS rate. See
[Pokete's main loop](https://github.com/lxgr-linux/pokete/blob/master/src/pokete/__main__.py)
and the locally installed `pokete/base/loops.py` and `pokete/release.py`.

Sparse frame inspection and cropped frame-change metadata were used only to
distinguish player motion from camera motion. No FPS estimate was derived from
compression differences or the recording's capture rate.

### Additional opening-menu recording

`Screen Recording 2026-09-13 at 5.40.47 AM.mov`, supplied from the author's Desktop,
is 14.472 seconds long at 1078x666. It shows Pokete's town scrolling horizontally
and vertically behind a stationary Mode menu. This is a valid qualitative camera
scrolling reference, unlike the stationary Route 0 sample above. It also shows
the foreground menu remaining intact over moving scenery. It does not test
notification dismissal or isolate horizontal from vertical preparation costs.

The installed `pokete/classes/pre_game.py` explains this behavior:
`BGMoverEvent.tick()` advances both viewport coordinates by one cell on every
even tick, reversing each direction at map boundaries. `ModeChooser` calls the
standard loop, which runs that event, calls `full_show()` and sleeps 50 ms.
Thus the intended movement interval is approximately 100 ms plus processing
time, not a 60 FPS animation. On movement ticks this uses `set()` followed by
`full_show()`, matching the double-remap shape of the diagnostic below. The
opening viewport reserves one terminal row (`tss.height - 1`).

As a recording-only cadence check, FFmpeg analysed a 940x480 content crop at
pixel (64,80), excluding the title bar. All 832 captured frames were examined.
Mean absolute frame-difference thresholds of 0.5, 1.0 and 1.5 each found the same
132 large changes: median spacing 116.67 ms, range 83.33-135.00 ms. That is
consistent with regularly paced cell steps. These are captured scene-change
intervals, not engine FPS, input latency or proof of tear-free physical output;
the recording itself cannot certify the installed source/build identity.
Raw metadata is retained locally at `/tmp/pokete-opening-scene-deltas.txt`.

The new evidence strengthens the target: steady whole-scene scrolling with
correct foreground composition. It does not change the matched Garden timings
below or establish that language choice causes their difference. The opening
animation has no equivalent player movement, collision or full gameplay workload.

## Same-map diagnostic

The installed packages are Scrap 1.5.4 and Pokete 0.10.0rc4, identified by package
metadata. Scrap's internal `__version__` string still says 1.4.4; the evidence
therefore records the actual Map/Submap source-file hashes as well.

Scrap was given the exact normalized, styled Garden cells used by the previous
Ichiloto benchmark, frozen from Last Legend commit
`47b38a2ba5058aaa59b01cfb736b298c1828acca`. Both use a 135x36 viewport, identical
horizontal/vertical camera positions, 12 warmup iterations, 36 measured steps
and three sequential runs per direction. Neither runs full game logic.

| Viewport preparation/output generation | Horizontal median range | Vertical median range |
| --- | ---: | ---: |
| Ichiloto before the pending optimization | 82.48-84.44 ms | 53.74-54.36 ms |
| Ichiloto pending optimized candidate | 34.08-34.50 ms | 25.67-26.31 ms |
| Scrap `set()` + `show()` | 0.480-0.481 ms | 0.347-0.357 ms |
| Scrap `set()` + `full_show()` | 0.727-0.762 ms | 0.591-0.605 ms |

The last arm includes a second remap, as Pokete can do when its camera update is
followed by the normal loop's `full_show()`. It remains below 1 ms median here.
That does not include Pokete's event handling, actors, input, sleep or terminal
display latency. It is not a matched full-game execution trace.

All 216 measured styled viewport hashes in each Scrap arm exactly match the
corresponding Ichiloto candidate. Scrap's emitted ANSI was also replayed outside
the timed region and checked against its expected visible rows. Scrap's output
format reserves a trailing physical row; replay used 135x37 and compared the
first 36 rows, rather than allowing its final newline to scroll the viewport.

Scrap emits 1,395,240 bytes horizontally and 1,077,981 vertically per 36 steps.
Ichiloto emits 236,667 and 244,072 respectively. Scrap sends about 5.9x/4.4x as
much data in this workload, despite much cheaper preparation. These measurements
exclude physical delivery and cannot establish how those byte volumes perform
through a particular terminal or remote connection.

### Why the paths differ

Scrap's Map/Submap retain already-renderable cells, copy the visible rectangle,
serialize rows through a bounded line cache, compare the final frame string and
emit the full viewport when changed. It is not a dirty-cell terminal renderer.
The installed paths do not repeatedly parse grapheme widths or ANSI styles while
assembling this ASCII map. The code includes frame-cache decorators too, but this
report does not assume they hit when their keys contain fresh generator objects.

Ichiloto already stores map cells, but Camera joins them into styled row strings.
Width fitting, Console writes, final diffing and output slicing then expand those
strings again. The pending fix removes several redundant passes, not the entire
cells-to-text-to-cells cycle. Ichiloto also performs sparse diffing and maintains
general-purpose wide-glyph and presentation semantics that this Scrap path does
not provide equivalently.

The useful next target is keeping normalized cells/styles through viewport
composition and diffing, serializing only at output boundaries. Preserve overlay
ownership, Unicode correctness and bounded state. Do not copy Scrap's full-frame
output indiscriminately or change camera behavior merely to disguise slow pans.
This comparison does not isolate programming-language cost or prove an inherent
PHP limitation.

## Evidence and limits

- [Ichiloto baseline](evidence/terminal-overlays/baseline.json) and
  [candidate](evidence/terminal-overlays/candidate.json) retain per-frame hashes,
  payload hashes, bytes and source hashes from the preceding matched runs.
- [Scrap single-remap](evidence/scrap-comparison/scrap-single-remap.json) and
  [double-remap](evidence/scrap-comparison/scrap-double-remap.json) retain measured
  results and viewport hashes. Scrap sources were read from the installed package
  under `/Library/Frameworks/Python.framework/Versions/3.13/lib/python3.13/site-packages`.
- Local reproduction material is in `/tmp/ichiloto-scrap-comparison`: exported
  `garden-cells.json`, `scrap-measure.py`, recording samples and scene metadata.
  No Scrap code was copied into the Engine.
- The timers measure elapsed preparation plus memory-sink output, not CPU time,
  GPU time, PTY latency, input-to-display latency or visible FPS. Runs are sequential,
  not randomized; their scopes and runtime implementations differ. Timings are
  diagnostic evidence, not sealed hardware-performance acceptance.
- Linux/WSLg remains untested. Full interactive validation of the pending
  Ichiloto changes is still outstanding. The report does not claim that the
  earlier region-map sluggishness is completely resolved.
