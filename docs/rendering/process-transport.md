# Renderer process transport

`Rendering\Transport` owns a single direct child and versioned NDJSON over its
stdin/stdout pipes. Ichiloto
remains the PHP game engine. An external renderer
owns presentation and keyboard reporting only, not gameplay, timing, bindings,
scenes, camera transforms, or simulation. No background PHP thread is needed.

The transport is a low-level component. [RendererRuntime](runtime.md) connects
it to the Game loop through one shared client for input, lifecycle and retained
presentation. Terminal remains the default. The native renderer accepts retained
V2 frames, not V1 or stateless V2 full-frame payloads. Reference encoders are not
a compatibility fallback. See [retained presentation](presentation.md) for operations.

## Usage and ownership

```php
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

$transport = new ProcessRendererTransport(new RendererProcessConfig(
  command: [$rendererExecutable], // argv, not a shell command
));
try {
  $transport->start(new RendererSessionConfig('My renderer session', $assetRoot,
    protocol: RendererProtocolVersion::V2));
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

This is a PHP transport limitation, not a GPUI window-platform restriction:
PHP documents that [`stream_select()` fails on `proc_open()` descriptors on
Windows](https://www.php.net/manual/en/function.stream-select.php#refsect1-function.stream-select-notes).
Removing the startup guard would leave a broken I/O loop. Native Windows needs
a transport that preserves bounded, nonblocking reads/writes and cleanup on that
platform. WSL/WSLg runs the POSIX path with Linux PHP and a Linux renderer; it does
not require the native Windows pipe implementation. Platform support here does
not assert that a particular native build has been exercised on every platform.

## Lifecycle

`RendererMessage`, `RendererEvent` and `RendererSessionConfig` retain their
`RendererProtocolVersion`. Low-level messages and sessions default to `V1` for
reference compatibility; `RendererRuntimeConfig` requires `V2` and rejects V1.
Select `protocol: RendererProtocolVersion::V2` on a low-level session talking to
the current native renderer. Encoding uses each message's version, not a global
constant. No new retained-presentation capability flag is required.

Hello fixes the session version. Application sends, ready, keys, errors, close
and shutdown must match it. Mixed outbound versions are rejected before enqueue;
mixed inbound versions fail the connection. During v2 startup only, a v1 ERROR
before ready is accepted as a pre-session hello rejection and fails startup with
its diagnostic. V1 keys before ready and all v1 events after v2 ready remain
illegal, including an error received in the same read as ready.

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
`trySend(RendererMessage): bool` performs the same validation without waiting:
`true` means the entire line was accepted; `false` means temporary outbound
capacity pressure and leaves the queue unchanged. Retain that message and retry
after servicing I/O. Invalid data, a mismatched protocol, an oversized line or a
dead peer still throws; these are not a `false` capacity result. The strict
`send()` API continues to throw when capacity is exhausted.

Both methods and `getPendingWriteBytes(): int` are part of
`RendererTransportInterface`; custom transports implement them too. The shared
`RendererClient` forwards the nonblocking admission and pending-byte APIs.
Pending bytes measure only PHP's unwritten queue, not receiver acceptance or
presentation. A zero count is not an ACK.

Hello/shutdown belong to the lifecycle and cannot be sent manually. Application
payloads remain opaque to this transport; Presenter owns retained operations and
generation sequencing.

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

Limits and timeouts are injectable and validated. A full outbound buffer declines
the new message without changing previously queued bytes (`trySend()` returns
false; `send()` throws). The retained producer additionally uses a 1 MiB pending
high-water mark and bounded 32-packet/32-pass work and acknowledgement windows,
rather than treating the transport's 8 MiB capacity as an upload target. Its
[resumable upload budget](presentation.md#nonblocking-upload-and-coalescing) keeps
a valid slow reader from causing a whole-map queue overflow. Incoming overflow,
malformed JSON, missing fields, unsupported versions/types, and duplicate ready
are fatal protocol errors, never silently dropped data. A child exit with unwritten
bytes is a failure. Key strings are protocol data, with no `KeyCode` or action mapping.

## Retained frame feedback

The V2 event stream includes:

```json
{"protocol":2,"type":"frame_ack","generation":1,"frame":1,"presented":true}
{"protocol":2,"type":"frame_rejected","generation":2,"expectedGeneration":1,"message":"base generation mismatch","resyncRequired":true}
{"protocol":2,"type":"resized"}
```

Each accepted packet advances generation. `presented: false` acknowledges staged
state; `presented: true` acknowledges its atomic promotion to the visible state,
not physical monitor scanout. The shared client coalesces ACKs into the latest
staging and presentation progress: at most two pending slots, one per `presented`
value, rather than one event per chunk. `drainFrameAcknowledgements()` drains only
those slots without I/O or consuming input/lifecycle events. `drainEvents()`
returns ordered lifecycle events followed by the coalesced ACKs. Runtime forwards
them to `RendererPresentation::acknowledge()`; keys remain in the input queue.

Uploads target 32 KiB chunks and remain within the 4 MiB line limit. Intermediate
packets use `present: false`; only the final `present: true` publishes the complete
candidate. Native validation failures keep the prior visible state and request
resynchronization. Non-reset packets require the current `baseGeneration`;
reset packets ignore a mismatched base but still require a newer generation.
See [atomic staging and limits](presentation.md#retained-operations-and-generations).

`frame_rejected` is recoverable presentation feedback, not a gameplay failure:
Runtime logs it and invalidates once per drained batch, taking the highest
`expectedGeneration` from that batch. `invalidate(expectedGeneration: ...)`
retains desired content and rebases the next reset above both the local and
reported receiver generation. A receiver ahead of PHP therefore does not cause
a succession of impossible resets. Resize and rejection in the same drain share
that one invalidation.

If pending delivery makes neither partial-write nor ACK progress for
`RetainedPresentation::ACK_TIMEOUT_SECONDS` (2.0 seconds), the next present
requests a reset even when content is unchanged. This uses a monotonic clock and
also detects a dropped final packet with no successor to trigger rejection.
Repeated old ACKs do not extend the deadline. Recovery still obeys queue capacity;
it neither discards a partially written line nor keeps appending full resets
behind a blocked reader. An actual send failure invalidates future delivery but
still propagates as a transport error; temporary `trySend(false)` does not.

A native `resized` event requests one resend on the next present, not a logical
grid resize. Explicit `RendererRuntime::restart()` cleans up the old peer and
starts a new presentation session from generation 0 without restarting gameplay.
Runtime does not automatically restart a crashed child. Malformed feedback and
transport/protocol failures remain errors; Runtime also treats native `error`
events as failures rather than confusing them with recoverable frame rejection.
Wire size and successful pipe delivery do not establish native resource
acceptance. See the separate [internal world source budget](presentation.md#internal-world-source-budget)
and its retained-text fallback.

## Diagnostics and shutdown

Stderr is drained separately into a bounded tail. It cannot contaminate the
stdout JSON parser. `getDiagnostics()` and transport exceptions expose this tail;
exceptions also carry the available exit code and a bounded offending-line excerpt.
Renderer `error` events are surfaced to their caller; Runtime routes them to its
normal failure handling. Recoverable frame rejection uses `frame_rejected`.

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

When sharing a connection with input, use the [shared client](input-sources.md)
rather than independently consuming this transport's events. See
[runtime ownership](runtime.md), [styled row extraction](styled-presentation.md)
and [retained world rows](tile-batches.md) for the higher-level contracts.
