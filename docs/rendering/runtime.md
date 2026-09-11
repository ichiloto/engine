# Optional Game renderer runtime (S6)

Terminal-only is still the default. S6 connects the existing S2 transport,
S3 input, S4 presentation and S5 sprite capabilities to the real PHP Game loop.
No renderer is started merely because a project authors graphical sprites.

## Explicit startup

```php
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;

$runtime = new RendererRuntime(new RendererRuntimeConfig(
    process: new RendererProcessConfig([$rendererExecutable]),
    assetRoot: __DIR__ . '/assets',
    cellWidth: 10,
    cellHeight: 20,
));

$game = new Game('My game', options: ['width' => 135, 'height' => 36]);
$game->useRendererRuntime($runtime)->run();
```

Supply the executable from application configuration, not an engine-specific
checkout path. Process argv, asset-root and geometry validation reuse the S2
configuration values. Startup failure is an error, not an implicit terminal
fallback. One runtime can be attached before `run()` and owns one session.

The Game's resolved logical dimensions become the protocol-v1 session grid.
Explicit width/height options are honored even when equal to legacy defaults;
omitted constructor defaults retain terminal auto-sizing. Choose a grid large
enough for the project's layouts. In particular, current battle UI requires
135x36 and the main menu requires 110x35. An 80x30 session clips those layouts
inside Console before any renderer can display them. Cell dimensions change
display size, not the number of available layout cells.

The grid is fixed until the session ends. Later terminal resizing does not
change Game, Camera or protocol geometry. The terminal is a mirror and may be
physically smaller than the logical frame. Terminal-only sessions retain their
existing dynamic resize path. Protocol v1 has no resize negotiation or auto-fit.

## Project artwork and saves

`Field\PlayerPresentationConfig::load()` reads the existing
`assets/Data/Entities/player.php`. Its `sprites` key remains terminal art. Its
optional `sprites2d` key uses the exact
`DirectionalGraphicalSpriteSet::fromArray()` format documented in
[graphical sprites](graphical-sprites.md). Missing means no graphical set;
present but malformed data fails clearly, including explicit null.

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

`Console::snapshot(['player'])` creates a separate immutable snapshot excluding
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

## Input, waits and shutdown

One RendererClient is shared by RendererInputSource and RendererPresentation.
Game pumps lifecycle, polls PHP input, updates PHP simulation, renders the normal
terminal composition, then presents its snapshot and providers. Rust receives
no movement, collision, event, heading, camera or save authority.

`InputManager::requiresTerminalInput()` centralizes input-mode ownership. GPUI
input does not claim STDIN raw/no-echo/nonblocking modes. Terminal input keeps
its existing setup. Cleanup restores only input modes the Game actually claimed
and reinstalls the previous input source when the renderer still owns it.

Blocked ticks pump lifecycle and present only completed compositions. They do
not update the scene recursively. Native close is a persistent lifecycle signal
that unwinds waits into ordinary Game quit without an input binding or prompt.
Renderer errors and transport failures reach the existing crash log/notice path.
Explicit Game cleanup shuts down audio and the renderer on normal quit, native
close, exceptions, PHP shutdown and supported signal paths. Cleanup is idempotent
and reuses S2's bounded process shutdown rather than relying on destructors.

## Spike limits

- Only the field Player is graphical. NPCs, objects, maps, battles and UI remain
  terminal presentation; placeholders are not production character art.
- Protocol v1 flattens text and draws sprites afterwards. Full sprite/UI
  occlusion and cinematic graphical parity are not implemented. Snapshot
  masking preserves later text, but overlapping PNG pixels can still cover it.
- The unchanged GPUI renderer centers individual glyphs in fixed cells with a
  conservative font size. Sparse letters and disconnected box-art strokes are
  a renderer typography limitation, distinct from an undersized logical grid.
- Terminal ANSI styling is not part of the v1 plain-text snapshot contract.

See [S6 validation](s6-validation.md) for measured acceptance status.
