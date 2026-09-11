# S3 validation and handoff record

**Status: S3 validated, including the maintainer-approved special-key
`isKeyPressed()` correction. S4 has not begun.**

Validated on Apple Silicon macOS with PHP 8.5.10. Engine baseline: clean `develop`
at `bdebd2596265b16372ba4456c5694807613787eb` (S2). Renderer reference: clean
`develop` at `c9e87bb56756a7f3d4b18948949be4aa13ddbc79`, GPUI 0.2.2, Rust 1.98.1.

## Automated acceptance

- Before changes: 807 tests passed, one existing skip, 3013 assertions (6.81 s).
- Final run: 896 tests passed, one existing skip, 3251 assertions (8.41 s).
- All existing input/binding and 49 S2 transport tests pass.
- `composer analyse` passes with no errors.
- PHP syntax checks pass for all changed/added PHP files. `git diff --check`
  and whitespace checks of the new files report no issues.

The original private input-parser tests now exercise the public terminal source;
the old rebind test's raw-state injection uses a fake source instead. No real STDIN
mutation, native renderer, or desktop is required for the new automated tests.

Coverage includes existing terminal mappings, injected/split/coalesced reads,
letter case and repeats, stream ownership/nonblocking restoration, drain bounds,
source defaults/selection/reset, unchanged binding/axis/edge behavior, normalized
event dispatch, and fake renderer input reaching the existing `Input` facade.
Client tests exercise separate ordered queues, byte/count overflow, preserved
queued events after failure, fatal S2 exceptions, lifecycle delegation, unknown
key rejection, key-only reset, and input-source destruction without shutdown.

Canonical `isKeyPressed()` behavior for special keys is tested and explicitly
approved by the maintainer. Up/Enter no longer incorrectly report unpressed when
their down edge is present; this is not a claim of exact legacy compatibility.
See [input normalization](input-sources.md#approved-compatibility-correction).

## Real native validation

The Engine task supplied `tools/gpui-input-smoke.php`; the Renderer task operated
the native window and rebuilt its unchanged reference with `cargo build --locked`.
The tool used S2 process control, the new client and input source, and non-game
fixture assets. No frame was sent; native validation does not claim gameplay or
`InputManager` integration (that boundary is covered by deterministic tests).

Native inspection used an ignored temporary app bundle containing the same binary
as the bare executable. Both SHA-256 values were:
`765aa1ebedd2aa6f04ae728984d6141698fd6910fd0e5256cd4ec1bd7e8570fd`.
The bundle supplies inspector identity, not a runtime/distribution dependency.

### Keyboard normalization and protocol shutdown

Following a separate `RENDERER ready`, the requested real key sequence produced:

```text
INPUT key=up KeyCode=UP
INPUT key=right KeyCode=RIGHT
INPUT key=w KeyCode=w
INPUT key=W KeyCode=W
INPUT key=enter KeyCode=ENTER
INPUT key=space KeyCode=SPACE
INPUT key=escape KeyCode=ESCAPE
INPUT key=q KeyCode=q
```

Shift-W produced uppercase W. The preserved raw capture also has two earlier valid
Up/Left inputs whose origin the operator could not attribute; the eight lines above
are the verified requested suffix, not a claim that only eight inputs arrived.
Native Enter/Escape/q did not quit. Enter in the launching PHP terminal requested
protocol shutdown. PHP and renderer exited 0 with no close_requested, and native
inventory confirmed the renderer stopped.

### Native close

A separate run received a real Right press followed by the native close button:

```text
RENDERER ready
INPUT key=right KeyCode=RIGHT
RENDERER close_requested
```

Close remained outside gameplay input. PHP exited 0 without hanging; renderer exit
status was 0 and native inventory confirmed it stopped. Both runs kept diagnostics
on stderr, separate from parsed keys/events:

```text
Input client handshake complete. No gameplay actions or frame generation.
Renderer exited with status 0; client cleanup complete.
```

No child diagnostic tail or parser errors occurred in these native runs. Renderer
error-event preservation and fatal failures were tested with deterministic peers,
not claimed as native fault-injection tests.

## Scope and S4 handoff

No Game loop, Console, Input facade, bindings configuration, gameplay, camera, or
Rust protocol change is included. No GPUI default/selection or automatic launch
was introduced. The separate scenario-music feature branch remains preserved.
Last Legend is unchanged from its baseline, including the pre-existing user
`config.php` edit (SHA-256
`77a0b26533c8246062182748e46bed4bd651ae24bc1b43ebbebbeb1e6b8177f4`).
No tracked renderer files changed; both its `develop` and `origin/develop` remain
at the reference SHA above. No separate Linux environment was used for this run;
the Linux terminal mappings and existing POSIX transport tests pass on macOS.

Future input and presentation must share one RendererClient; independently polling
its transport would steal events. Applications own pumping cadence, consuming
non-key events, close/error policy, and explicit renderer shutdown. Queue overflow
is explicit rather than lossy; sustained workloads must respect S2/S3 budgets.
Frame generation, runtime ownership/selection, display widths, image cache policy,
and Last Legend integration remain later-phase decisions, not S3 additions.
