# Graphical output and Garden validation

2026-09-12. Follow-up to the S8/readability work. Changes remain uncommitted.

## Changes

External renderer sessions no longer also paint game frames or ANSI controls to
the terminal. Game selects output at startup, after optional runtime attachment;
construction is quiet. Console still composes canonical cells and named layers,
exports styled snapshots and retains rollback/cleanup semantics. It skips only
physical-terminal work: dirty spans, payload assembly, screen controls, the
dedicated output descriptor and active emoji cursor-position probes. Native
terminal sessions retain their normal input, output and resize lifecycle.
Errors continue to use the existing logs and stderr notice.

Garden exposed a separate shared-text problem. `TerminalText::visibleSymbols`
appended every colour change and selective reset to each subsequent cell. A
2,300-byte alternating-colour sample expanded into 302,500 bytes of cells, with
one character carrying up to 2,005 bytes. `SgrStyleState` now retains active
attributes instead: the same sample produces 2,100 bytes, at most 10 per cell.
Foreground/background and non-colour attributes remain independent; indexed/RGB
components are not mistaken for resets. Unsupported controls keep their legacy
replay ordering until a full reset. The common SGR semantics follow the
[xterm control-sequence reference](https://invisible-island.net/xterm/ctlseqs/ctlseqs.html).
This corrects shared text processing for both terminal and graphical sessions,
without changing map content, sprite pixels, gameplay or renderer protocol.

## Measurements and native checks

Private save copies and muted runtimes were used under
`/tmp/ichiloto-garden-output`; real saves and project configuration were untouched.
The Garden checkpoint starts at `(8, 3)` after its arrival cinematic. The control
workload uses 12 idle iterations and 36 prescribed camera pans, with seed 12345,
a 135x36 grid and 10x20 cells. This is not a physical-key latency measurement or
a playthrough of the entire chapter.

The same installed release renderer was used throughout:
`20a38d04d44f7eb0dd3469dadabdc2b7cf567487bac14db34bd73608bfa6a584`.
No Rust runtime changes were made for this investigation.

| Measurement | Terminal mirror | Buffer-only |
| --- | ---: | ---: |
| 48-iteration elapsed time | 9.947 s | 6.041 s |
| Median PHP field recomposition | 178.000 ms | 78.233 ms |
| Median presentation snapshot | 25.627 ms | 25.099 ms |
| Median native callback to CPU paint end | 33.240 ms | 33.426 ms |

Both controls delivered all 36 snapshots through a complete native CPU draw
cycle. Foreground/occlusion was not confirmed for those initial runs, so they
establish CPU-stage observations, not visible FPS or GPU completion.

The first style-normalized run recorded no native paint callbacks. Its PHP
measurements are retained separately, not used as a matched drawing comparison.
The Mac was found locked afterward; this does not prove when it locked or why
callbacks were absent.

After the user unlocked the Mac, a fresh `normalized-foreground` run added a
bounded startup gate: present the initial field, confirm the focused native
window, then release the same 48-iteration workload. The initial Garden capture
showed legible labels, blue water and coordinate/heading HUD, with the graphical
player intact. The measured workload completed in 3.280 seconds, exited 0, left
empty error/stderr logs and restored identical terminal settings. CUA inventory
confirmed that the renderer had exited. No test window remains waiting for input.

The foreground trace contains 36 completed native draw cycles. Excluding the
startup frame leaves 35 measured-workload frames, all drawn; no trace records
were dropped. Median recomposition was 25.891 ms (36 pans), presentation snapshot
7.525 ms (48 iterations), and native callback-to-CPU-paint-end 34.687 ms. The
unchanged native draw cost and lower PHP work support the shared-text fix. The
extra initial frame/warmup and single sequential samples mean this is not a
controlled FPS benchmark or a guarantee for all Garden gameplay.

Full frame-stage traces, analysis, receipts and the distinct control/foreground
workloads are retained by the renderer task in
`website/gpui-renderer/docs/garden-performance.md` and its adjacent
`docs/evidence/garden-output` directory. Timing samples are sequential single
runs, subject to system load; neither CPU paint completion nor these elapsed
totals establish visible frame rate.

## Regression coverage

- Engine: 1,337 passing tests, one existing skip, 4,954 assertions.
- PHPStan: no errors (`--debug --memory-limit=1G` avoids sandbox worker sockets).
- Console: `composer test` passes; two existing opt-in integration checks skip.
- S6 game: focused graphical-player and Garden Slice A integrations pass all
  15 tests (270 assertions), including normal/skipped arrival cinematics. The
  broad game suite was stopped during its unrelated 180,000-battle simulation
  baselines; it is not reported as a completed full-suite pass.
- New tests cover quiet construction, output selection, identical canonical
  cells/layers in both modes, transaction rollback, late writes, startup failure,
  later terminal sessions, bounded styles, selective resets and RGB zero values.
- Renderer task additionally compared 5,000 deterministic styled-prefix cases
  using existing colour extraction: no semantic mismatch.
- A real macOS PTY terminal Garden run rendered all 48 iterations, exited 0 and
  left empty error/stderr logs. Its 386,333-byte transcript includes normal
  screen entry, coloured content and cleanup. Terminal settings differ only by
  macOS's transient `PENDIN` bit; an engine-free `stty cbreak -echo`/restore control
  reproduces that exact difference. No platform-specific workaround was added.

Native Linux and WSL/WSLg acceptance remains untested. These PHP changes do not
introduce platform-specific rendering behavior, but macOS tests do not establish
cross-platform native acceptance. See [S8/readability validation](s8-a-validation.md)
for the earlier font/palette checks. The dark authored player artwork against a
plain dark field is still a separate art/background concern, not fixed by text
contrast or style normalization.
