# Renderer process transport (S2)

`Rendering\Transport` owns a single direct child and protocol v1 NDJSON over its
stdin/stdout pipes. Ichiloto remains the PHP game engine. An external renderer
owns presentation and keyboard reporting only, not gameplay, timing, bindings,
scenes, camera transforms, or simulation. No background PHP thread is needed.

**S2 does not connect input to `InputManager`, change terminal rendering, or render
Last Legend through GPUI.** The transport is opt-in developer/library code; no
game-loop hook or renderer default was added. S3 has not begun.

The reference contract is [ichiloto/gpui-renderer at c9e87bb](https://github.com/ichiloto/gpui-renderer/tree/c9e87bb56756a7f3d4b18948949be4aa13ddbc79).
This document describes PHP ownership, not a second graphics protocol specification.

## Usage and ownership

```php
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

$transport = new ProcessRendererTransport(new RendererProcessConfig(
  command: [$rendererExecutable], // argv, not a shell command
));
try {
  $transport->start(new RendererSessionConfig('My renderer session', $assetRoot));
  foreach ($transport->pollEvents() as $event) {
    // Typed ready/key/error/close_requested data. The caller owns policy.
  }
} finally {
  $transport->shutdown();
}
```

The session requires an absolute existing readable asset directory (canonicalized
before hello), a UTF-8 title, and supported positive geometry in
`RendererGridConfig`. Defaults are 80x24 cells at 16x24 logical pixels. The process
command is injected as an argv list using `proc_open()`, without a shell; pass the
renderer executable directly rather than a launcher that leaves descendants.
The transport deliberately supports POSIX process pipes (Linux/WSL and macOS).
Native Windows anonymous-pipe selection is not supported; startup fails explicitly.
No extension beyond normal PHP process/stream support is required.

## Lifecycle

`NEW -> STARTING -> RUNNING -> STOPPING -> STOPPED` is the normal path. Startup
launches the child, configures all three pipes as nonblocking, sends one hello,
and services I/O until ready or the configured timeout (default 5 seconds).
Ready remains available as a typed event; keys received alongside it are preserved.
An error during this handshake, invalid protocol, or early exit fails startup
and performs bounded cleanup. `start()` cannot be called twice in a live session.

Runtime I/O/protocol failures enter `FAILED`. Termination advances with subsequent
polls, without waiting for the TERM grace period inside a game-loop poll. Already
parsed events are returned before the failure is thrown; stderr is drained before
the final exception. Keep polling until failure is reported, or call explicit
`shutdown()` to complete cleanup. A successfully cleaned session can be restarted
after `shutdown()` resets it to `STOPPED`.

`isRunning()` queries the OS process only. It is **not** a substitute for pumping:
the last event and stderr bytes can still be in pipes after the child exits.
Use `getState()`, poll events, and always shut down the handle. A native
`close_requested` is returned to PHP, prevents new sends to that closing peer,
and permits its clean exit; it never decides to quit the PHP game.

## Pumping and bounds

`send(RendererMessage)` validates/encodes a versioned envelope, then queues one
complete JSON line. It does not promise that the renderer has read/applied it.
Hello/shutdown belong to the lifecycle and cannot be sent manually. Application
payloads remain opaque; S2 introduces no graphics model or frame generation.

`pollEvents()` services one bounded I/O pass and returns a list of typed events.
Its default wait is zero. Tools may use a finite optional wait of up to one second.
`stream_select()` and monotonic `hrtime()` deadlines avoid busy waiting.
Each pipe has its own byte budget so a noisy stderr cannot starve stdout or writes.
Outgoing partial writes retain their exact remainder and ordering. Partial stdout
lines remain buffered, coalesced lines are all parsed, and whitespace-only lines
are ignored. EOF with an unfinished JSON line is a protocol violation.

Defaults in `RendererProcessConfig`:

| Limit | Default |
| --- | --- |
| Work per pipe per poll | 64 KiB |
| Outbound queue | 8 MiB |
| NDJSON line, including newline | 4 MiB |
| Pending events | 1024 events and 8 MiB encoded data |
| Diagnostic stderr tail | 16 KiB |

Limits and timeouts are injectable and validated. A full outbound buffer rejects
the new message without changing previously queued bytes. Incoming overflow,
malformed JSON, missing fields, unsupported versions/types, and duplicate ready
are fatal protocol errors, never silently dropped data. A child exit with unwritten
bytes is a failure. Key strings are protocol data, with no `KeyCode` or action mapping.

## Diagnostics and shutdown

Stderr is drained separately into a bounded tail. It cannot contaminate the
stdout JSON parser. `getDiagnostics()` and transport exceptions expose this tail;
exceptions also carry the available exit code and a bounded offending-line excerpt.
Recoverable runtime renderer `error` events are surfaced normally.

Explicit shutdown queues the protocol shutdown after pending messages, flushes
them, closes stdin, and continues draining output until exit. After native close,
it drains that already-closing peer instead of sending another command. The default
graceful timeout is 3 seconds, longer than GPUI's 2-second output-drain deadline.
Failure to stop escalates to TERM then KILL, each with a default 250 ms deadline.
`proc_close()` is called only after observed exit, avoiding an unbounded wait on a
live child. Errors include diagnostic stderr and exit status. Repeated cleanup is
safe; destruction is best-effort and never throws. An OS refusing to reap a killed
child is reported, not hidden behind an infinite wait.

PHP's POSIX proc pipes are file-descriptor streams whose writes go directly to the
descriptor, not buffered stdio. They do not require `stream_set_write_buffer()`;
that unsupported setter must not be treated as a launch failure. See PHP's
[stream implementation](https://github.com/php/php-src/blob/PHP-8.4/main/streams/plain_wrapper.c).

## Tests and real renderer smoke

Automated tests use a deterministic PHP peer, not Rust, a desktop, or a sibling repo:

```sh
composer test -- --filter=RendererTransport
composer analyse
```

After building the reference renderer with `cargo build --locked`, run:

```sh
php tools/gpui-transport-smoke.php \
  --renderer=/absolute/path/to/gpui-renderer \
  --asset-root=/absolute/existing/assets
```

This completes hello/ready and graceful shutdown without sending a frame. For native
keyboard/window checks, add `--duration=60`. A blank focused native grid is expected.
Press keys in that window to see PHP-parsed JSON events on the tool's stdout.
Enter in the **launching terminal** requests graceful shutdown; Enter/q/Escape in
the **native window** are only key data. The duration deadline also requests shutdown.
Use the native close button to exercise `close_requested`. Lifecycle diagnostics go
to the tool's stderr. Paths are supplied explicitly; no game or renderer checkout
path is hardcoded into the transport or smoke tool.

S3 must define higher-level process ownership, event/error policy, and input-source
integration. Later frame work must account for cadence/backpressure, scalar-cell
versus terminal display widths, and the renderer's per-frame image cache. None of
those decisions are implemented here.
