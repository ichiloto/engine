# Pluggable input sources (S3)

S7-E uses this same route for v2 renderer events. Expanded identities C/c, M/m,
T/t, Tab, Shift+Tab and F5 reach existing KeyCode and binding queries without
graphical-specific action mappings. Session-version validation belongs to the
transport, not InputManager. See [S7-E validation](s7-e-validation.md) for
deterministic and real-game coverage.

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

`InputManager::init()` and `setBindings()` supply a missing `info` action for
older projects: `i` and `I` are offered only when not already bound elsewhere.
An explicit `info` entry, including empty keys, is preserved unchanged. If both
aliases conflict, Info remains discoverable as Unbound in Controls for explicit
rebinding. Info cycles two-line menu descriptions and wraps to the first page;
it does not change selection. Boot captures these effective bindings for Restore
Defaults. Loading never writes configuration; the existing explicit Controls
rebind/restore workflow retains persistence ownership.

<a id="approved-compatibility-correction"></a>
### Canonical key comparison

S3 uses canonical comparison for `isKeyPressed()`.
Previously that method compared raw terminal bytes with enum values, so special
keys such as Up and Enter always returned false even when `isKeyDown()` returned
true. These keys now report pressed consistently for terminal and renderer input.
No special-key whitelist or terminal-byte leakage is needed in InputManager.

The normalized contract also excludes terminal bytes outside the existing enum:
these yield null rather than dispatching an event whose `getKey()` is null.
All supported terminal key mappings are retained. Bindings remain case-sensitive;
axes, `isButtonDown()`, and `isAnyKeyPressed()` remain edge-triggered. Repeating
the same key is pressed but not a new down edge. Changing A directly to B is not
an A release; `isKeyUp()` still requires a following no-input sample.

## Action hint presentation

`ActionHints` resolves semantic actions to display-only `ActionHint` and
`ControlHint` values. By default it reads the live `InputBindings` on every
redraw. Compact hints show one bound control: Enter for confirm and Escape for
cancel/back when those keys are actually bound, otherwise the first bound key.
This does not remove aliases or change input handling. The Controls screen keeps
the complete binding list through `describeKeys()`.

The shared menu hint painter uses optional theme icon roles such as
`input.keyboard.ENTER`, with readable keycap labels when no matching image is
supplied. Action labels, control identity and artwork remain separate. Main Menu,
Equipment and Status consume the same mechanism; legacy window help strings
elsewhere have not all been migrated.

Themes may set `showInputHints` to false to omit persistent graphical helper
strips and their reserved layout space without changing actions or bindings.
The default remains true for compatibility. Themes can use the dedicated
Controls lookup while retaining useful action descriptions in menus and dialogs.
Controls exposes all aliases, not only the compact primary key.

A PHP input context can supply an `ActionHintProvider` and replace its display
profile without changing menu composition or dispatching input. That is a
presentation boundary, **not implemented gamepad detection or controller input**.
The current native and terminal sources still report keyboard identities only.
Display providers may select gamepad-family glyphs for previews without changing
keyboard input or establishing physical device support. Physical-controller work must drive the profile from meaningful active-device
input, handle focus/disconnection and keyboard/controller coexistence, and keep
device glyph changes independent of actions, focus and selection. Merely having
a controller connected must not make hints unusable for a keyboard player.

Remaining rollout belongs with semantic input migration: audit each legacy help
owner against the keys/actions its controller actually accepts, then reuse these
descriptors for terminal text and native glyphs. Do not globally replace strings
with misleading remappable hints for a controller that still reads fixed keys.
Editor authoring of icon-role paths must use the same project asset selection and
safe round-trip contract as other presentation artwork.

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

`pump()` performs one zero-wait transport pass. Keys enter an ordered event FIFO;
ready, close_requested, and error enter a separate typed-event FIFO. `pollKey()`
and `pollEvents()` pump when their respective queue is empty. Applications must
consume non-key events as well as keys; no close/error is interpreted as a game
action or automatic quit policy. Shutdown retains final transport events and is
safe to repeat.

`pollKey()` still returns a string; retaining the original immutable event internally
allows opt-in trace correlation without adding wire or gameplay fields.
`drainEvents()` consumes already-pumped lifecycle events without polling again.
RendererRuntime uses it after its explicit pump, keeping each I/O pass bounded.

Both terminal and renderer paths still consume one key per `handleInput()`.
They cannot distinguish deliberate repeated taps from OS repeats, because neither
contract supplies repeat identity. A burst can queue behind the frame cadence.
Do not deduplicate identities or batch only KeyboardEvents: gameplay also reads
current/previous key state once per update. See the latency investigation in
[S7-E validation](s7-e-validation.md) for evidence and the unresolved batching boundary.

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
and the canonical key comparison behaviour. The smoke tool does not run gameplay.

## Planned controller-ready input and normalized movement

**Not implemented.** The sections above describe the current event-only source
contract. This extension is queued in the [integration roadmap](integration-roadmap.md#controller-ready-input-and-normalized-movement)
for G4 field readiness after the current cinematic ownership work; this plan does not change current APIs.

### Ownership and compatibility

Gameplay and UI consume semantic actions, navigation/focus and input values.
Control hints remain separate from action identity and artwork, preserving
keyboard bindings while permitting controller glyphs and remapping. Shared
contracts must accommodate device/control identity, press/release/held state,
simultaneous controls, analog magnitude, focus/connection changes and coexistence.
Future gamepads must not be permanently modeled as synthetic keyboard presses.
PHP owns bindings, action contexts, movement and timing; native sources report
normalized controls. No render callback may drive gameplay.

Renderer must inspect the pinned GPUI key-down/up and focus APIs before Engine
and Renderer agree the smallest negotiated transition/reset extension. Preserve
legacy event semantics; do not reinterpret them silently or assume an upgrade.
Track stable control identity separately from text/case so modifier changes do
not strand held keys. Repeated down events create neither new physical edges nor
extra movement. GPUI's previously unreliable `is_held` repeat flag is not enough.
Clear held state on focus loss, connection failure and shutdown; elapsed silence
is not release evidence. Preserve platform shortcuts and text-entry separation.

Replace single-current-key state for stateful sources with bounded event/held
processing before the normal gameplay update. Preserve taps that press and
release between updates, multiple bindings for one action and event ordering.
Do not run gameplay once per input event or drain unboundedly. Keep held actions,
pressed/released edges and UI navigation repeat distinct; do not redefine every
existing `isButtonDown()` consumer as held. Terminal keeps an explicit event-only
adapter and cannot claim reliable physical key release.

### Movement and world semantics

- Derive one intent from all held movement actions. Opposing directions cancel
  per axis; Down plus Right produces a diagonal without alternating key presses.
- Use elapsed time and explicit travel distance, not OS repeat rate or painted
  frames. The stable PHP field metric must account for rectangular horizontal and
  vertical step dimensions; equal cell frequency is not equal apparent speed.
  Native scaling, DPI and resize must not alter gameplay speed. Avoid scattered
  axis-specific speed corrections. Future analog input retains partial magnitude.
- Keep integer cell commits initially, using a distance/time accumulator or
  scheduled step duration rather than truncating a normalized fractional vector.
  Direction changes grant no free step; blocked movement banks no burst; catch-up
  after stalls is bounded. Leave presentation-position interpolation separate:
  this does not deliver smooth subcell FIELD rendering.
- Commit a diagonal through one validated operation, not two cardinal moves.
  The destination and both orthogonal clearances must be valid against walls,
  map edges, NPCs and authored gates. Clearance probes have no movement events,
  triggers or encounter RNG. Only the occupied destination receives existing
  movement/event effects, exactly once.
- Document how a committed diagonal counts for step-based systems before
  implementation. Do not silently rebalance encounter frequency; encounter
  policy belongs to Engine/game rules, not Renderer.
- Follow both camera axes using the existing policy and only the necessary
  completed field recomposition, retaining T1's gains. Use existing four-direction
  art with a documented deterministic facing rule, independent of movement vector;
  a new eight-direction art batch is not a prerequisite.
- Preserve authored route `secondsPerStep`, completion and ownership. Reuse the
  validated movement boundary without replacing route timing with the new free-
  movement clock. Dialogue, menus, cinematics and battles cancel pending walking;
  returning cannot replay stale presses or confirm twice. Coordinate Player,
  input, Camera and session edits with real-subject cinematic takeover.

### Acceptance and exclusions

Deterministic tests must cover held Down/add Right/release Right/release Down;
all diagonals and opposing pairs; multiple bindings; repeat-down; quick taps and
modifier changes; focus/reset/disconnect/failure/context changes. Compare equal
travel over equal simulated time, with a documented one-step quantization bound,
under different update subdivisions and both rectangular and square metrics.
Verify resize independence, blocked/stalled timing, corner/NPC/gate/map-edge
collision, both-axis camera tracking and exactly-once destination effects.
Retain keyboard menu, terminal, cinematic route, T1 composition and graphical
exclusion regression coverage.

Native acceptance uses one bounded ordinary-Game GPUI pass: simultaneous keys,
partial/all release, unobstructed horizontal/vertical/diagonal travel, corners,
scrolling, focus loss and dialogue/menu/cinematic entry and return. Measure
travel relative to the field, not only a camera-followed on-screen Player.
Preserve user audio settings, saves and configuration during validation. State
tested platforms explicitly.

Physical controllers, controller libraries, enhanced terminal key reporting,
continuous subcell animation and broad platform ports are outside this first
stateful-keyboard delivery.
