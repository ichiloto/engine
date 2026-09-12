# Game renderer startup

Terminal-only is still the default. S6 connects the existing S2 transport,
S3 input, S4 presentation and S5 sprite capabilities to the real PHP Game loop.
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

Since S8-A the automatic GPUI registration requires the negotiated
`sprite_source_rect` capability. An older installed renderer fails startup clearly
rather than displaying an entire sprite sheet. Rebuild/install the matching
renderer when updating this spike. Explicit programmatic runtime configurations
retain an empty requirement list by default for legacy full-image integrations;
sheet users must request the capability as described in [sprite sheets](sprite-sheets.md).

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

This API remains for tooling, tests, custom embedding and the unchanged temporary
Last Legend spike launcher. One runtime can be attached before input startup and
owns one session. Tests can inject a `RendererRegistry` into Game, descriptor
factories into the registry, or a `RendererExecutableResolverInterface` into the
default registry. Ordinary unit tests need no installed Rust binary.

The graphical runtime defaults to protocol v2. Pass `protocol:
RendererProtocolVersion::V1` to `RendererRuntimeConfig` for the retained v1 path;
this does not change the default for low-level transport sessions. Existing
launchers that do not pin a protocol gain v2 without project source changes.

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
graphical session and the available terminal dimension for a terminal session.
Caller requests are retained separately from resolved dimensions, so attaching
a runtime after construction does not turn terminal measurements into explicit
graphical overrides. All registered camera viewports are synchronized before
the renderer handshake, including scenes that have not started yet.

Explicit smaller grids can still clip authored layouts inside Console; an
80x30 override cannot contain the battle UI. Cell dimensions change display
size, not the number of available layout cells. The physical GPUI window remains
resizable and scales/centers this fixed canvas rather than changing its grid.
Maps larger than the viewport continue to scroll through the PHP-owned Camera.

The grid is fixed until the session ends. Later terminal resizing does not
change Game, Camera or protocol geometry. Terminal-only sessions retain their
existing dynamic resize path. Neither protocol negotiates a new logical grid
on resize; GPUI's existing viewport fitting is presentation-only.

## Output ownership

Game construction is buffer-only. At startup, after renderer selection and any
explicit runtime attachment, Game selects physical Console output independently
of input ownership. Native terminal sessions enable terminal output; external
renderer sessions keep canonical cells and named layers but do not mirror frames,
emit terminal controls, open a terminal output descriptor, or probe emoji with
cursor-position queries. Errors still reach the existing logs and stderr notice.

`Console::setTerminalOutputEnabled()` must run before a frame or alternate screen
is active. Buffer-only mode skips terminal dirty-span comparisons and payload
assembly, not composition, rollback, snapshots, colours, or sprite exclusion.
This applies equally to GPUI and future external runtimes. Shutdown does not
reenable terminal painting; a later terminal Game selects its own output normally.

Styled cells retain active SGR attributes rather than a growing history of
obsolete colour assignments. Selective resets preserve unrelated attributes;
extended colour components remain grouped. Unknown controls retain their prior
replay behaviour until a full reset. See [Garden validation](garden-output-validation.md)
for the measured dense-map impact and test boundaries.

## Project artwork and saves

`Field\PlayerPresentationConfig::load()` reads the existing
`assets/Data/Entities/player.php`. Its `sprites` key remains terminal art. Its
optional `sprites2d` key uses the exact
`DirectionalGraphicalSpriteSet::fromArray()` format documented in
[graphical sprites](graphical-sprites.md). Missing means no graphical set;
present but malformed data fails clearly, including explicit null.
The alternative `mode: sheet` structure is documented in [sprite sheets](sprite-sheets.md).

New-game loading uses the same project presentation loader for terminal art.
GameScene's shared Player construction path loads current graphical definitions
for both new and restored games. Position, heading and saved terminal fields
still come from GameConfig. No graphical definitions enter save serialization,
and changing artwork requires no save migration.

## Scene ownership and composition

`GraphicalSpriteProviderHostInterface` is optional, not a new requirement on
every SceneInterface. The collector asks the active host for providers, ignores
null definitions and uses the existing S5 projector and PHP Camera. IDs must be
unique; the field Player is always `player`.

GameScene exposes the active Player while its FieldState owns presentation.
Ordinary event dialogue borrows input without replacing the field, so the PNG
remains visible during dialogue. This also applies to safe blocked-timer ticks.
Title, menu, item, records, controls, map, shop and battle screens receive no
field sprite. Active cinematics remain conservatively text-only in S6.

Off-grid providers still reach GPUI for protocol clipping. Snapshot masking is
limited to providers whose projected anchor is inside the logical grid; an
off-grid provider cannot accidentally mask a terminal edge cell. No second
world visibility or camera algorithm is introduced.

Normal Player rendering still writes terminal art. Its ordinary draw runs
inside `Console::withLayer('player', ...)`. Optional Console layer tracking
records the existing canonical cells below that draw. The actual field
compositor remains the sole source of map, event-cue and NPC ordering.

`Console::presentationSnapshot(['player'])` in v2, or `Console::snapshot(['player'])`
in v1, creates a separate immutable snapshot excluding
that named layer. It restores recorded underlay, not unconditional spaces,
using Console's existing wide-cell representation. Multi-row and wide glyphs
retain their footprints. Later ordinary writes invalidate provenance at the
cells they overwrite, even if they write the same glyph, so masking preserves
later overlays. Recomposition rollback restores provenance with the buffer;
clear/resize/recomposition discard stale history. Retained history is bounded
by the grid and named layers, not the number of frames.

Only the optional runtime enables this tracking. Capturing a snapshot neither
draws nor changes Console output, dirty state or gameplay. S4 still rejects
incomplete frames and owns duplicate suppression and frame numbering.

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
Game pumps lifecycle, polls PHP input, updates PHP simulation, renders the normal
terminal composition, then presents its snapshot and providers. Rust receives
no movement, collision, event, heading, camera or save authority.

After enqueueing a changed frame, Runtime performs one bounded, zero-wait I/O
pass before returning to Game/Timers' sleep. A writable small frame begins delivery
in the same iteration; backpressure or a frame larger than the I/O budget retains
pending bytes for later pumps. This is not an acknowledgement or synchronous
wait for native drawing. Unchanged frames do not trigger that extra pass.

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
and reuses S2's bounded process shutdown rather than relying on destructors.

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
and an explicit uncertainty bound. Current native acceptance status and exact
evidence are in [S7-E validation](s7-e-validation.md).

## Geometry boundary

The logical game surface, native terminal dimensions, and graphical renderer
viewport are different concepts. GPUI resize is presentation-only: it must not
change Console dimensions, Camera geometry or the session grid. Terminal mode
retains its existing size-probe policy. Larger maps still scroll through the
PHP-owned Camera rather than becoming larger protocol grids automatically.

Future configuration may select logical resolutions, preferred window size,
resizability, scaling policy or fullscreen independently. Current renderer defaults
are not permanent restrictions on developers/players. None of those preferences,
resize messages or new terminal resize policies is implemented by this follow-up.

## Spike limits

- Only the field Player is graphical. NPCs, objects, maps, battles and UI remain
  terminal presentation. S8-A's example replaces the earlier Player calibration
  placeholders with author-supplied directional sheets.
- Explicit protocol v1 flattens text and draws sprites afterwards. V2 fixes
  world/sprite/UI ordering and opaque UI blanks. Cinematics remain text-only.
- GPUI fits text to measured font advance and line metrics inside fixed cells.
  This supersedes the earlier undersized-font limitation without changing grid
  dimensions, sprite geometry or viewport fitting. Very small viewports still
  reduce the entire surface; fitting cannot guarantee legibility at every size.
- V2 preserves colours, not blink, bold weight, italic, underline or other
  terminal attributes. V1 remains unstyled.

See [S6 validation](s6-validation.md) and [S7-E validation](s7-e-validation.md)
for measured acceptance status.
