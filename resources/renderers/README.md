# Internal renderer installation boundary

This directory is an Engine packaging boundary, not project configuration or
an end-user installation interface. `PackagedRendererExecutableResolver` alone
owns its layout. Console and game scripts pass renderer identities, never paths.

A packager stages implementations under `installed/` and writes
`installed/manifest.json`. That generated installation directory is ignored by
Git; binaries and machine-specific manifests must not enter source commits.
There is no default download, checkout-relative search or PATH fallback.

A clean Engine checkout therefore contains this README, not a native renderer
or a Rust project. Cargo commands belong in the separate `ichiloto/gpui-renderer`
source repository and are developer instructions, not a player setup workflow.
As audited on 14 September 2026, there is no published renderer release or
Console installation command to provision this boundary. Player delivery remains
unfinished: it must supply a compatible, verified platform package without
requiring players to install Rust or build tools. See the
[integration roadmap](../../docs/rendering/integration-roadmap.md).

GPUI gameplay, packaging and performance validation must use the optimized
release renderer built with `cargo build --release --locked` in its own
repository. Debug builds are for development and diagnostics, not representative
gameplay performance. Stage the release executable with its required platform
bundle resources; this boundary does not build or download it automatically.

Manifest version 1 maps renderer IDs and platform IDs to executable paths
relative to the manifest. For the currently validated Apple Silicon build:

```json
{
  "version": 1,
  "renderers": {
    "gpui": {
      "darwin-arm64": "gpui/darwin-arm64/Ichiloto Renderer.app/Contents/MacOS/gpui-renderer"
    }
  }
}
```

Only actually installed/validated implementations belong in a manifest. Host
identification normalizes the OS family and arm64/aarch64 or x86_64/amd64 names;
recognizing a platform does not imply that a binary for it exists. Missing
platform entries fail with a renderer-availability error. Absolute paths,
parent traversal, and executable symlinks escaping the package root are rejected.
WSL/WSLg uses the Linux entry with Linux PHP and a Linux renderer, not a Windows
executable. Native Windows process transport is a separate
[unsupported boundary](../../docs/rendering/process-transport.md).

The Renderer window's earlier blanket non-macOS rejection has been corrected
using shared GPUI maximize/restore handling. The accepted optimized canvas/UI
renderer is now installed at this boundary locally, preserving the existing
application identity and manifest. It has macOS validation only, not Linux/WSLg
or native Windows execution acceptance, and is not a published package. See the
[current graphical battle record](../../docs/rendering/graphical-battle-g1.md).

Preserve a platform bundle and its resources when staging it. On macOS the
existing GPUI application includes its Info.plist for native application
identity; its executable speaks the existing stdin/stdout protocol directly.
Process launching and protocol remain the existing Engine runtime's
responsibility. The session fixes the legacy grid; negotiated graphical canvases
have their own logical dimensions. Cell defaults belong to the GPUI registration
(10x20), not to the manifest or Console.

Tests inject a temporary manifest/platform or resolver and use PHP-only/fake
transports. Native development acceptance may stage the built GPUI bundle here,
but must still launch through the same `ICHILOTO_RENDERER=gpui` contract. No
vendor or project source modification is necessary.
