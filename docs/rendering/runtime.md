# Game renderer startup

Terminal-only is still the default. The optional renderer runtime connects
process transport, input and retained presentation to the PHP Game loop.
No renderer is started merely because a project authors graphical sprites.

## Automatic startup

Console selects a stable public renderer ID and sends it to the ordinary PHP
game entrypoint in `ICHILOTO_RENDERER`. Current IDs are exactly `terminal` and
`gpui`. The engine reads the value once while resolving initial geometry, trims
whitespace and lowercases it. An absent, empty or whitespace-only value means
`terminal`, so direct `php game.php` launches retain existing behavior.

```sh
ichiloto play --renderer=terminal
ichiloto play --renderer=gpui
ichiloto play --gpui-renderer
```

`ichiloto play` in an interactive terminal offers Native Terminal and GPUI.
Console communicates only the selected ID, including for new tmux sessions;
the engine does not know which flag or prompt produced it. No renderer binary
path is accepted by this public contract. Reattaching an existing tmux session
does not restart that game or change its renderer.

An ordinary project bootstrap is sufficient:

```php
use Ichiloto\Engine\Core\Game;

(new Game('My game'))->run();
```

`Rendering\Launch\RendererRegistry` maps IDs to immutable descriptors with
runtime factories. Terminal returns no external runtime and does not inspect
renderer packages. GPUI resolves an installed implementation and constructs
`RendererProcessConfig`, `RendererRuntimeConfig` and `RendererRuntime` internally.
Its registration uses 10x20 pixel cells, the existing v2 protocol, and the
project's canonical `assets` directory under the launch working directory.
Logical dimensions come from the Game, not from Console or binary discovery.

The automatic GPUI registration requires the negotiated
`sprite_source_rect` capability. An older installed renderer fails startup clearly
rather than displaying an entire sprite sheet. Source-development launches through
Console offer matching renderer updates as described below. Explicit programmatic
runtime configurations retain an empty requirement list by default for legacy full-image integrations;
sheet users must request the capability as described in [sprite sheets](sprite-sheets.md).

Automatic GPUI startup also requires v2 `tile_batches`, a historical capability
name that no longer carries map tiles. An older binary must fail clearly before frames are sent.
See [the retained world contract](tile-batches.md).
Retained operations and camera transforms are core V2 behavior, not a new
capability flag. Historical capability names do not enable stateless frames.

## Field zoom

The graphical field keeps the terminal's grid: each terminal cell is drawn as a
`FieldViewport::CELL_WIDTH` x `CELL_HEIGHT` (24 x 48) logical-pixel box, the
terminal's own tall shape, so a step covers one cell in both. A cell shows one
column of an autotile's quarters; RPG Maker's 48-pixel plain tiles and
character frames are two cells wide, centred on their cell. A map without
graphics shows each cell's text in its box. The camera shows as many whole
field cells as the session surface holds, centred. See the
[graphical field plan](../graphical-field.md).

Projects may set `graphics.field.zoom` in `config.php` to a number from 1 to 8
(default 1), a display scale applied to the field cells. The Editor exposes
this as **System > Field zoom**. This scales the field's terrain, decorations,
terminal fallback glyphs and graphical actors together. Dialogue, notifications, menus and battle canvases keep their existing
sizes. Zoom does not change world coordinates, collision or movement speed.

Camera transforms are part of the retained V2 baseline. The historical
`frame_viewport` support symbol may still be advertised, but Runtime and Presenter
do not gate zoom on it. The older native 1x fallback is removed; an incompatible
renderer needs a matching update. Native Terminal stays at its normal scale.
PHP reduces the field camera to whole cells, keeps small maps centered and
follows the player across larger maps. Zoom never gives the renderer camera authority.

A retained `viewport` carries `scale`, pixel `origin` and `clipRect`, plus
`worldOrigin: {column, row}` and an optional `worldId`. The selected world's
glyphs and tiles use world coordinates: subtract the cell origin, then scale
and clip. Explicit `textLayerIds` and `spriteIds` select screen-space items that
receive only scale and clip; their camera subtraction already happened in PHP.
Unlisted screen items remain unchanged. There is no `tileBatchIds` wire field.
A screen-only viewport omits `worldId` and uses a zero world origin.

The player's field-action prompt is also a viewport member, even though its
paint priority places it above character sprites. Its position follows the
current graphical sprite height and the unscaled session cell height. The
player owns and clears that text layer when moving, losing the action, or
leaving presentation; it never relies on terrain repainting to erase it.
Location HUDs and dialogue remain unscaled screen-space UI.

Omitting `viewport` from a delta retains its previous value; `viewport: null`
clears it. Runtime clears it when leaving field presentation. Canvas and an active
viewport are mutually exclusive. These transforms precede physical window fitting.

## Application icon

A game may name its application icon in `ichiloto.json`, as a PNG or ICNS
path relative to the assets:

```json
"icon": "Graphics/System/Game.icns"
```

A graphical renderer shows it in place of its own, for example in the macOS
Dock, from the start of the session. The renderer is shared by every game, so
the icon belongs to the game rather than to the renderer bundle. A missing,
unreadable or unsupported icon is reported and the renderer keeps its own; it
never stops the game. The terminal has no application icon. The Editor does
not yet expose this setting.

## Development renderer updates

Before starting a new graphical game process, Console's `ichiloto play` checks
the renderer declared in `resources/renderers/development.json` without building,
but only when the project's resolved Engine is a Git source checkout outside its
vendor tree. Composer's vendored Git clones do not activate this development path.
The declaration names the source directory relative to itself and a PHP builder relative to that
source. It is excluded from exported Engine archives. No executable search on
PATH or implicit sibling-source discovery is involved.

The renderer builder's read-only `--describe` mode describes its current build
inputs. Console compares that fingerprint and the installed payload with its
installation receipt. When an update is available, interactive play offers:

- **Update now:** build a release package and install it through the verified
  package installer before attempting launch.
- **Continue:** attempt this launch with the current installation, without
  changing the update preference.
- **Skip this version:** suppress this exact source fingerprint for the selected
  renderer and host platform. A different fingerprint is offered again.

Non-interactive play reports an available update and continues without prompting
or building. `ichiloto renderer:update` explicitly requests the update, including
a skipped version. Successful installation clears its skip preference. Update
checks do not create installation directories, acquire a write lock, or build.
Only an explicit update serializes package creation and installation. Failed
checks, preference writes, or updates produce a warning and never prevent play
from attempting the selected renderer. An unsuccessful update preserves the
previous installation. This does not bypass runtime protocol or availability
checks, and does not silently switch the selected renderer to Terminal.

**Removed behaviour:** automatic rebuilding during play and refusing launch
because renderer preparation failed are removed from the development workflow.
Build fingerprints cover renderer source and packaging, not game artwork or PHP
gameplay edits. They identify update versions, not runtime asset identity.
Terminal launches and existing tmux-session reattachment skip update checks.
WSL uses the Linux host target.

Direct PHP entrypoints still consume an already installed renderer; they do not
invoke Console or a compiler. Ordinary Composer distributions do not activate
source updates, download binaries, or require Rust. Delivery of compatible
prebuilt packages with those distributions remains separate unfinished work;
the development updater is not a published player-update service.

## Optional graphical surfaces

`hello.requiredCapabilities` is the startup minimum, not a feature allowlist.
The V2 renderer advertises its supported drawing capabilities in `ready` and
accepts frames using those capabilities. The Engine retains recognized advertised
features even when they were not required. Installing a menu or title theme does
not add startup requirements. Retained-compatible renderers without an optional
surface remain usable; that surface uses its terminal presentation. This is not
a fallback to the removed native V1/stateless protocol.

`window_activation` changes the incoming event stream and remains explicitly
opt-in through the existing requirement list. It is not a prerequisite for title
art or rolling credits. Without that subscription, scene/modal pausing still
works, but native window-focus pausing is unavailable. The Hello requirement
list remains the subscription boundary.

**Removed behaviour:** `eb5e19e` removed automatic pause-on-unfocus for title
animation and rolling credits when it removed their activation requirement to
decouple graphics availability from startup. Normal launches no longer subscribe
to activation events; `windowActive` consequently remains true. This was a
behaviour removal, not merely a capability fallback correction.

**Proposed, not implemented:** restore pause-on-unfocus through an optional
activation subscription requested only after the renderer advertises support.
It must never become a mandatory startup capability. Older renderers must remain
usable, and renderers must not send unsolicited events to older clients. This
proposal needs approval and a compatible subscription contract before implementation.

`PackagedRendererExecutableResolver` is the sole owner of the installation
manifest layout and platform lookup. It resolves only a readable installed
manifest entry naming an executable within that package. See the
[internal packaging boundary](../../resources/renderers/README.md) for packager
and test details. This is not project gameplay configuration, executable search
on PATH, a binary download, or a public renderer-path environment variable.

Unknown IDs fail with the supplied ID and the valid IDs. An unavailable GPUI
package, unsupported installation/platform, or invalid manifest raises
`RendererUnavailableException` with a renderer-availability explanation.
Neither case silently falls back to terminal. Normal Game startup routes these
errors through its existing crash log and notice.

## Programmatic precedence

An explicitly attached `Game::useRendererRuntime()` is authoritative, even if
the environment contains another or invalid ID. Otherwise the engine resolves
launch intent; absent intent means terminal. Selection precedes runtime startup
and the decision to claim STDIN raw/nonblocking modes. No second runtime is
created for an explicitly attached session.

```php
$game->useRendererRuntime($embeddedRuntime)->run();
```

This API remains for tooling, tests and custom embedding. One runtime can be
attached before input startup and owns one session. Tests can inject a
`RendererRegistry` into Game, descriptor
factories into the registry, or a `RendererExecutableResolverInterface` into the
default registry. Ordinary unit tests need no installed Rust binary.

The graphical runtime requires protocol V2; `RendererRuntimeConfig` rejects V1.
Native V1 frames and stateless V2 full-frame payloads have been removed. Low-level
V1 envelopes and snapshot encoders remain for reference tooling, not as a native
fallback. Direct tile-batch presentation is also removed: supply a retained world
through the `world` argument instead.

## Fixed geometry

The Game's resolved logical dimensions become the session grid. Graphical
sessions default to `BattleScreen::WIDTH` by `BattleScreen::HEIGHT`: 135x36
cells, including the battlefield and its bottom controls. At the registered
10x20 cell pitch, that is a 1350x720 logical-pixel canvas. The main menu's
110x35 layout fits within the same surface. The launching terminal's dimensions
do not change this default, and switching scenes does not resize the grid.

Explicit flat or nested width/height options are honored per axis, even when
equal to legacy constructor defaults. Non-default positional dimensions also
remain supported. A dimension left on auto uses the battle footprint for a
graphical session and the capped available dimension for a terminal session.
Caller requests are retained separately from resolved dimensions, so attaching
a runtime after construction does not turn terminal measurements into explicit
graphical overrides. All registered camera viewports are synchronized before
the renderer handshake, including scenes that have not started yet.

Explicit smaller grids can still clip authored layouts inside Console; an
80x30 override cannot contain the battle UI. Cell dimensions change display
size, not the number of available layout cells. The physical GPUI window remains
resizable and scales/centers this fixed canvas rather than changing its grid.
Maps larger than the viewport continue to scroll through the PHP-owned Camera.

The graphical grid is fixed until the session ends. Later terminal resizing does
not change Game, Camera or protocol geometry. Terminal-only sessions use the
bounded dynamic resize path below. Neither protocol negotiates a new logical grid
on resize; GPUI's existing viewport fitting is presentation-only.

### Native terminal cap

Native terminal sessions are limited to **135x36 cells**, using the same
`BattleScreen` dimensions as the graphical default. Each axis is the minimum
of the caller's resolved request, the physical terminal dimension and the battle
dimension. This cap applies to explicit terminal sizes too; smaller requests
remain effective through terminal shrink/regrow cycles. Graphical overrides
are not capped by this terminal policy.

Startup and the existing throttled resize check use the same resolution rule.
Only changes to the effective grid reset the buffer, update all camera viewports
and notify the current scene. Resizing between two larger physical terminals
does not trigger those operations. The engine does not resize the terminal
window to match its logical drawing area; unused terminal space stays unused.
Low-level physical size probes still return the actual terminal dimensions.

The complete logical viewport, including empty cells, is centered on both axes.
Its zero-based physical origin is `floor(max(0, physical - logical) / 2)` per
axis. A 135x36 grid in a 186x38 terminal therefore starts at (25, 1), leaving
25 columns on the left, 26 on the right and one row above and below. Only the
terminal output boundary applies this origin; buffers, Cameras, coordinates and
graphical snapshots stay logical. Direct writes, batched frames and legacy
one-based absolute Cursor operations share the same translation.

A physical resize clears stale margins and replays the canonical screen even
when the capped logical dimensions or rounded origin stay unchanged. It uses
the existing quarter-second size probe, not additional probes per draw. Blocked
dialogue/timer frames also refresh margins once composition has finished,
without re-entering scene updates. Logical resizing waits for normal gameplay
to resume while a blocking operation owns the layout; shrinking the physical
terminal below that retained layout can temporarily clip it.

Maps larger than this area scroll normally. A physical terminal smaller than
135x36 uses its available space, but cannot display the complete fixed battle
layout; the cap does not add scaling or a small-screen layout.

## Output ownership

Game construction is buffer-only. At startup, after renderer selection and any
explicit runtime attachment, Game selects physical Console output independently
of input ownership. Native terminal sessions enable terminal output; external
renderer sessions keep canonical cells and named layers but do not mirror frames,
emit terminal controls, open a terminal output descriptor, or probe emoji with
cursor-position queries. Errors still reach the existing logs and stderr notice.

`Console::setTerminalOutputEnabled()` must run before a frame or alternate screen
is active. With layer tracking enabled and terminal output disabled,
`Console::recomposeFrame()` uses sparse authored rows and lazy empty defaults,
not full-grid blank allocation or visible-cell snapshots. It preserves rollback,
colours, wide-glyph masking and explicit snapshot/buffer semantics. T1 retains
its existing output and dirty-span path. Shutdown does not
reenable terminal painting; a later terminal Game selects its own output normally.

Styled cells retain active SGR attributes rather than a growing history of
obsolete colour assignments. Selective resets preserve unrelated attributes;
extended colour components remain grouped. Unknown controls retain their prior
replay behaviour until a full reset.

## Project artwork and saves

`Field\PlayerPresentationConfig::load()` reads the existing
`assets/Data/Entities/player.php`. Its `sprites` key remains terminal art. Its
optional `sprites2d` key names an RPG Maker character sheet, documented in
[field character sheets](sprite-sheets.md). Missing means no graphical
character; malformed data, including explicit null, is reported and keeps the
terminal sprite rather than stopping the game.

New-game loading uses the same project presentation loader for terminal art.
GameScene's shared Player construction path loads current graphical definitions
for both new and restored games. Position, heading and saved terminal fields
still come from GameConfig. No graphical definitions enter save serialization,
and changing artwork requires no save migration.

## Scene ownership and composition

`GraphicalSpriteProviderHostInterface` is optional, not a new requirement on
every SceneInterface. The collector asks the active host for providers, ignores
null definitions and uses the shared projector and PHP Camera. IDs must be
unique; the field Player is always `player`.

GameScene exposes the active Player and visible, unsuppressed NPCs while its
field state owns presentation. Cinematic providers use staged actors and suppress
the matching ordinary owner, restoring it when staging ends.
Ordinary event dialogue borrows input without replacing the field, so the PNG
remains visible during dialogue. This also applies to safe blocked-timer ticks.
Title, menu, item, records, controls, map, shop and battle screens receive no
ordinary field sprite unless the active presentation explicitly provides one.

Off-grid providers still reach GPUI for protocol clipping. Snapshot masking is
limited to providers whose projected anchor is inside the logical grid; an
off-grid provider cannot accidentally mask a terminal edge cell. No second
world visibility or camera algorithm is introduced.

Player, NPC and staged-actor rendering still provide terminal fallbacks. Each
owner draws inside its named Console layer. Optional Console layer tracking
records the existing canonical cells below that draw. The actual field
compositor remains the sole source of map, event-cue and NPC ordering.

`Console::getRetainedPresentationChanges($excludedLayers, $reset, $excludedWorldLayers)`
returns changed rows, stable layer metadata/order and explicit removed IDs.
Graphical sprites exclude only their own named fallback. Exclusion restores
recorded underlay, not unconditional spaces,
using Console's existing wide-cell representation. Multi-row and wide glyphs
retain their footprints. Later ordinary writes invalidate provenance at the
cells they overwrite, even if they write the same glyph, so masking preserves
later overlays. Recomposition rollback restores provenance with the buffer;
clear/resize/recomposition discard stale history. Retained history is bounded
by the grid and named layers, not the number of frames.

Only the optional runtime enables this tracking. Unchanged frames do not build
a full snapshot or scan every cell. `presentationSnapshot()` and `snapshot()`
remain explicit reference/testing APIs; capturing them does not paint or mutate
gameplay. Snapshot inputs to Presenter are converted to retained styled rows,
not sent through old native wire formats.

A valid retained world uploads map glyph/tile rows in world coordinates once
and bypasses Console map drawing. If no valid world can be constructed, the field
continues through Console. This includes the compiler's internal 64 MiB source
budget: it diagnoses failure, retries without atlas definitions when applicable,
then uses screen-space retained text if a glyph-only world also cannot fit. Cell
count or successful pipe delivery alone is not proof of native resource validity.
See [world source accounting](presentation.md#internal-world-source-budget) and
[world bounds](tile-batches.md#bounds-and-atomicity).
`setRetainedWorldPresentation(true)` omits only
synthetic base blanks; explicit authored spaces, non-map base text, named UI and
overlays remain screen-space content. Camera restoration uses
`removeWorldCellContributions()` to erase stale dynamic cells without writing an
opaque blank over the retained map. Named-owner removal is preferable when only
one overlapping actor should be removed. See [styled presentation](styled-presentation.md).

V2 additionally preserves structured foreground/background colour and sparse
named UI layers. `PresentationLayerPolicy` reserves world text at 0, world
sprites at 0..999, ordinary UI at 1000, existing FIELD_HUD at 1010 and MODAL at
1020, notifications at 2000, and transition cover at 3000. Automatic runtime
composition rejects sprites outside the world range; the generic sprite DTO
still permits the protocol's signed i32 layer range. Explicit UI spaces paint
opaque cells above sprites; missing UI cells are transparent. See
[styled presentation](styled-presentation.md) for authoring and extraction rules.

## Input, waits and shutdown

One RendererClient is shared by RendererInputSource and RendererPresentation.
Game pumps lifecycle, polls PHP input, updates PHP simulation, renders dynamic
Console contributions, then presents changed rows, providers and any retained
world. A valid retained world replaces Console map draws only in GPUI. Rust receives
no movement, collision, event, heading, camera or save authority.

After enqueueing retained-update bytes, Runtime performs one bounded, zero-wait I/O
pass before returning to Game/Timers' sleep. A writable small frame begins delivery
in the same iteration. Large uploads resume over later presentation calls with
bounded zero-wait servicing inside the sender; only the final `present:true`
publishes the new state. The producer targets 1 MiB/32 packets and at most 32 I/O
passes per call, caps pending bytes at 1 MiB (one larger indivisible record only
on an empty queue), and allows at most 32 unacknowledged packets. Existing line
and transport capacity limits are unchanged. See the exact
[upload budgets](presentation.md#nonblocking-upload-and-coalescing).

Backpressure is a `trySend(false)` deferral, not a gameplay error or blocking wait.
Runtime keeps calling present even for unchanged content, allowing pending work
to advance; new desired changes coalesce behind the current atomic transaction.
The present return value means bytes were queued, not upload completion, so false
must not be used to stop a pending drain. Generic presentation tools can use
`RendererPresentation::hasPendingUpload()` without inspecting internals, and must
continue servicing input/feedback. Cleared pending upload state is distinct from
zero pipe bytes or a final presentation ACK. Once complete and unchanged, no new
bytes or extra changed-send pump are needed.

`InputManager::requiresTerminalInput()` centralizes input-mode ownership. GPUI
input does not claim STDIN raw/no-echo/nonblocking modes. Terminal input keeps
its existing setup. Cleanup restores only input modes the Game actually claimed
and reinstalls the previous input source when the renderer still owns it.

Blocked waits update lifecycle/timers/audio/notifications, then let an optional
caller draw, then present the completed Console frame before sleeping. They do
not update the scene recursively. Native close is a persistent lifecycle signal
that unwinds waits into ordinary Game quit without an input binding or prompt.
Renderer errors and transport failures reach the existing crash log/notice path.
Explicit Game cleanup shuts down audio and the renderer on normal quit, native
close, exceptions, PHP shutdown and supported signal paths. Cleanup is idempotent
and reuses bounded process shutdown rather than relying on destructors.

## Retained recovery

Every packet names its `baseGeneration` and a newer `generation`. Native ACKs
distinguish accepted staging from a presented state. Runtime forwards ACK progress
to Presenter. `frame_rejected` is logged without changing gameplay. Runtime
invalidates once per drained rejection/resize batch and forwards its highest
`expectedGeneration`. The next reset advances beyond both that expectation and
the local generation, including when the receiver is ahead. Capacity may defer
or stage this resend across calls; it is not a synchronous full upload.
An actual send failure also invalidates desired delivery for retry/restart but
still propagates through the existing failure path. It is distinct from
nonthrowing temporary backpressure.

If pending delivery makes neither partial-write nor new ACK progress for
`RetainedPresentation::ACK_TIMEOUT_SECONDS` (2.0 seconds), the next present
requests a reset even if nothing changed. This covers a dropped final packet
without a later delta provoking rejection. The monotonic deadline is not extended
by repeated/pre-reset ACKs. Queued partial lines retain their ordering; a stalled
pipe is not filled with repeated complete resets.

A native `resized` event requests one full resend on the next present, without
changing the logical grid. `RendererRuntime::restart()` explicitly cleans up the
old peer, discards old-session keys/feedback, and starts the same session
configuration with generation 0 (first packet 1). Desired sprites, text, world and
canvas remain, as do Console, gameplay and the input-source object; keys arriving
from the new peer are retained. This is not an automatic child-restart policy. See
[process transport](process-transport.md) for feedback and bounded cleanup.

## Optional latency diagnostics

Set `ICHILOTO_ENGINE_TRACE=1` when launching the ordinary CLI to append NDJSON
observations to the private project's `logs/latency.ndjson`. Leave it unset for
normal play. Tracing uses `hrtime(true)`, not wall time, and changes no protocol
fields. Records identify the PHP process, iteration, input identity and stage.
Input records contain key identities, so treat the log as diagnostic data.

The internal `Diagnostics\LatencyTrace` observes transport parse/queue/dequeue,
source return, input acceptance/KeyboardEvent dispatch, update/render boundaries,
snapshot/style/run costs, sprite collection, payload comparison, JSON encoding,
frame enqueue and byte draining. Queue depth/oldest observed age and per-iteration
consumption counts are diagnostic only. Ages begin at PHP's first observation,
not physical key-down. Synthetic transports have no native parse timestamp.

Records are buffered with a 4096-record cap and flushed to the log at frame/wait
boundaries; dropped records are marked. Logging still has observer overhead and
must be considered during native comparisons. Nothing is written to normal stdout.
`LatencyTrace::configure()` supplies internal test sinks/clocks, not a gameplay API.

Renderer-relative `Instant` timestamps cannot simply be subtracted from PHP
`hrtime`. Native-to-PHP measurements require a matching monotonic-clock anchor
and an explicit uncertainty bound. These diagnostics are not proof of native
presentation latency or cross-platform correctness on their own.

## Geometry boundary

The logical game surface, native terminal dimensions, and graphical renderer
viewport are different concepts. GPUI resize is presentation-only: it must not
change Console dimensions, Camera geometry or the session grid. Terminal mode
caps its existing size-probe results as described above. Larger maps still scroll through the
PHP-owned Camera rather than becoming larger protocol grids automatically.

Future configuration may select logical resolutions, preferred window size,
resizability, scaling policy or fullscreen independently. Current renderer defaults
are not permanent restrictions on developers/players. Those preferences and
public window-preference APIs are not implied by resize feedback. The current
`resized` event requests presentation resynchronization, not a new logical size.

## Presentation limits

- Optional graphical actors, terrain and canvases retain their existing authoring,
  validation and terminal fallbacks. Invalid optional artwork must be diagnosed;
  retained transport does not make an unsupported asset valid.
- GPUI fits text to measured font advance and line metrics inside fixed cells.
  This supersedes the earlier undersized-font limitation without changing grid
  dimensions, sprite geometry or viewport fitting. Very small viewports still
  reduce the entire surface; fitting cannot guarantee legibility at every size.
- Retained text preserves colours, not blink, bold weight, italic, underline or
  other terminal attributes. Native V1/stateless fallback is not available.
