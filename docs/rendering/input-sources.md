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

Sources that know physical key state also implement `HeldInputSourceInterface`
(see [key transitions](#key-transitions-implemented)); the two methods above
stay their complete event-only contract.

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

Controls stores changed keyboard keys under `input.bindings` in the player's
`.data/player-settings.json`. The authored `input.php` supplies action identities,
descriptions, controller metadata and default keys. At boot, the Engine applies
valid player key overrides to known, rebindable actions; Restore Defaults clears
those overrides. Neither action rewrites `input.php`.

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
The event-only stream cannot distinguish deliberate repeated taps from OS repeats.
A `key_transitions` session supplies repeat identity for held state only (see
[key transitions](#key-transitions-implemented)); its key stream is unchanged.
A burst can queue behind the frame cadence.
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

## Controller-ready input and normalized movement

The sections above describe the event-only source contract every source
keeps. Held keyboard walking in the graphical field is implemented on top of
it; diagonals, physical controllers and analog input remain planned. The
[integration roadmap](integration-roadmap.md#controller-ready-input-and-normalized-movement)
tracks the remaining work.

### Ownership and compatibility

Gameplay and UI consume semantic actions, navigation/focus and input values.
Control hints remain separate from action identity and artwork, preserving
keyboard bindings while permitting controller glyphs and remapping. Shared
contracts must accommodate device/control identity, press/release/held state,
simultaneous controls, analog magnitude, focus/connection changes and coexistence.
Future gamepads must not be permanently modeled as synthetic keyboard presses.
PHP owns bindings, action contexts, movement and timing; native sources report
normalized controls. No render callback drives gameplay.

### Key transitions (implemented)

`key_transitions` is a protocol 2 event subscription, requested like
`window_activation` in `hello.requiredCapabilities`; the Game's GPUI session
requires it. Sessions that do not request it receive exactly the legacy `key`
events. A subscribed session receives every key-down as before, OS repeats
included, now with a stable `control` identity and a `repeat` flag, plus two
new events:

```json
{"protocol":2,"type":"key","key":"S","control":"s","repeat":true}
{"protocol":2,"type":"key_release","control":"s"}
{"protocol":2,"type":"input_reset"}
```

The control names the physical key independently of its text: case, Shift and
Caps Lock change `key`, never `control` (Tab and Shift-Tab are control `tab`).
The renderer tracks held controls itself, because GPUI's `is_held` is false
for keys macOS routes through text input (the arrows): a key-down for a held
control is a repeat and creates no press. A key-up releases its control
whatever modifiers are down. Platform shortcuts stay ignored. The renderer
sends `input_reset` when the window loses focus, and when Command engages
while keys are held, because macOS withholds key-up events under Command.

`RendererClient` queues presses, releases and resets in a bounded transition
FIFO beside the unchanged key FIFO; repeats stay event-only keys. Unsubscribed
transitions and unidentified keys in a subscribed session are protocol errors.
`RendererInputSource` implements `HeldInputSourceInterface`: `poll()` still
yields every key for edge consumers, and `drainTransitions()` returns
`KeyTransition` values. The terminal source stays event-only and never claims a
release; `InputManager::isHeldInputAvailable()` is false for it.

Each `InputManager::handleInput()` applies the transitions the runtime's pump
received, in order, to a bounded `KeyHoldState` before the gameplay update. A
held control keeps the key code it typed when pressed, so a modifier change
never strands it. Each press gets an increasing order, and press edges survive
a release in the same update, so quick taps are kept. The new queries are
separate from the existing edges: `isButtonHeld()`, `wasButtonPressed()` and
`getButtonPressOrder()` cover every binding of an action, while `isButtonDown()`,
`isKeyDown()` and axes keep their event-only meaning, so menus and their
navigation repeat are unchanged. Held state is cleared by `input_reset`,
`resetState()`, source replacement, renderer restart and any input failure or
disconnect; elapsed silence is never release evidence.

### Field walking (implemented)

With held input, `FieldState` walks through `PlayerWalk`, RPG Maker MZ's
four-directional walking: the most recently pressed held direction wins and
releasing it falls back to the next most recent one still held; opposing
directions follow the same rule. Each step is the ordinary validated
`Player::tryMove()`, so collision, NPCs, gates, triggers, encounters, events
and saves behave exactly as a single step does, once per committed cell.

`Rendering\FieldMetric` is the one field metric: RPG Maker's default walk of
180 logical field pixels per second (48 pixels in 16 frames at 60 frames per
second) over 24 x 48 cells. A vertical step takes 16/60 s and a sideways one
8/60 s, one apparent speed rather than one cell frequency. It is measured
before field zoom, device scale and window fit, so none of those change
gameplay speed. From standing, a press faces and steps at once. While a step
is in progress a new direction waits for it: a direction change grants no free
step. A tap is kept for the next step and cannot outrun walking. Blocked
movement faces the wall each update and banks no time. The step clock carries
its overshoot, so equal time walks equal distance at any update interval up to
one step: the count differs by at most one step. After a stall at most one step
catches up. Any update the field did not control (a menu, dialogue, cinematic,
battle, map transfer) cancels walking, and every press before it is stale: a
direction still held when control returns must be pressed again.

Event-only input keeps its one step per key event with its own timing. Route
timing is unchanged: a route step presents over its own `secondsPerStep`
through `GameScene::moveAtPace()`, and wander steps walk at field speed.

### Planned

- Diagonal movement stays planned. Four-directional movement follows RPG
  Maker MZ; a diagonal mode would derive one intent from all held movement
  actions, cancel opposing directions per axis and commit one validated
  diagonal operation whose destination and both orthogonal clearances are
  valid against walls, map edges, NPCs and gates. Clearance probes have no
  movement events, triggers or encounter RNG; only the destination receives
  its effects, exactly once. How a diagonal counts for step-based systems and
  encounters must be decided before implementation, and art keeps a
  documented four-direction facing rule.
- Physical controllers, controller libraries, analog magnitude (partial walk
  speed), device identity and hot-plug remain planned; they must report
  normalized controls, not synthetic keyboard presses.
- Enhanced terminal key reporting remains outside this delivery.

### Acceptance

Deterministic tests cover held Down, adding Right, releasing Right and Down;
opposing pairs; multiple bindings; repeats; quick taps; modifier changes;
focus, reset, restart and failure; context cancellation; equal travel under
different update rates with the one-step bound; rectangular and square
metrics; blocked and stalled timing; terminal and menu edges. Retain keyboard
menu, terminal, cinematic route, T1 composition and graphical exclusion
regression coverage.

Native acceptance uses one bounded ordinary-Game GPUI pass: simultaneous keys,
partial and complete release, unobstructed horizontal and vertical travel,
corners, scrolling, focus loss and dialogue/menu/cinematic entry and return.
Measure travel relative to the field, not only a camera-followed on-screen
Player. Preserve user audio settings, saves and configuration during
validation. State tested platforms explicitly.

