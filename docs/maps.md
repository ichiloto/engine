# Maps, regions, and the map screen

A map lives in its own directory under `assets/Maps`. Its data and event files
are named after the directory; its visible geometry can use ordered layers:

```
assets/Maps/village/harbour/
  harbour.data.php
  harbour.event.php
  layers/
    01.ground.map.php
    02.floor.deco.php
    03.structures.map.php
    04.objects.map.php
```

The directory path is the map's **id**: `village/harbour`. That is what
`destinationMap` names, what save files record, and what the map screen uses.
Layer names and the choice of layers belong to the game, not the Engine.
A simple layered map needs only one gameplay layer.

Maps without a `layers/` directory continue to load `<name>.map.php` beside
their data and event files. This legacy format remains supported. An existing
but empty or invalid `layers/` directory is an error, not a request to fall back
to the legacy grid.

## Grid source format

### Named local areas

Map data can declare logical territories independently of artwork, collision,
minimap discovery or whole-map region labels:

```php
'areas' => [[
  'id' => 'survey-reach',
  'area' => ['cells' => [[12, 8], [13, 8], [13, 9]]],
]],
```

Each stable lowercase id owns one exact, nonempty set of physical cells. A
rectangle can use `area => ['x'=>12, 'y'=>8, 'width'=>2, 'height'=>3]`. There
are at most 256 declarations; ids and cells cannot repeat, and every cell must
exist in the physical grid, including on ragged maps. Unknown fields, missing
areas and invalid bounds are errors. Rectangle bounds are checked before the
cell set is expanded.

An event can use `subject => ['kind'=>'area', 'id'=>'survey-reach']` instead of
painted marker cells or an inline area. Those three geometry owners are mutually
exclusive. Existing engagement, conditions and one-time event identity still
apply. An automatic event runs through the ordinary Player lifecycle on entry
and when a new or restored field starts inside its area; the area itself grants
no quest progress, rewards or story flags. The event's authored commands own
those outcomes. This supports local arrival flags without treating entry into
the entire map as entry into one district.

A landmark can use `anchor => ['area'=>'survey-reach']`; learning its name does
not run the event or change its eligibility. A random encounter entry can be
limited to areas with `'areas' => ['survey-reach']` (see Random encounters). `MapAreaCatalog` owns declaration
validation, and `MapEventDefinition::SUBJECT_KINDS` exports supported subject
kinds for authoring. Runtime and unsaved source validation use the same catalog.
`MapLandmarkAnchors::readMap` requires the physical grid when named areas exist;
its fourth reference table is `areas`. Editing territory moves its consumers
through references, with no independent copied geometry.

### Read-only validation of unsaved maps

`MapSourceReader::readDeclarations($mapId, $data, $layers, $eventLayer)` accepts
current authored data, a `MapLayerSet` and event cells. It applies the same
geometry and event-subject resolution as file-backed loading and returns
`data`, composed `tiles` and `layers`. The input data is authored source, before
event areas have been resolved; do not pass already-normalized subject events
back as declarations with an independent `area`.

`ProjectReachability::analyze($assetRoot, $mapSources = [], $loadTileset = null)` checks a current
in-memory view without saving or copying maps. Overrides are keyed by map ID:

```php
[
  'town/room' => ['data' => $currentData, 'layers' => $currentLayers,
                  'eventLayer' => $currentEventCells],
  'old/room' => null, // removed from this view
]
```

An optional tileset reader accepts a stable tileset ID and returns its current
`Tileset`. It is used for every map painted in terrain kinds, including maps
whose declarations have not changed, so staged catalogue edits participate in
reachability and safe-save planning. Reader failures remain unreadable-map
diagnostics. Without a reader, tilesets are loaded from the saved assets.

An override replaces disk source, including new maps that have no directory
yet. Null removes a map from the validation view. Other maps, project startup
and common/cinematic script arrivals retain their existing readers. Malformed
overrides report an unreadable map and never fall back to an older saved copy.
Map files, events, world state and saves are unchanged. Editor and Console can
therefore validate current sources without staging a copied map tree or using
symbolic links. Reachability retains its documented open-story-gates analysis;
this API alone does not prove quest progression or current-phase NPC eligibility.

### File declarations

Every `.map.php`, `.deco.php` and `.event.php` file returns one literal nowdoc string.
For example:

```php
<?php

return <<<'TOWN_MAP'
####
#  #
####
TOWN_MAP;
```

A map cell is one terminal character. Coordinates in map data, events,
cutscenes and saves count those cells, and the player moves one cell per step.
A graphical renderer keeps this grid: it draws each cell in the terminal's own
tall shape (see [graphical field](graphical-field.md)).

The delimiter may be any valid nowdoc label. Comments and whitespace outside
the return are allowed; executable statements, builders, calls, interpolated
heredocs, arrays and additional returns are not. The Engine parses all grid
files without executing them, before it evaluates `.data.php`. The Editor uses
the same parser. An invalid map remains listed with a source diagnostic but is
read-only; other maps still open and validate. A repaired map becomes editable
after reopening the project. The Editor rechecks the source before saving,
duplicating or moving a map, so an external edit cannot be silently rewritten.
An unchanged canonical grid keeps its source bytes on save. A failed Game map
transfer leaves the current map and player position intact.

Executable PHP and `string[]` grid values are no longer supported. Map metadata
in `.data.php` and the project's `collisions.php` remain executable PHP data
sources; they are not grid files.

### Event markers

An event is placed by painting its marker character on the `.event.php` layer
and defined under that marker in `.data.php`. The event occupies exactly the
cells showing its marker, in any shape: a line, an L, or separate placements
such as a town exit on the east edge and another on the south side, which
are one event with one identity, conditions and completion. Cells between
two placements are not part of it, so standing there triggers nothing and an
unavailable event's `whenBlocked` gate only stops steps onto its own cells.
A cue shows on each separate placement. Cinematics that name a marker as a
subject use its first cell in reading order, the top-left of a rectangle.
An NPC's pages take the same `cue` (symbol, colour, conditions, optional
kind and `maxDistance`), read by the same validator; the NPC shows the cue of the page talking
would run, in the cell above itself (see [NPC pages](#npc-pages)).
Quest waymarks name markers the same way and mark each placement; see
[Waymarks](quests.md#waymarks-where-an-objective-sends-the-player) for how
objectives are marked on the field, the local map and the world map, and how
the next exit toward another map is chosen.

### Opening passages

`PassageEventTrigger` owns a permanent opening operated through the ordinary
Action interaction. Author it on walkable physical floor, with `engagement =>
'facing'`, `data.mode => 'action'` and `data.reusable => false`. The map id and
event marker are its saved identity. Entry from outside stays blocked until
the opening action completes. A player already inside can move out.

```php
'P' => [
  'class' => PassageEventTrigger::class,
  'engagement' => 'facing',
  'conditions' => [['type' => 'switch', 'name' => 'sense-passages']],
  'cue' => ['symbol' => '!', 'maxDistance' => 2],
  'data' => ['mode' => 'action', 'reusable' => false],
],
```

Root conditions control whether the Action interaction and cue are available.
Sensing displays the marker; Action completes the opening. Optional inline
`script` commands or a selected `scriptId` can present the reveal. An empty
opening script completes directly. A failed script leaves the passage closed.
`whenBlocked` supplies an optional explanation; its absence keeps refused
movement quiet. Completion uses existing `completedEvents`, survives reload and
map visits, and keeps the opening after the sensing condition changes.

Concealing artwork belongs to a world object. Give that object's visibility the
negated `map_event` condition for this map and marker; its graphical and terminal
presentations then retire together when the passage opens. Keep the physical
floor walkable and use the event as the closed-access owner. Runtime preparation
and Editor validation refuse permanently blocked floor under an opening passage.
Artwork replacement never changes physical occupancy.

For a secret minimap passage, anchor an explicit `kind => 'passage'` landmark to
this event, set `secret => true`, and use its `map_event` completion as the
`revealConditions`. The same completion controls route access. Undescribed
opening passages create no automatic marker. Each independent opening needs its
own event marker; disconnected placements of one event share completion.

Cue `maxDistance` is an optional integer from 0 to 64 logical cardinal steps.
Each separate placement measures from its own cue cell. Outside that range it
shows no cue. Omission preserves unlimited cue range. Both graphical and terminal
presenters use the logical player position, and NPC cues support the same range.
The shared Editor offers Opening Passage and cue-range controls with ordinary
source-preserving save, undo and redo. Game conditions own which ability senses
a passage; the Engine carries no game-specific character or story rule.

### Event speakers

An event names the people who speak its lines with `speakers`, a list of 1..8
distinct stable NPC ids on the same map:

```php
'H' => [
  'class' => ScriptEventTrigger::class,
  'speakers' => ['analysis-supervisor'],
  'data' => ['mode' => 'auto', 'reusable' => false, 'scriptId' => 'assessment-briefing'],
],
```

Speakers are authored identity; nothing infers them from a line's name, and
they change no runtime behaviour. `MapEventSpeakerRule` (rule `event.speaker`,
part of `MapCompletenessValidator::validateEvents`) requires the player to see
who talks: every page of a speaker that may show while the event can run must
stand on or within one cell of the event's area, in any direction, with a
wandering page's whole wander area there, and at least one page must be able
to show. A page may show unless its conditions and the event's effective
conditions (subject conditions included) provably exclude each other
(`Core\WorldConditionExclusion`, the same proof the Editor uses for NPC
phases sharing a tile). A speaker who is not an NPC on the map, is never
present, wanders freely or stands away is reported with the cells where they
stand. A scene that walks people into place is authored as pages conditioned on
that phase, standing where the scene needs them, so the rule reads the map
without running scripts.

A scene the player starts by action and an NPC delivers belongs to that NPC.
`MapNpcInteractionRule` (rule `event.npc-interaction`, also part of
`validateEvents`) reports a script, Cinematic or dialogue event in `action`
mode that names NPC speakers: the player would have to find its cell, while
talking to the NPC says something else. Move it onto the first speaker's
pages. Scenes that start on arrival (`auto`) are not conversations, and an
event bound to a world object is that object's interaction (a terminal, a
reception counter) and one bound to a named area is that place's (a safe
position, a gap a creature holds); their speakers answer for them. Shop and
inn events stay with the counter or fixture they are authored on; a page
offers either service with the `shop` and `inn` script commands when the
NPC should own it.

### NPC pages

An NPC's ordered `dialogue` variants are its interaction pages, as RPG
Maker's event pages. Talking runs the first page whose `conditions` hold and
which has not retired, so the most advanced story state goes first and an
unconditional page last:

```php
'npcs' => [[
  'id' => 'field-supervisor',
  'name' => 'Supervisor Ilyan',
  'x' => 55, 'y' => 11,
  'dialogue' => [
    [
      'id' => 'first-assignment',
      'conditions' => [['type' => 'event', 'name' => 'blood_sample_submitted'],
        ['type' => 'event', 'name' => 'assignment_issued', 'negate' => true]],
      'cue' => ['symbol' => '!', 'color' => 'bright-yellow'],
      'scriptId' => 'garden-of-roads-assignment',
      'reusable' => false,
    ],
    ['lines' => [['name' => 'Supervisor Ilyan', 'text' => 'Use the east deployment gate.']]],
  ],
]],
```

A page shows its `lines`, then runs at most one action: inline `script`
commands, the shared script `scriptId` (`assets/Events/<id>.php`, run as a
Common Event, the files map script events use) or the Cinematic
`cinematicId`. Its `sets` apply when it finishes, before the NPC's own
`sets`. Its `cue` is the NPC's guidance while it is the page talking would
run, so a waymark on the NPC appears and retires with that cue. A page with
`reusable` false retires once its action completes; it needs a stable `id`,
and its completion is saved as the one-shot event `{map}:{npcId}/{pageId}`,
so moving a one-shot map event onto a page maps the old `{map}:{marker}`
identity there through `aliases.one_shot_events`. `speakers` names the
other NPCs its scene stages; each must stand beside the NPC whenever the page
can run, as event speakers stand beside their event.

A plain list of lines, or a top-level `script` (which replaces any
dialogue), is one unconditional page, and only that shorthand carries its
`cue` on the NPC itself. `NpcPages` reads pages for the field, waymarks,
routes and validation alike. The rule `npc.page` reports malformed pages,
dialogue a top-level script hides, a page an earlier reusable page always
pre-empts (its authored conditions include all of the earlier page's) and a
staged speaker away from the NPC. A reusable page whose script transfers is
a route, used by talking to the NPC while it is the page talking runs.

## Active-map landmarks and discovery

Andrew selected a GPUI corner atlas with compact and larger sizes, and an opened
scrollable map with focused location details. His 17 October review rejected the
forced whole-map local fit and regional connection diagram. Local views now use
physical map geometry and a separate map camera. GPUI local maps offer two zoom levels,
both farther out than gameplay; global maps fit the whole geography at the
zoomed-out level and allow a closer, scrollable level; these are independent of the compact/larger HUD
sizes. The opened map pans in both axes. Terminal retains its coordinate HUD;
opened maps use blank walkable ground, `-`, `|`, `+` physical boundaries, `v`
entrance/exit chevrons in floorplans and single-block place markers. Outdoor
`places` views remove collision-derived building/tree/fence traces; an entrance
to a building uses one square, while exits/passages keep chevrons. `@`
marks the player. Decorative field glyphs and miniature Terminal art are absent.
GPUI keeps simplified map artwork and dedicated place-category pictograms.

`landmarks` in a map's `.data.php` is a list of at most 256 declarations:

```php
'landmarks' => [
    [
        'id' => 'harbour-gate',
        'kind' => 'exit',
        'anchor' => ['event' => 'G'],
        'name' => ['message' => 'maps.harbour.gate', 'default' => 'Harbour Gate'],
        'approachDistance' => 3,
    ],
    [
        'id' => 'old-tower',
        'kind' => 'building',
        'anchor' => ['object' => 'tower-sign'],
        'name' => ['message' => 'maps.harbour.tower', 'default' => 'Old Tower'],
        'revealConditions' => [['type' => 'event', 'name' => 'heard_of_tower']],
    ],
],
```

The map-scoped `id` is a stable lowercase identifier of 1–64 characters. Kinds
are `passage`, `entrance`, `exit`, `building` and `landmark`. An anchor references
one existing local event marker or stable world-object id. It never duplicates
coordinates or names a destination spawn. Old legacy map transfers may be given
a stable `id` and referenced with `['trigger' => 'back-door']`. Moving the owning
event/object moves the landmark; deleting an anchor requires reference repair.
Each disconnected event placement gets a marker centred on one of its own cells.
One event/legacy transfer can anchor one landmark. Route kinds must reference a
transfer; a transfer landmark must retain a route kind. A separate building may
use its sign object as its anchor.

All ordinary transfer events and legacy map triggers receive anonymous passage
markers automatically, including gated routes. Explicit landmark metadata
replaces that marker. Destination names are never inferred. Route markers are
visible immediately; names and non-route landmarks are learned by approach or
authored play revelation. `approachDistance` defaults to 3 physical Manhattan
cells and accepts 0–64 or `null` to disable approach discovery. This distance is
independent of the renderer, viewport and zoom.

`secret => true` hides the marker and ignores approach discovery. Only explicit
play revelation can reveal it; a secret declaration must omit `approachDistance`.
Use
a nonempty `revealConditions` list of up to 32
shared world conditions, or `GameState::revealLandmark($mapId, $landmarkId)`.
Revelations latch in saved `knownLandmarks[mapId][landmarkId]`, survive map visits
and save/reload, and never unlock the route. Transfer access conditions remain
separate and may show a known route as locked. Old saves begin without individual
landmark knowledge; map visitation alone does not reveal names. Presentation
reads knowledge and never causes a discovery.

Runtime uses `MapLandmarkCatalog::readMap($resolvedMap, $tiles)`. Editor validation
uses `readDeclarations($entries, new MapLandmarkAnchors($events, $objects, $triggers),
$rowWidths)` on unsaved source. Each table maps a source identity to
`new MapLandmarkAnchor($cellArea, $isTransfer, $accessConditions)`; `rowWidths`
contains each real row's cell count, preserving ragged maps. Both use the same
validation and automatic-route generation. Public catalog constants define the
id pattern, limits and approach default. `getNameMessageKey($mapId, $landmarkId)`
supplies a bounded map-scoped translation key using the map identity's SHA-256;
authors may supply another valid localized message id. Saved landmark ids remain
immutable during ordinary editing. Existing world-condition flags provide
authored revelations; no new script command is introduced in this slice.

Map scope and physical parent references live in map data, independently of
its filename, tileset and free-text region:

```php
'atlas' => ['scope' => 'global'], // on an actual overworld map
// On a local map, e.g. an interior inside a town:
'atlas' => ['parent' => ['map' => 'harbour', 'landmark' => 'home-entrance']],
```

`MapAtlasDefinition::readMap` validates scope (`local` by default) and the optional
parent. Global maps cannot have parents. `resolveGlobal($mapId, $readMap,
$categories)` accepts a resolver returning `data` and `tiles`, including unsaved
Editor models, and follows at most 32 parent maps. It refuses cycles, missing
maps and missing parent landmarks. The landmark may be a route or a place; its
existing physical area supplies the representative position. The last parent
entry is the marker on the world map. No spawn points or duplicate coordinates
are used. `readGlobalMaps` enumerates explicitly global sources for browsing.
A local area without a proven world entry can still browse those maps; it has
no invented player position and says that its current location is not marked.
Physically global maps open only their global view.

`atlas.terminalView` explicitly selects `places` or `floorplan` through
`MapAtlasDefinition::TERMINAL_VIEWS`. Local maps default to `floorplan`; global
maps default to `places`. Game authors mark outdoor maps as `places`; the
renderer never infers interiors from paths, tilesets, glyphs or artwork.
`places` draws classified terrain and simplified building shapes beneath known
location/route markers at their real map positions. General ground remains blank.
`floorplan` also keeps simple collision-derived room/corridor outlines.
This is Terminal presentation data and changes no collision, discovery or GPUI
artwork. Source-preserving Editor/GUI authoring uses the same validated values.

`atlas.terrain` classifies existing source-layer cells for Terminal geography:

```php
'atlas' => [
    'terminalView' => 'places',
    'terrain' => [
        'terrain' => ['symbols' => ['~' => 'water', ',' => 'road', ';' => 'ground']],
        'buildings' => ['default' => 'building'],
        'canopy' => ['default' => 'vegetation'],
    ],
],
```

Layer names must exist. Each `symbols` key is one plain authored glyph; its role
belongs to that layer and map. `default` classifies nonblank cells only. Explicit
space bindings are supported; unclassified space never erases a lower role.
Bindings compose in the existing layer order. This references current source
geometry and stores no duplicate grid, positions, collision or graphical IDs.
`MapAtlasTerrain::ROLES` supplies constrained authoring choices: ground, road,
bridge, water, vegetation, highland, obstacle, building and boundary. Unknown
materials can use obstacle; collision or a familiar glyph must not invent hills,
woodland or bridges. Buildings use a coarse `#` silhouette, not source lettering;
square landmark markers remain above it. Boundaries use `- | +`. Roads, water
and plants remain distinguishable in monochrome and have Terminal colour accents.
The legend lists declared roles. Names remain focus-only and secrets retain
their existing discovery rules. Floorplans still expose physical boundaries
under blank ground classifications; terrain roles never grant passage.

Reduction aggregates source cells exactly, preserving narrow roads and bridges.
Classified views support at most 16 Mi source cells; target rasters remain bounded
to 512 by 256 and two cached variants per installed map. Panning never changes
the field position or map state. GPUI continues to use its existing world/artwork
composition; the Terminal classifications do not affect its output.

The semantic `map_view` action (unclaimed V/v by default) switches Local/World.
The opened map always shows its primary action bar, using the shared required
control treatment even when the theme hides optional hints. Corner maps carry no
location-name header, legend or hint strip. The corner geography and rim are
translucent (theme `cornerOpacity`, default0.75); markers remain opaque. Removing
the opaque corner frame backing lets the field remain visible beneath the map.
Directional actions scroll without moving the player. Page actions cycle through
visible landmark identities and centre the chosen marker; names appear only for
the focused marker. Ordinary scrolling clears focus. Info pages long names;
`map_recenter` (Home) returns to the player. GPUI `map_zoom` (Z/z) switches exactly
two map scales. These actions use shared bindings and hints. Map drawing never
causes a discovery or a transfer, installs events, or starts background music.

A landmark's optional `category` describes a place independently of route kind.
Generic categories are `inn`, `shop`, `home`, `organization`; shared defaults draw
bed, bag, house and columned-building pixel pictograms in one batched layer.
Unknown ordinary routes keep generic chevrons until learned. Projects may add
categories in `Data/map-categories.php`:

```php
return ['schema' => 'ichiloto.map-categories/1', 'categories' => [
    'guild' => ['base' => 'organization',
        'name' => ['message' => 'map_categories.guild.name', 'default' => 'Guild']],
]];
```

`MapCategoryCatalog` validates at most 128 project categories, supplies generic
bases, localized names, stable name keys and round trips. Generic IDs cannot be
replaced. Pass the catalog as the optional last argument to landmark `readMap`
and `readDeclarations` to validate project category references. Missing category
stays absent. Optional theme icon roles `map.category.<id>` replace individual
category pictograms. Local route chevrons and global squares are scope-aware;
a settlement entrance keeps its entrance kind.

The optional `atlas` treatment in `Data/Presentation/menus.php` reuses the shared
palette, replaceable `quiet`/`panel` frames and optional semantic icon bindings.
Its keys are `compactWidth` (256), `compactHeight` (192), `largeWidth` (400),
`largeHeight` (300), `margin` (16), `markerSize` (10), `frameRole` (`quiet`) and
`cornerOpacity` (0.75, finite0..1).
Dimensions accept 96–768 logical pixels; larger dimensions cannot be smaller
than compact dimensions. Margin accepts 0–64 and markers 4–24. The two corner sizes
are maximum extents: the whole map preserves its aspect and fills a one-pixel
palette rim. Its frame hugs the map, with no menu padding or reserved text rows;
menu typography and spacing apply only to the opened view. Opened maps scroll at a fixed
map scale; changing map size or zoom never changes field zoom.
Optional icon roles are `map.player`, `map.passage`, `map.entrance`, `map.exit`,
`map.building` and `map.landmark`. Missing art uses shared geometric marker
treatments. `MapAtlasPresentation::listIconRoles($categories)` supplies these roles
and every generic/project category role to theme authoring; `PLAYER_ICON_ROLE`,
`MapLandmarkKind::getIconRole()` and `MapCategoryCatalog::getIconRole()` are the
shared identities consumed by the composer. Projects supply data and artwork;
the Engine owns drawing and input.

GPUI uses the same installed retained world as a static overview source. The
scene suppresses the Terminal coordinate HUD whenever graphical atlas capabilities
are available. The corner itself follows field, dialogue, menu and HUD-setting
ownership. Terminal keeps coordinates. The semantic
`map_size` action (F3 by default) cycles compact/larger/off; Config exposes Corner
Map Size and persists `ui.hud.map_size` in player settings. Opened mode is temporary
and remains available when the corner is off. Graphical coordinate-HUD exclusion
is owned by the scene, independent of whether a corner or event overlay currently
has a canvas. A cutscene or hidden corner therefore cannot expose Terminal HUD
glyphs. Terminal and renderers without atlas capabilities retain their coordinates.

Frame composition shares one bounded PNG file-fact snapshot across sprites,
world coverage and overview subjects. This removes repeated filesystem/header
probes for the same asset within one composition. The snapshot is released after
success or failure; the next frame checks current files again, including header
changes whose timestamps and file size collide. Crops remain checked separately.
Opt-in latency tracing separates composition, frame preparation and submission.
The 18 October muted macOS held-walk comparison measured compact-map composition
median 7.545 ms before and 4.574 ms after this change; its longest measured step
gap fell from 608.787 ms to 274.017 ms. These are bounded CPU/pipeline observations,
not display FPS or a guarantee against all scheduling delays. Player review of
the walking feel passed Andrew's 18 October review, together with the GPUI map,
F3 compact/larger/off cycle and Nivira-departure HUD correction. Terminal received
"Needs changes — I’ll describe them" and remains unfinished pending his details,
the resulting corrections and acceptance. The earlier coordinator goal-completion
status was premature and does not establish Terminal acceptance. Andrew then
specified the missing terrain/building context, with a blank world-map screenshot.
His later 18 October instruction removes player review from this implementation
goal: finish implementation and technical verification, then start the queued
Lore/Lika'mi goal. He will review later and request further changes separately.

The correction is locally integrated in Engine `6a9275a`, Editor `46e04e3`
and Game `5a428e4`. Engine's six affected suites pass 48 tests / 1,068
assertions; targeted static analysis passes. Editor's affected checks pass
65 tests / 256 assertions and static analysis. Game's two-mode atlas check
passes 2 tests / 70 assertions, including source context outside markers,
scrolling, focus, local/world switching, discovery and save/reload/revisit.
A muted macOS PTY Terminal run passed 19 stages in 221 frames / 5.095 seconds,
with nine terrain checks, zero audio backends, unchanged user data and removal
of its isolated save root. Current-source world and town output was inspected
offline. This establishes Terminal output behavior; macOS Terminal GUI pixels,
Linux and Windows were not inspected in this correction. The accepted GPUI
presentation remains unchanged. Later player review is separate.

The opened atlas retains its selected source world without selecting a field
viewport or field actors; ordinary menu canvases release that dependency.

The optional negotiated `map_overview` world-composite operation reuses prepared
map tiles and up to 128 map-owned still groups / 1024 child images. It freezes
animated resources at a resting pose and preserves physical anchors, crops,
pivots, layers and lift. Raster output is at most 1024 by 1024, with 16 MiB of
live overview output/key storage, two-times sampling and 256 Mi bounded cold
work. A frame requests one overview. The additional negotiated
`map_overview_viewport` capability admits a `source` rectangle in physical map
cells. Source geometry must be finite, positive and inside the actual world.
Panning/zoom changes its bounded cached raster; marker movement reuses it. `canvas_composite_indicators` separates live
player/landmark markers from this cached raster. Map revision and current decoded
image identity invalidate it; movement only changes indicators. Generic glyph-only
maps use their presentation colours. Neither collision nor location meaning is
inferred from graphics. Existing composite limits remain unchanged.

The earlier installed macOS checks covered HUD ownership, discovery and safe
save/reload/revisit behavior; Andrew rejected both map presentations afterwards.
The scroll/zoom/local-world/category revision passed 134 focused Engine tests
with 1480 assertions and 12 focused Renderer overview/viewport/wire tests. Game's
two CPU modes passed 2 tests / 63 assertions in 12.46 seconds, including mandatory
opened controls. The later scope-legend/helper correction passed 26 focused
symbol/presentation tests (overlapping suites; counts are not additive).
The final installed macOS GPUI run on Renderer `a5c1e41` completed 2598 frames /
434 presentation changes in 81.895 seconds, exit 0. Both scales, local/world
switching, global-only display, focused details, discovery and save/revisit checks
passed. The action bar, zoomed local view and full-world geography/legend were
visually inspected. Music, effects and voice stayed muted; audio backends were
absent, user files stayed unchanged and temporary storage was removed.
Terminal's revised native appearance still needs Andrew's review; its captured
functional/symbol checks passed.
Walk animation is accepted by Andrew. Editor/GUI authoring and Game metadata
are coordinated with their existing owners; final dependency integration follows
the Engine commit. No publication is authorized by
this implementation. Linux/WSLg native presentation has not been verified.

## Layer order and composition

Layer filenames have the form `NN.name.map.php` for gameplay or
`NN.name.deco.php` for decoration. The two-digit order is in `00` through `99`,
and names start with a letter followed by letters, digits, underscores or
hyphens. Names and order numbers must each be unique within a map. Files are
discovered from `layers/` and sorted by their numeric prefix; there is no
second layer list in `.data.php`.

All layers and the root event grid must have the same number of rows and the
same number of logical symbols on each corresponding row. Existing ragged
maps are supported: one row may be shorter than another, but that row must have
the same width on every layer. Do not pad a migration just to make it rectangular.
Colour markup is not a cell; wide glyphs retain their existing logical-cell and
terminal-display behavior.

The lowest gameplay layer is the base. Higher gameplay layers replace it only
where they contain a non-space symbol. A space on an upper layer, including a
styled space, is empty and shows the lower layer. The topmost occupied cell
supplies the complete styled symbol. This composed grid is `Camera::worldSpace`
and is exactly what the terminal renders.

Decoration is never composed into that grid and never contributes collision.
A decoration layer named in the collision dictionary refuses the map with a
diagnostic. Interactive objects must be gameplay glyphs or events, never hidden
decoration. Decoration has no graphical presentation of its own; a graphical
renderer draws a map from its tile layers (see Graphics). The glyph-keyed tile
crops (`tiles2d`) are retired: a map data file that still has `tiles2d` loads
with a warning and shows its terminal glyphs.

## Graphics

A graphical renderer draws a map from its own tile layers, independent of
its terminal glyphs. The map names an RPG Maker style tileset in its data
file and keeps one tile identity per terminal cell in `graphics/`.

Tilesets are grouped by the kind of setting they draw, as RPG Maker groups
its own: one for overworld maps, one for exteriors (towns, fields, roads),
one for interiors (homes, inns, offices) and one for dungeons (caves, ruins,
crypts). That setting is the map's kind: every map has one, chosen when the
map is created, and every map of a kind names the same tileset, so a tileset
never belongs to one map or one place. The tiles a map can use change only
when its kind does. The Editor asks a new map's kind, shows it as the
Inspector's Kind, and warns about a map without one in a project that has
tilesets; `ichiloto generate:map` takes it as `--kind`.

A tileset's sheets are sorted as RPG Maker sorts them: A1 to A5 for
structure (water, ground, building exteriors, walls, plain floors), B for the
main objects, and C to E for more. A tile's identity is its place on its
sheet, so new art goes into free cells and existing art never moves.

```
assets/Data/Tilesets/interior.php      # name, sheets A1 to E, above, tables
assets/Maps/village/harbour/
  harbour.data.php                      # 'tileset' => 'interior'
  graphics/
    01.floor.tiles.php
    02.furniture.tiles.php
```

```php
<?php

return <<<'TILES'
2816 2816 2816 2816   0   0
2816 2816 2816 2816  42  43
TILES;
```

Each tile layer is a literal nowdoc with one row per map row and one
whitespace-separated RPG Maker tile identity per cell (`0` is empty); it is
never executed. A field cell is one whole RPG Maker tile. An autotile (such as
floor 2816) is composed for its own cell from its neighbours, so an area is
painted in every cell; any other tile, such as the table above, is drawn whole
in its cell. An entry naming half a tile (`42L`) is refused, with a message
asking for whole tiles. The map data may shift a whole tile layer by half a
cell across or down
(`'tileLayers' => ['lounge' => ['offset' => [0, -0.5]]]`) so art can sit between
the cells its terminal footprint allows, and may name the gameplay layer a tile
layer belongs to (`'floor' => ['movesWith' => 'buildings']`): editors move its
tiles with that layer's glyphs, and the graphical field hides only that layer's
glyphs under its tiles. Without a setting, a tile layer belongs to the gameplay
layer whose tileset pieces write it, when exactly one does.
Graphics never change
geometry, collision, events or saves, and unusable graphics or sheets are
reported while the map shows its terminal glyphs. Autotile shapes are stored in the identities, as RPG Maker
stores them; `AutotileShape::resolveLayer` chooses them from neighbours for
authoring tools. See [graphical field](graphical-field.md) for sheets, draw
bands and animation.

## Physical occupancy

A map may explicitly declare `occupancy` in its `.data.php`. It contains
zero-based row lists of resolved `CollisionType` cases, with exactly the same
row lengths as the logical map, including ragged rows:

```php
'occupancy' => [
    [CollisionType::NONE, CollisionType::COUNTER, CollisionType::SOLID],
    [CollisionType::NONE, CollisionType::NONE, CollisionType::SOLID],
],
```

This declaration is the sole static passage source for both renderers,
reachability and shared field previews. Terminal appearance and graphical
resources do not choose physical cells. Integer cells, mismatched dimensions
and unresolved `PASS_THROUGH` cases are refused; a malformed declaration never
falls back to glyph-derived passage.

Only an absent `occupancy` key selects the compatibility rules below. Ordinary
loads and saves never migrate a map. The GUI's explicit **Separate collision**
action captures the current resolved cells through the shared Editor session,
without changing either presentation or current passage. It is source-preserving,
undoable and saved through the existing transaction. Later glyph/layer appearance
edits no longer change collision on a converted map. Resize and row/column
insertion carry its physical cells with geometry and make newly added cells
`SOLID`; maps without the declaration remain undeclared. Physical painting and
object-footprint authoring remain separate GUI capabilities, not implied by this
conversion control.

## Collision dictionaries

The game's `assets/Maps/collisions.php` can combine a flat glyph dictionary with
optional sections keyed by layer name:

```php
<?php

use Ichiloto\Engine\Events\Enumerations\CollisionType;

return [
    ' ' => CollisionType::NONE,
    '#' => CollisionType::SOLID,
    ';' => CollisionType::ENCOUNTER,
    'structures' => [
        '=' => CollisionType::NONE,          // A bridge overrides the ground.
        '^' => CollisionType::PASS_THROUGH,  // An awning inherits the ground.
    ],
];
```

A named section overrides the flat dictionary for that layer; otherwise the
flat entry applies. Unknown glyphs remain solid. A glyph is looked up by its
own entry first and then by its ASCII transliteration, so entries beyond
ASCII such as `▲` or `♣` apply as authored.

A map painted in its tileset's terrain kinds (`'terrainKinds' => true`)
reads each kind layer through a section composed from the kinds: every kind's
glyph gets the collision its passage and encounters give (`SOLID`,
`ENCOUNTER` or `NONE`). The dictionary's own section for that layer may repeat
a kind's glyph only with the same collision; a different one refuses the map,
naming both, since a kind owns its glyph's passage on its layer. See the
layered tilemaps plan, Stage 7. Resolution walks gameplay
layers from top to bottom, skipping upper spaces and `PASS_THROUGH` symbols.
The first remaining glyph supplies the collision result. If no layer supplies
a result, the cell is solid; `PASS_THROUGH` is never a final collision value.
Decoration is excluded entirely. For an undeclared map, collision comes from
authored symbols and the dictionary, never from colour or graphics.

`CollisionType::COUNTER` marks a counter: solid to movement, but the player
talks across one cell. A directly faced NPC is still reachable. Otherwise the
faced cell must be `COUNTER` and the NPC must stand immediately behind it in
the same cardinal heading. Floor, ordinary walls, the map's edge and a second
counter cell without an NPC end the reach. An intervening NPC keeps priority;
the player cannot reach a farther NPC through it (`InteractionReach`).

Multi-cell-counter reach has been removed: the historical any-depth rule is
superseded by Andrew's 2026-10-07 one-cell instruction. Counters are authored
one gameplay row deep; wider art or an overhanging front face must not introduce
another physical counter row. A counter is a gameplay type like any other:
give the counter's glyph `COUNTER` in the flat dictionary or in the section of
the layer it is drawn on, without changing how it looks. Graphical tile identity
and visual offsets do not change collision or talk reach.

## Reachability

Maps, rooms and NPCs change throughout production, so nothing pins where
content stands. What must hold is that the player is never blocked:
`Ichiloto\Engine\Field\Reachability\ProjectReachability::analyze($assetRoot)`
reads every map as the field does (`MapSourceReader` and the collision
dictionary) and floods it from every place the project brings the player onto
it, starting from where the game starts:

- the system starting position, and scripted `transfer` commands in common
  events and cinematics, which can run from anywhere;
- transfer events, edge triggers and sleep events, once the player can reach
  the cell that triggers them;
- scripted transfers in an NPC's lines, once the player can speak to it directly
  or across one counter cell,
  and in an event's data, once the event can fire.

An arrival counts only once the player can reach its source, and the spread
repeats until nothing new is reached, so a door from a map nobody can reach
leads nowhere.

The flood follows the field's movement rules: one cell at a time in the four
headings, onto any cell whose collision is not solid and that no NPC stands on.
Fixed NPCs block their cell; wanderers and NPCs that only appear under
conditions do not, since story gates are assumed open. Stepping onto a transfer
or edge trigger leaves the map, so those cells are reached but not walked
through. Each map's report lists:

- an arrival outside the map, or on a solid or occupied cell (the player can
  step off a blocked cell, but arrives overlapping it);
- a map nothing the player can reach brings them onto yet, which is information
  rather than a blocker (`ReachabilityProblemKind::isBlocking()` is false): a
  map kept for content still to come strands nobody;
- an event or edge trigger with no reachable cell, which therefore never fires;
- a talkable NPC no reachable cell reaches, beside it or across one counter cell by the
  same rule the field talks by, whose lines nobody can read;
- an arrival naming a map the project does not have, and a map the field
  cannot read;
- an interaction the player meets from the wrong side, a warning rather than a
  blocker (see below).

### Approach sides

Interactions should face the way the player comes in. For every interaction
(an action event that is faced, such as a desk or terminal, or that names
speakers; transfers and automatic events are routes and scenes, not
interactions), `MapReachability::findDetouredApproaches()` walks from each
arrival into the map to the fewest-steps cell where the player first stands
at the event's subject: on or beside its cells from any side, or where they
can speak to one of its speakers by the field's talk rule. When the nearest
cell where the event actually answers (its engagement and authored facing) is
more than `MapReachability::APPROACH_DETOUR_TOLERANCE` (one) steps further,
the player has reached the subject behind or beside it and must walk round.
`ProjectReachability` reports one `DETOURED_APPROACH` problem per event, naming
the arrival with the longest walk round, the cell the player naturally reaches,
the extra steps and the cell where the event answers. Arrivals carry the
conditions of the event or NPC that sends the player
(`ReachabilityEntrance::$conditions`), so an arrival that cannot happen while
the event runs is skipped, by the same `WorldConditionExclusion` proof the
speaker rule uses; speakers present while the event runs block their cells.
`AutomaticEventTriggerInterface::determineRunsAutomatically()` reads a
trigger's mode from its authored data, so the analysis tells automatic events
apart without building triggers. Fix a finding where the room is designed:
turn the desk and its speaker toward the arrival path, move the event's cells
to the side the player reaches, or allow its facing from that side.

`MapReachability` runs the same analysis on one map's collision grid, events
and NPCs, for tests with synthetic maps. Its bounded candidate cells use
`InteractionReach::MAX_COUNTER_CELLS` and `findTalkCell`, the same authority as
runtime interaction and the action prompt. The Editor's `ReachabilityValidator`
delegates to `ProjectReachability`; authoring validation therefore uses the same
one-cell rule, including whether an NPC's scripted transfer can run. This does
not rewrite existing layouts or make graphical counter art a collision source.

### Walks between cells

`MapReachability::findRoute($from, $to)` gives a shortest walk between two
cells under the same movement rules: every cell from the first to the last, or
null when the player cannot get there. It never passes through a cell that
takes the player off the map, though it may begin or end on one
(`isLeavingMapAt()`), and of the equally short walks it keeps its heading
wherever it can, so it turns as seldom as a player holding a direction.
`describeStandingProblem()` says why a cell cannot be stood on (outside the
map, solid, or where a fixed NPC stands). `MapReachability::readMap()` reads a
map's resolved events, edge triggers and NPCs the way `ProjectReachability`
does. The Editor's walk measurement (`map.measure`) is built on these.

## Editing and migration

The Editor cycles through gameplay, decoration and event layers with independent
visibility and dimming. Terminal preview shows the composed gameplay grid only.
Vim, mouse, colour, selection and clipboard operations use the active layer.
Event colours are authoring aids only; runtime event markers are read without
colour tags.

Saves transact the whole changed file set. Untouched layer files are not written,
and unchanged rows preserve their original bytes. Layer create, rename and remove
participate in undo. Renaming preserves the numeric order without flattening
the data file. Because a shared collision dictionary may use the old name, the Editor asks for confirmation if a rename changes resolved
collision; it does not rewrite that dictionary automatically. The first explicit
layer creation on a legacy map converts its grid.

A game may supply a building catalogue for multi-row facade brushes. The Editor
reads the catalogue as the shape source rather than keeping copied definitions.
The game's layer conventions still determine where those brushes belong.

When splitting an existing map, compare its complete styled composed grid and
every cell's resolved collision before and after. A geometry-preserving split
does not by itself require a save-content version change. Deliberate changes to
walls, approaches or safe arrival cells need separate save-compatibility review
and migrations where old positions become unsafe. Do not hide such changes by
updating the equivalence baseline.

Inserting blank rows or columns grows a map and moves everything at or beyond
the insertion line by the inserted count: every terminal, event and tile layer,
NPC positions and wander areas (an area straddling the line stretches), spawn
points and transfers into the map from any file, `startingPositions`, and
coordinates in scripts and cutscenes that run on the map. A regional `station`
and tile-layer `offset` values are not map cells and never move. The Editor's
insert command performs this as one transaction and reports every coordinate
it cannot rewrite from source (a PHP expression, or a script started from
several maps) for a hand edit. Saves follow through a declarative `mapShifts`
content migration; see [Versioned save compatibility](save-compatibility.md#declared-map-shifts).

### Regular NPC sprites

Map `npcs` entries may add optional `sprites2d` alongside the existing terminal
`sprite` and directional `sprites`. It names an RPG Maker character sheet, the
same contract as the Player: see [field character sheets](rendering/sprite-sheets.md).
An NPC occupies exactly one field cell. Omit `sprites2d` for terminal-only
NPCs; an explicit empty array, null, or malformed definition is invalid and
produces a diagnostic with terminal fallback, without removing the NPC.

```php
'npcs' => [[
  'id' => 'village-guide',
  'name' => 'Guide',
  'sprite' => '@',
  'x' => 7, 'y' => 4,
  'sprites2d' => ['sheet' => 'Graphics/Characters/People.png', 'index' => 3, 'layer' => 100],
]],
```

Sheets are project-asset-root-relative PNG paths, not character identities.
Frame size is read from the image; world layers are 0..999. Shared limits
require readable root-contained PNGs of at most 16 MiB and 4096 pixels per
dimension. These are resource limits, not recommended artwork sizes. Missing
files, invalid headers, unsafe paths, and sheets that do not divide into RPG
Maker's layout retain terminal art with a warning. Replacing a valid file at the
same path requires no metadata update; preflight retries changed files.
Full PNG decoding remains the native renderer's responsibility: a valid header
with corrupt IDAT data is not detected by this PHP preflight, and recovery from
that native decode failure is not supplied by the NPC sidecar.

NPCs implement `GraphicalSpriteProviderInterface`.
`NpcManager::getGraphicalSpriteProviders()` returns exactly the visible,
cinematically unsuppressed ordinary NPCs; terminal-only providers return a null
graphical definition. Each terminal fallback uses its provider's named Console
layer, so graphical replacement masks only that NPC. Presentation IDs are
map-scoped and use the existing stable NPC `id`; legacy id-less entries receive
separate entry-based presentation IDs without changing script or save identity.
Visibility, interaction, collision, conversation writes and movement stay owned
by the existing NPC logic. Cinematic leases suppress ordinary NPC art until
released, including while the staged actor is hidden.

The scene advances `NpcManager::advanceGraphicalAnimation($seconds)` alongside
Player/staged-actor animation, including event routes, and uses
`stopGraphicalAnimation()` on presentation lifecycle boundaries. Successful
steps use the shared `CharacterWalkAnimation` (RPG Maker's 1, 2, 1, 0 stride);
facing, blocked movement, restored staging, and map replacement return to the
standing frame. Reduced motion retains route outcomes and shows the standing
frame for each direction. The regular-NPC
Editor picker and safe source-preserving authoring workflow are coordinated
separately; runtime support alone does not complete Editor authoring.

### Map-owned interactive fixtures

A fixed interaction can keep its stable NPC `id`, dialogue and position while
the gameplay layer supplies its appearance. Set that NPC's `sprite` explicitly
to the empty string (`''`), and omit directional `sprites`. An omitted sprite
still defaults to `@`; a space is not an empty sprite and would paint over the
map. The empty sprite writes no cells, leaving both the terminal map glyph and
its graphical crop visible without a second drawing above them.

Keep the interaction anchored on the visible fixture cell, with a reachable
approach cell beside it. The NPC still participates in interaction, conditions
and occupancy. Its conditions control the interaction, not the authored map
glyph; use a regular NPC sprite for an object whose appearance must move or
disappear with the NPC. Map-owned fixtures should remain fixed.

## Regions

`.data.php` gives a map its `name` and its `region`:

```php
return [
  'name' => 'Town Center',
  'region' => 'Happyville',
  // ...
];
```

Maps sharing a region are the same place as far as the player is concerned,
and the map screen draws them together.

What a project authors for a whole region lives in the optional
`Data/regions.php` (`RegionCatalog`, schema `ichiloto.regions/1`), one record
per region keyed by its name exactly as maps write it. A record may name the
region's `skitBackdrop`, which skits show on any of its maps that names none
(see the skits plan, Backdrops Follow The Place). A map's own `skitBackdrop`
(an asset-root-relative PNG) wins over its region's.

## Map roles

A map may name what it is to the world in its data's `role`, one of the
project's roles in `Data/map-roles.php`. A role has a localized name and,
optionally, `leadsTo`: the roles its maps' transfers may lead to. A role
without `leadsTo` leads anywhere.

```php
return ['schema' => 'ichiloto.map-roles/1', 'roles' => [
    'overworld' => ['name' => ['message' => 'map_roles.overworld.name', 'default' => 'Overworld'],
        'leadsTo' => ['overworld', 'outside', 'dungeon']],
    'outside' => ['name' => ['message' => 'map_roles.outside.name', 'default' => 'Outside']],
    'interior' => ['name' => ['message' => 'map_roles.interior.name', 'default' => 'Interior']],
]];
```

The Engine defines no roles and enforces none at runtime: a game's travel
rules are its own authored data. `MapRoleCatalog` validates the catalog (at
most 64 roles, stable ids, destinations that are roles), reads a map's role
with `readMapRole()` and answers `allowsDestination()`; the Editor's
validation applies it to every route `MapRouteGraph::readMap()` finds.

## Place types

A map may name the type of the place it belongs to in its data's
`placeType`, one of the project's types in `Data/place-types.php`, each with a
localized name: a city, a town, a village, a castle, an outpost, an inn, a
shrine, a cave, a wild area, or whatever a game names. A place type is the
scale and nature of the whole place, so a player can tell a larger settlement
from a single building on the world map. It differs from the map's role (what
one map is to travel) and from a landmark's category (what a building inside a
place offers).

```php
return ['schema' => 'ichiloto.place-types/1', 'types' => [
    'town' => ['name' => ['message' => 'place_types.town.name', 'default' => 'Town']],
    'outpost' => ['name' => ['message' => 'place_types.outpost.name', 'default' => 'Outpost']],
]];
```

A tileset gives each type the piece a map drawn with it shows over an
entrance into a place of that type, in `placeIcons` (see the graphical field
guide). `PlaceTypeCatalog` validates the catalog (at most 64 types, stable
ids) and reads a map's type with `readMapPlaceType()`.
`Validation\MapPlaceIconRule` holds every entrance on a map whose tileset
declares place icons to its destination's type: the type's piece stands whole
over the entrance, in its tiles and its terminal glyphs (`place.icon`), and a
place icon over a way into a map naming no type is reported (`place.untyped`).
The findings are warnings. The Engine defines no type and changes nothing at
runtime.

## The map screen

The `map` action (M by default) opens the entire active map with the player's
physical position. `map_view` switches to the connected places around it. The
Terminal connectivity view replaces the old large name boxes with one cell per
location. Only the focused place is named in the detail panel:

```
       ■────┐
            ■────┐
            │    ↓
            ■    ■

  Town Center — You are here
```

Nothing about this is authored twice. `Field\RegionMap` reads every map's
`name`, `region`, transfer events and legacy transfers. Its source-owned routes
reuse the landmark catalog and saved discovery policy. `getKnownConnections()`
returns directed connections and optional known names without mutating discovery,
access or saved state. Secret routes require their own explicit revelation even
when the destination has already been visited. Ordinary locked routes stay visible.

The view opens with the current place focused. Directional actions move the cursor
spatially; page actions step through all visible locations, and the retained Home
key focuses the current place. Focus highlights its connections. Arrows show known
travel direction; known reciprocal transfers share a line. Reverse travel is not
inferred from an unvisited map. Lines route around unrelated
blocks. Back or the map action returns to exploration. The panels fit the logical
screen, including an 80x24 terminal. Oversized diagrams follow the focused block;
manual pan-first navigation and always-visible place-name boxes are removed.

### Where a place is drawn

**Where a door sits on its map is where the place behind it lies.** The house's
door is up on the north-west side of the square because the house is north-west
of it; the shop's is straight down at the south. The engine reads the door's
position out of the map's event layer and places the destination that way, so
a region that was drawn sensibly maps sensibly, for free.

A door in the middle of a map says nothing about direction, and its
destination is simply set down beside what it connects to.

The region is laid out around its **hub**, the place with the most doors,
rather than around the player, so it does not rearrange itself depending on
where the player is standing.

When the doors get it wrong, a map can say where it belongs:

```php
return [
  'name' => 'Sealed Vault',
  'region' => 'Crypt',
  // Grid position on the region map. Beats anything the doors imply.
  'station' => ['x' => 4, 'y' => 2],
];
```

Stations are region-grid coordinates, not local field tiles or transfer
arrival positions. The diagram compresses unused station gaps while preserving
cardinal order. It does not allocate a world-sized raster for distant station
values or change authored stations, physical coordinates, transfers or saves.
Every distinct authored pin is reserved before unpinned places are inferred.
For duplicate pins, the first map in stable file-scan order keeps the pin and
the others use the next free cells east, without taking another authored pin.
This resolves display overlap without rewriting the project's station data.

What the player has seen governs what it says:

- visited places can show their name under focus,
- an immediately visible destination stays unnamed until visited or its owning
  route's declared label has been learned through approach or play,
- anything further is not drawn at all.

Visits are recorded in the world state (`GameState::markMapVisited()`, called
when a map loads) and ride the save file, so a map fills in as the game is
played. Immediate known exits outside the region are included as distinct blocks.
An unvisited inbound source does not reveal itself merely by linking to a visited
destination. Names from unexplored maps are never inferred from file metadata.

A region can hold a map no door reaches, one entered only by a cutscene, say.
It is still part of the region and becomes visible when visited.

## Random encounters

A map turns on random encounters by naming the troops that can appear and how
often:

```php
'encounters' => [
  // Troop name (from assets/Data/troops.php) => weight.
  'troops' => [
    'Bat x 2' => 5,
    'Rat + Bat' => 4,
    'Great Wolf' => 2,
  ],
  // The average number of steps between fights.
  'rate' => 10,
  // Optional: 'any' counts every tile, the default counts encounter tiles.
  'tiles' => 'encounter',
],
```

Weights are relative, so a `Bat x 2` above is met roughly five times as often
as `Great Wolf` twice. A map with no `encounters` key never rolls one.

Only steps the player takes count. A move route, cinematic or other scripted
movement of the player still runs the triggers of every cell it reaches, but
never advances encounters, walking conditions such as poison, or anything else
that counts the player's walking (`Field\Enumerations\StepOrigin`).

The engine warns when a map declares `encounters` in a shape it cannot read,
because a silent no-op is indistinguishable from a design decision.

### Encounters limited to terrain

On a map painted in terrain kinds, a troop can live on some terrain: its
entry names the encounter terrains of the map's kinds it is met on, such as
`'Bog Toads' => ['weight' => 3, 'terrains' => ['wetland']]`. A cell's terrain
comes from the kind its glyph names, by the same top-down walk as collision
(`MapEncounterTerrain`); whether a step counts stays the cell's collision. A
troop naming a terrain no kind of the map meets is never met (the engine
warns, and Editor validation reports it). Terrains combine with `areas`: a
troop limited to both is met only on its terrains inside its areas.

### Encounters limited to named areas

A troop can live in part of a map: its entry names the map's named `areas`
(see Named local areas) it is met in.

```php
'encounters' => [
  'troops' => [
    'Roost Bats' => ['weight' => 3, 'areas' => ['east-roost', 'shaft-roost']],
    'Store Rats' => ['weight' => 2, 'areas' => ['supply-nook']],
    'Road Wolves' => 1, // anywhere the map counts steps
  ],
  'rate' => 24,
  'tiles' => 'any',
],
```

A troop with `areas` is met only while the player stands in one of them; a
troop without is met anywhere the map counts steps. A step where no troop can
be met does not count toward the next encounter, so a map whose troops all
live in areas is quiet between them and `rate` is the average number of
counted steps inside those areas. The danger-tile rule still applies first:
with `'tiles' => 'encounter'`, only danger tiles inside an area count.

`areas` is a nonempty list of unique area ids. An entry whose scope cannot be
read, or that names an area the map does not declare, is never met (the
engine warns): reading it as the whole map would put the troop where its
author kept it out. `EncounterManager::getTroopWeightsAt()` reports the troops
a cell offers.

### The encounter table

`EncounterTable::readBlock($encounters, $areas, $terrain)` reads a map's block
once into what it means: the troops met and where, the average steps between
encounters (`averageStepsBetween`, fifteen when `rate` is missing) and how much
each step counts (`getStepWeight()`: none where the step does not count or no
troop can be met, one for a counted step, two for a danger tile on a map that
counts every tile). Parts it leaves out are named in `ignored`. The field's
`EncounterManager` counts the player's steps with it, and the Editor's walk
measurement reads the same table, so both answer the same way.
