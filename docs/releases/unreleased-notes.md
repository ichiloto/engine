# Unreleased Engine changes

These changes are being integrated through develop into main. They do not
create a release, tag, or release branch.

## Rendering and input

- Shutdown consumes cached confirmation input and stops remaining field/frame
  work. Blocking modals unwind without repainting or resuming stopped scenes,
  preventing an Exit confirmation from opening a new interaction after quit.
- Battle Pause reuses the existing state with Resume, Config, To Title and Exit,
  optional project-owned graphical art and terminal controls. Resume preserves
  active-time gauges, actions and feedback timing; destructive confirmations
  default to Cancel. Config shares the main menu's settings interaction and
  restores Pause focus without resuming gameplay. Live visual acceptance is
  tracked in the [integration roadmap](../rendering/integration-roadmap.md#approved-pause-upgrade).
- Optional supervised graphical rendering with structured colour, text layers,
  PNG sprites, PHP-owned directional sheet animation and negotiated field tiles.
- Native terminal viewports capped at 135x36 and centered within larger windows,
  with shared startup/resize behavior and no forced physical window resizing.
- Retained notifications restore live scene content when moved or dismissed;
  terminal, snapshot and graphical output share overlay precedence.
- Reduced repeated styled-row parsing during scrolling while preserving Unicode
  width semantics and sparse terminal output.
- Region-map locations remain visible and reachable in bounded viewports.
- Cinematic field presentation retains graphical terrain and Player sprites.
  Temporary actors support optional static or sheet-backed graphics, with
  terminal fallbacks and explicit narration/cover precedence.
- Real-subject cinematic visual leases support paired suppression, pose replacement
  and temporary transform recovery without changing NPC gameplay eligibility.
- Optional graphical battle Results show real party progression and rewards over
  the final battlefield, with shared terminal paging and presentation-only timing.
  Result snapshots cannot replay EXP, loot or other committed gameplay outcomes.

## Gameplay and persistence

- Game bootstrap now loads the existing Editor-authored
  `assets/Data/equipment-optimization.php` policy for ordinary Optimize actions.
  Omitted declarations retain legacy scoring; invalid declarations stop startup
  with a file-specific error instead of silently using different weights.
- Shops and their sell menus now enforce item sale eligibility from the owned
  inventory definition, including `sellable: false`. Existing shop rates and
  once-per-transaction rounding are unchanged.
- Purchases create party-owned stacks rather than sharing the shop's catalogue
  object. Selling the last copy no longer empties subsequent purchases, and
  quantity/capacity checks cover equipment as well as consumables before payment.
- Battle openings share one pre-emptive/ambush decision across battle modes and
  honor scripted overrides. ATB starting gauges have meaningful bounded variation;
  readiness order survives high speeds and delayed frames without losing the
  existing speed-based refill behavior.
- Scenario-aware field music, structured battle-entry rules and field-skill
  targeting, alongside the existing scene and resource-preservation fixes.
- Relocated save summaries use the file actually selected instead of following
  a serialized path from an older installation.
- Saved party members resolve by stable actor identity, with legacy name
  fallback and project-declared identity migrations.
- Field skills charge MP for live state and stat-stage changes, while genuinely
  ineffective casts still refund their cost.
- All-target battle commands highlight the eligible group and wait for explicit
  confirmation in turn-based and ATB battles; cancelling returns to the submenu.
- Battle-entry observers run after commit; notification failures cannot roll
  back committed world state or replay the rule's effects.

## Validation limits

The accepted S8-B native pass covers ordinary CLI launch, Garden field rendering,
movement/collision, menu restoration, resize, map transfers and normal shutdown.
Its evidence is in [S8-B validation](../rendering/s8-b-validation.md). Integration
validation is recorded separately from those historical branch measurements.

The broad Last Legend suite was not completed because of the unrelated
180,000-battle simulation baseline. Linux/WSLg remains untested. The
dark-player-on-dark-field issue remains an art/background concern. Scrolling
diagnostics are not gameplay FPS or cross-platform performance certification.
