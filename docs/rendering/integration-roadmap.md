# Dual-Presentation Integration

This programme follows the accepted GPUI feasibility spike. It does not reopen
the spike or extend its S-numbering. Only T1 and G1 are authorized to begin;
later milestones are backlog, not implementation permission.

Engine coordination owns shared Engine APIs and integration. Last Legend - Game
Design owns presentation priorities and coordinates with Last Legend - Lore.
Last Legend - Art owns placeholder and production art. Story-specific design
and acceptance stay in the private game-docs repository, not this document.

| Entry | Scope and owner | Dependencies | Status, acceptance evidence and remaining work |
| --- | --- | --- | --- |
| T1 | Retained-cell native terminal composition and scrolling. Engine coordinator. | Accepted overlay/Unicode/output fixes; matched Garden fixture. | Implemented: matched medians H 0.575-0.580 ms / V 0.491-0.565 ms, exact payload/style equality on 216 frames, full Engine and bounded integrations pass. About 2.1 MiB additional retained process memory. Ordinary game PTY pass completed; visible terminal-app smoothness remains unverified because UI access was denied. No Linux/WSLg acceptance. See [validation](t1-retained-cells-validation.md). |
| G1 | Independent graphical battle layout, static arena and graphical combatants. Engine integration with Renderer; Game Design and Art. | Shared API agreement with T1; approved graphical layout/assets. | Authorized, not started by T1. Acceptance: graphical battle foundation independent of terminal layout, with terminal regression evidence. Coordinate before touching shared Console/Camera areas. |
| G2 | Battler animation and combat feedback. Engine/Renderer with Game Design and Art. | G1. | Backlog, not authorized. Needs scoped brief and animation/feedback acceptance evidence. |
| G3 | Graphical summon presentation. Engine/Renderer with Game Design and Art. | G1/G2. | Backlog, not authorized. Needs scoped summon presentation and terminal parity gates. |
| G4 | Complete graphical field actors, objects and environment. Engine/Renderer with Game Design and Art. | Shared field presentation contract and asset coverage. | Backlog, not authorized. Needs coverage inventory and field acceptance. |
| G5 | Graphical interface and game-wide presentation coverage. Engine/Renderer with Game Design and Art. | G1-G4. | Backlog, not authorized. Needs interface coverage and complete presentation audit. |
| G6 | Packaging and supported-platform readiness. Engine/Console/Renderer owners. | Accepted presentation coverage. | Backlog, not authorized. Needs distribution, installation and actual supported-platform evidence; macOS evidence alone is not Linux/WSLg acceptance. |

T1/G1 completion will not mean the programme is complete. Publication follows
existing develop -> main PR flow. No remote working branches, release branches,
tags or releases are authorized by this roadmap.
