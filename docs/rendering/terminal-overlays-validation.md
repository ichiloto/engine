# Retained notifications and terminal scrolling

2026-09-13. Implemented locally on `fix/retained-notification-overlays`, based on
`879b23fae51452d28e20e870c820b440cb159db2` (region-map viewport fix), not merged
into the ordinary game checkout. The separate S8-B and viewport
integration gates remain unchanged. No live game window or save was touched.

## Correctness

Notifications erased their old footprint by writing spaces into the canonical
Console buffer. This destroyed an idle map's underlying content. A second cause
was AbstractScene treating notification lifecycle events like modal ownership:
opening suspended field objects and dismissal resumed them even while the map
was still the active screen.

Console now retains transient overlays separately from the live scene. Moving
or removing an overlay recomposes its affected rows against current content,
not a stale saved rectangle. Scene writes beneath it remain live. Scene clears
and recompositions preserve active overlays, while terminal teardown releases
them. Notifications no longer suspend/resume the scene; actual modal behavior
and notification lifecycle events remain intact.

Native output, plain snapshots and structured snapshots share glyph clipping
and overlay precedence. Higher scene layers still cover notifications. Wide
glyphs are never partially retained when covered or resized. World, named
layers and overlays share the existing 64-layer limit, including nested
callbacks; rejected writes leave the frame presentable. No renderer protocol
or game-content changes were part of this slice.

## Scrolling

The Console was reparsing already-separated styled glyphs to measure each cell,
re-expanding a complete styled row for each dirty span, and computing intermediate
dirty spans that full-screen recomposition discarded immediately afterward.

It now uses the existing bounded symbol-width cache directly for separated
glyphs, expands each output row once per frame, and computes only the final diff
during full-screen recomposition. Explicit repair requests and rollback remain
intact. No new persistent row cache, terminal escape optimization, map-coordinate
change or platform-specific branch was introduced.

### Matched windowless measurements

The fixture is the Garden map and region metadata from Last Legend commit
`47b38a2ba5058aaa59b01cfb736b298c1828acca`, frozen before concurrent Game edits.
Both arms use the same PHP runtime, 135x36 Console, in-memory blocking output
stream, 12 warmup iterations, 36 measured iterations, and three sequential runs
per direction. Source and fixture hashes are retained in the evidence files.

Field measurements call the real Camera map renderer within Console recomposition;
region measurements call MapState layout/panning and a real Window. They exclude
player input, the complete Game loop, audio, physical PTY delivery and native
window painting. Values are `hrtime` elapsed milliseconds, not CPU time or FPS.

| Workload | Baseline median range | Candidate median range | Bytes per 36 steps |
| --- | ---: | ---: | ---: |
| Field horizontal | 82.484-84.437 ms | 34.082-34.495 ms | 236,667 |
| Field vertical | 53.739-54.361 ms | 25.669-26.305 ms | 244,072 |
| Region horizontal | 4.656-4.815 ms | 2.628-2.863 ms | 3,402 |
| Region vertical | 4.494-4.560 ms | 2.522-2.604 ms | 2,921 |

All 432 measured frames match their baseline canonical-buffer hashes, emitted
payload hashes and byte totals exactly. The field workload reproduces a
horizontal/vertical cost difference. The sparse region workload has only nine
unique horizontal and two unique vertical frames per run; its improvement does
not establish that all reported in-game region-map chugging is resolved.

Raw matched evidence: [baseline](evidence/terminal-overlays/baseline.json) and
[candidate](evidence/terminal-overlays/candidate.json). Local harness and frozen
fixture: `/tmp/ichiloto-terminal-scroll/measure.php` and `fixture/assets` beside it.
The first fixture attempt omitted referenced Events files and failed; it was
discarded. The reported arms both include the matching Events data. These are
ordinary diagnostics, not sealed game-dev run bundles or hardware acceptance.

### Follow-up: safe ASCII width measurement

`TerminalText::displayWidth()` now measures printable ASCII after formatting and
ANSI removal directly, retaining the existing path for Unicode and all possible
formatter input. The latter guard preserves exactly-once formatter state changes.
No fitting or Console normalization order changed. A broader single-pass fitting
candidate was rejected: removing reset controls can join regional indicators or
ZWJ components, changing their width and damaging untouched cells. Regression
tests now cover those cases. Its faster 26/20 ms results are not accepted results.

The accepted revision was compared with the preceding overlay candidate using
the same frozen harness: horizontal field medians 34.27-35.20 to 30.61-30.71 ms;
vertical 25.77-25.89 to 23.06-23.21 ms. Region medians were 2.65-2.66 to 2.56-2.58 ms
horizontal and 2.53-2.63 to 2.50-2.56 ms vertical. All 432 canonical and emitted
payload hashes and byte totals match. These remain memory-sink preparation
diagnostics, not gameplay FPS or evidence that all scrolling issues are resolved.
Source hashes and raw runs: [before](evidence/terminal-overlays/ichiloto-fit-before.json)
and [final](evidence/terminal-overlays/ichiloto-ascii-width-final.json). Rejected
earlier revisions remain separate at `/tmp/ichiloto-fit-after.json` and
`/tmp/ichiloto-ascii-width-after.json`.

## Verification

- Full Engine suite after the ASCII follow-up: 1,433 passed, one existing skip,
  6,018 assertions (`/tmp/ichiloto-ascii-width-final-tests.log`).
- PHPStan: no errors. The initial worker-socket attempt was sandbox-blocked;
  the successful run used `--debug --memory-limit=1G` without worker sockets.
- Regression tests cover an actual MapState/Window plus notification lifecycle,
  animation directions, unchanged idle-map restoration, live underlay updates,
  stacked overlays, transitions, styles, wide glyphs, resizing, exclusions,
  rollback, layer-budget boundaries and nested writes.
- A separate windowless emission replay uses the actual Garden MapState and
  Notification, including panning beneath the toast. Pyte replay matches all
  36 canonical rows after opening, panning, sliding, dismissal and idle. Idle
  emits zero bytes and needs no redraw or scene resume. Artifacts remain in
  `/tmp/ichiloto-terminal-scroll/overlay-emission.{php,json}` and
  `/tmp/ichiloto-terminal-scroll/replay-overlay.py`. This is ANSI replay, not a
  physical-terminal acceptance test.
- The Renderer task independently reports 2,048 deterministic flattened
  structured/terminal comparisons passing across 512 arrangements, with no
  remaining review blocker. No renderer code or installation was changed.
- Independent final ASCII-width review compared 647 cases for both measured
  width and subsequent formatter output, with no remaining blocker. The earlier
  formatter double-application finding was fixed by excluding formatter input
  from the fast path, not adding normalization passes.
- `git diff --check` passes. Main Engine/S8-B checkout remains untouched.

The broad Last Legend suite was not completed because of the unrelated
180,000-battle simulation baseline. Linux/WSLg remains untested. The
dark-player-on-dark-field issue is an art/background concern. No renderer
protocol or game-content changes were part of this slice.

Full interactive terminal validation and integration with the pending viewport
and S8-B changes are still outstanding; the isolated measurements do not replace
those gates or claim a complete cross-platform scrolling fix.
