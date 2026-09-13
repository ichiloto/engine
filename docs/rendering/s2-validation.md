# S2 validation record

Validated on Apple Silicon macOS with PHP 8.5.10. Engine baseline: `9082a10` on
`develop`. Renderer reference: clean `develop` at
`c9e87bb56756a7f3d4b18948949be4aa13ddbc79`, GPUI 0.2.2, Rust 1.98.1.

## Automated acceptance

- Before changes: 758 engine tests passed, one existing skip, 2839 assertions.
- Added 49 focused transport tests using only a deterministic PHP child fixture.
- Full suite: 807 passed, one existing skip, 3011 assertions (7.09 seconds).
- `composer analyse` passes. A sandboxed run also passes with
  `composer analyse -- --debug --no-progress` (no worker sockets).
- PHP syntax checks and `git diff --check` pass.
- The renderer task rebuilt the unchanged native reference with `cargo build --locked`.

Tests exercise hello/ready, typed events, coalesced/chunked input, incomplete EOF,
unknown/malformed envelopes, startup errors/timeouts, noisy stderr with a bounded
tail, byte/event limits, real pipe backpressure and exact output ordering, pending
output at exit, clean/unexpected/nonzero exits, native-close ordering, idempotent
shutdown, TERM/KILL escalation, nonblocking fatal cleanup, and destructor cleanup.
Fixture deadlines prevent stalled children from outliving test guards. No Rust or
graphical desktop is required by the automated suite.

## Real PHP-to-GPUI checks

The Engine task supplied `tools/gpui-transport-smoke.php`; the Renderer task
operated its native window. Every launch used the PHP transport's `proc_open()`
and a non-game fixture directory as `assetRoot`. No frame was generated or sent.

The native inspector needs app identity, so the interactive runs used an ignored
temporary macOS bundle containing the exact renderer binary. Bundle and bare
binary SHA-256 both matched:
`765aa1ebedd2aa6f04ae728984d6141698fd6910fd0e5256cd4ec1bd7e8570fd`.
The bundle is not a transport or distribution dependency.

### Native keys, then protocol shutdown

The blank native grid stayed open during real Up, Shift-W, q, and Escape presses.
PHP's stdout contained exactly these parsed events:

```jsonl
{"protocol":1,"type":"ready"}
{"protocol":1,"type":"key","key":"up"}
{"protocol":1,"type":"key","key":"W"}
{"protocol":1,"type":"key","key":"q"}
{"protocol":1,"type":"key","key":"escape"}
```

Enter in the launching PHP terminal requested protocol shutdown. PHP and renderer
both exited 0, with no `close_requested`. Native inventory confirmed the renderer
was gone. This proves keys are surfaced as data rather than mapped to game actions.

### Native close

A new launch received a real Right press followed by the native close button.
PHP's stdout contained exactly:

```jsonl
{"protocol":1,"type":"ready"}
{"protocol":1,"type":"key","key":"right"}
{"protocol":1,"type":"close_requested"}
```

PHP surfaced the close event, drained the closing process, and exited 0 without
hanging. Native inventory confirmed the renderer was gone.

### Bare executable

An additional immediate handshake/shutdown run used the unbundled executable.
Its sole stdout event was `ready`; both PHP and renderer exited 0.
All three runs kept lifecycle diagnostics on the tool's separately captured stderr:

```text
PHP hello/ready handshake complete. No frame or gameplay integration.
Renderer exited with status 0; process and pipes released.
```

Normal native runs produced no child diagnostic tail. Heavy/nonempty child stderr
and failure injection were tested with the PHP fixture, not claimed as native tests.

## Scope and observations

- Existing terminal/runtime code, `InputManager`, `Game`, `Console`, camera, and audio are untouched.
- Last Legend is unchanged from its baseline, including the user's pre-existing
  `config.php` edit (SHA-256
  `77a0b26533c8246062182748e46bed4bd651ae24bc1b43ebbebbeb1e6b8177f4`).
- No tracked renderer file changed; it remains at the S1 reference commit.
- The unrelated engine `feature/scenario-music` branch remains preserved.
- PHP's descriptor-backed proc pipes do not support the stdio write-buffer setter;
  nonblocking descriptor writes are used directly, with correct partial-write handling.
- PHP's CLI launch failures and broken-pipe notices are converted to transport
  failures rather than escaping through the host's error handler.
- macOS native behavior is validated here. Linux/WSL use the same POSIX pipe path,
  but a separate Linux execution environment was not used for this acceptance run.
- S3 must define application ownership and input policy. Unicode display widths,
  frame cadence, and image caching remain later-phase considerations. No S3 work began.
