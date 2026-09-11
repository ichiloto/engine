# Pluggable input sources (S3)

Ichiloto owns input bindings and gameplay. Input sources supply key identities,
never actions. Terminal input remains the default; this capability does not launch
GPUI from a game, change project configuration, or render a graphical game frame.

## Contract and normalization

`IO\InputSources\InputSourceInterface` has two methods:

```php
public function poll(): ?KeyCode;
public function reset(bool $drainBufferedInput = false): void;
```

Each poll returns one canonical key in arrival order, or null. Sources perform
bounded reads and do not call gameplay, change bindings, or own game-loop timing.
`InputManager` holds current/previous `?KeyCode` state and dispatches the existing
`KeyboardEvent` with the key's value, including recognized repeated keys.
The gameplay-facing `Input` facade is unchanged.

### Approved compatibility correction

S3 uses canonical comparison for `isKeyPressed()` with maintainer approval.
Previously that method compared raw terminal bytes with enum values, so special
keys such as Up and Enter always returned false even when `isKeyDown()` returned
true. These keys now report pressed consistently for terminal and renderer input.
This is an explicitly approved correction to the legacy behavior, not a preserved
quirk. No special-key whitelist or terminal-byte leakage is needed in InputManager.

The normalized contract also excludes terminal bytes outside the existing enum:
these yield null rather than dispatching an event whose `getKey()` is null.
All supported terminal key mappings are retained. Bindings remain case-sensitive;
axes, `isButtonDown()`, and `isAnyKeyPressed()` remain edge-triggered. Repeating
the same key is pressed but not a new down edge. Changing A directly to B is not
an A release; `isKeyUp()` still requires a following no-input sample.

## TerminalInputSource

The terminal source contains the former manager's pending-byte buffer, escape
completion, splitting, and translation. It retains arrow keys, letter case,
CR/LF Enter, Space, Escape, Tab/Shift-Tab, both Backspace bytes, existing Home/End,
Insert/Delete/Page sequences, and F0-F12 mappings. It does not add previously
unsupported function-key sequences just because the enum has additional cases.

The existing four-attempt, 1 ms escape-completion window and concatenated sequence
ordering are retained. An injected stream supports deterministic tests without
touching real STDIN; ordinary callers omit it. The stream remains caller-owned.
Reads temporarily make it nonblocking and restore its prior blocking flag.
Existing engine echo/cbreak/cursor/terminal-lifecycle APIs are unchanged.

## Selection and reset

```php
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;

InputManager::setInputSource(new RendererInputSource($client));
```

`getInputSource()` lazily creates a terminal source. `init($game)` retains an
explicitly selected source. Selecting a source clears current and previous keys,
so the old source cannot cause a phantom press/release. Selection does not drain
either source or terminate a renderer; use `resetState(true)` when draining is
intended. `resetState()` always clears both manager states and delegates its flag.

| Source | `reset(false)` | `reset(true)` |
| --- | --- | --- |
| Terminal | Clear parsed pending bytes | Also drain unread stream bytes without waiting |
| Renderer | Clear client-cached keys | Also discard keys from one bounded upstream poll |

Terminal draining has a 64 KiB budget; excess input fails explicitly instead of
trapping a scene transition behind a continuously writing peer. Renderer draining
does not promise to discard keys arriving later or beyond the S2 per-poll budget.
Both renderer reset modes retain queued ready, close, and error events. Neither
reset nor input-source destruction shuts down the renderer.

## Shared renderer connection

`Rendering\RendererClient` is the sole consumer of its transport's `pollEvents()`.
It delegates `start()`, `send()`, `isRunning()`, and `shutdown()` to S2 rather than
duplicating handshake or process control. The application owns this shared client
and explicitly shuts it down; the input source only borrows it.

`pump()` performs one zero-wait transport pass. Keys enter an ordered string FIFO;
ready, close_requested, and error enter a separate typed-event FIFO. `pollKey()`
and `pollEvents()` pump when their respective queue is empty. Applications must
consume non-key events as well as keys; no close/error is interpreted as a game
action or automatic quit policy. Shutdown retains final transport events and is
safe to repeat.

Default bounds are **1024 keys**, **256 non-key events**, and **8 MiB combined
key/message payload bytes**. Count limits also bound fixed event/queue overhead.
Limits are constructor-injectable. An over-capacity batch raises an explicit
`RendererTransportException` before changing prior queues; that rejected batch
is not silently accepted. The failure is latched, while already queued events
remain readable. Underlying fatal S2 exceptions propagate likewise.

`RendererInputSource` converts client key strings with `KeyCode::tryFrom()`, not
another mapping table. An unsupported identity raises `RendererProtocolException`
with a bounded diagnostic. S2's wire parser remains independent of engine enums.
Consuming or resetting keys never steals lifecycle/error events from the client.

Future presentation code must share this same client, not independently poll its
transport. The standalone S2 smoke tool still owns its own separate connection.

## Native smoke tool

Build the unchanged reference renderer with `cargo build --locked`, then run:

```sh
php tools/gpui-input-smoke.php \
  --renderer=/absolute/path/to/gpui-renderer \
  --asset-root=/absolute/existing/assets \
  --duration=60
```

A blank native grid is expected. Native keys are reported independently from
renderer lifecycle events:

```text
RENDERER ready
INPUT key=up KeyCode=UP
INPUT key=W KeyCode=W
RENDERER close_requested
```

Enter in the launching terminal requests protocol shutdown. Enter, Escape, and q
in the native window are only keys, not quit actions. Native close is surfaced
separately before cleanup. The duration defaults to 60 seconds (allowed 0-300).
Lifecycle diagnostics go to stderr. The tool generates no frames or game actions.

See the [S3 validation and handoff record](s3-validation.md) for tested boundaries
and the approved compatibility correction. Runtime selection, presentation,
and Last Legend integration remain out of scope.
