# Dual-Presentation Integration

This programme follows the accepted GPUI feasibility spike. It does not reopen
the spike or extend its S-numbering. Only T1 and G1 are authorized to begin;
later milestones are backlog, not implementation permission. Andrew's subsequent
cross-platform correction request covers the existing startup defect and the
installation audit, not blanket authorization for those milestones or releases.

Engine coordination owns shared Engine APIs and integration. Last Legend - Game
Design owns presentation priorities and coordinates with Last Legend - Lore.
Last Legend - Art owns placeholder and production art. Story-specific design
and acceptance stay in the private game-docs repository, not this document.

| Entry | Scope and owner | Dependencies | Status, acceptance evidence and remaining work |
| --- | --- | --- | --- |
| T1 | Retained-cell native terminal composition and scrolling. Engine coordinator. | Accepted overlay/Unicode/output fixes; matched Garden fixture. | Merged at `d0c4f0c`. Matched medians H 0.575-0.580 ms / V 0.491-0.565 ms, exact payload/style equality on 216 frames, full Engine and bounded integrations pass; about 2.1 MiB additional retained process memory. Andrew subsequently playtested and confirmed it was "absolutely brilliant and fast"; [author acceptance receipt](https://github.com/ichiloto/engine/pull/94#issuecomment-5662733694). This is observed responsiveness, not measured end-to-end latency or Linux/WSLg acceptance. See [validation](t1-retained-cells-validation.md). |
| G1 | Independent graphical battle layout, static arena and graphical combatants. Engine integration with Renderer; Game Design and Art. | Agreed canvas contract and matching fixtures; native implementation; approved graphical layout/assets. | Accepted in the normal local Game, including right-aligned resources, recipient-side feedback and target cursors. Shared native controls now apply to all 11 authored encounters independently of PNG coverage; explicit encounter arena selection supports location-specific art without inferring setting from troop identity. [Current contract and validation](graphical-battle-g1.md) is authoritative; superseded temporary runtimes are removed. Engine passes 2097 tests on PHP 8.4/8.5 with one existing skip each; Game shared-UI checks pass 22 / 599. Prior native macOS acceptance covers one illustrated encounter and corrected Cure placement, not a new all-encounter playthrough. Platform/outcome limits and actor-cursor polish remain explicit. Local integration is not remote publication. |
| G2 | Battler animation and combat feedback. Engine/Renderer with Game Design and Art. | G1. | Backlog, not authorized. Needs scoped brief and animation/feedback acceptance evidence. |
| G3 | Graphical summon presentation. Engine/Renderer with Game Design and Art. | G1/G2. | Backlog, not authorized. Needs scoped summon presentation and terminal parity gates. |
| G4 | Complete graphical field actors, objects and environment. Engine/Renderer with Game Design and Art. | Shared field presentation contract and asset coverage. | Backlog, not authorized. Needs coverage inventory and field acceptance. |
| G5 | Graphical interface and game-wide presentation coverage. Engine/Renderer with Game Design and Art. | G1-G4. | Backlog, not authorized. Needs interface coverage and complete presentation audit. |
| G6 | Packaging and supported-platform readiness. Engine/Console/Renderer owners. | Accepted presentation coverage. | Full delivery remains backlog. Shared GPUI maximize/restore corrects the blanket non-macOS rejection; the accepted optimized build is installed locally through the existing Engine manifest. This is macOS validation only. Players should receive a verified platform package, not compile Rust; published artifacts, a Console installer, runtime dependencies and Linux/WSLg validation remain undelivered. Native Windows also needs a correct process transport. No release or distribution publication is authorized. |

T1/G1 completion will not mean the programme is complete. Publication follows
existing develop -> main PR flow. No remote working branches, release branches,
tags or releases are authorized by this roadmap.
