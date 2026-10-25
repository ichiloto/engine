# Changelog

## Unreleased

### Added

- Separate Music, Sound Effects and Voice volumes beneath the master volume:
  `Audio\Enumerations\AudioBus` names the bus every sound plays on, and a
  sound plays at master volume x bus volume x its own authored volume.
  `audio.music_volume`, `audio.sfx_volume` and `audio.voice_volume` (0 to 100,
  default 100) are authored per game in `config.php` and chosen by the player
  in Config and the title Options (Master Volume, Music Volume, Sound Effects
  Volume, Voice Volume), kept in player settings. Earlier player settings keep
  their master volume; each bus starts at the game's authored volume. Changes
  apply live: music already playing restarts at its position on a
  seek-capable player, and running loops (the counter tick, the EXP tally)
  restart at the new level. `AudioManager::getBusGain()`, `getBusVolume()`,
  `isBusEnabled()` and a public `getMasterVolume()`.
- Solid fills (an Engine-drawn slider track, divider or backing) pack onto
  shared whole-pixel lattices when glyph batching leaves a canvas over its
  text-layer budget, so a dense Config list with four sliders no longer falls
  back to the Terminal presentation in GPUI.
- Dash, as RPG Maker's: while the `dash` action is held, each held field step
  takes the walking time divided by the project's multiplier, authored in
  `Data/system.php` (`'dash' => ['enabled' => true, 'speedMultiplier' => 2.0]`,
  greater than 1 and at most 4; no `dash` means no dash). `PlayerWalk::update()`
  takes the multiplier for the step it decides, so the step clock, the slide
  the renderer draws and the walk animation share one duration, and steps
  chain end to end across a change between walking and dashing. A map turns
  dashing off with `'disableDashing' => true`. Always Dash (`field.always_dash`)
  is a player option in Config and the title Options; with it on, holding dash
  walks. `dash` defaults to Shift where nothing claims it, with B / Circle
  (`face_east`) reserved for a controller. Encounter and other step counts are
  unchanged by dashing. The Terminal reports no held control, so it offers no
  dash and leaves Dash and Always Dash out of Controls and the option lists.
- `KeyCode::SHIFT` and the `modifier_controls` renderer event subscription
  (over `key_transitions`): Shift held alone is reported as control `shift`,
  pressed and released with the modifier. The Game's GPUI session requires it.
- `GameScene::runAtFieldPace()`: what a step's arrival sets off (an event, a
  cinematic, a transfer) no longer inherits the pace of the step that arrived,
  so an actor started by an arrival walks at its own pace after a dash.

- Painted doors on tileset pieces: a stamped piece, such as a building,
  declares its `doorway` in the same form as a world object's resource (cells
  from its top-left cell, each entered on its opening or from the front), read
  by the shared `Doorway`. A door entered on its opening may not be solid in the
  piece's own occupancy. `MapGraphics::findPieceStamps()` finds every copy of a
  piece standing whole on a map's tiles (`FieldPieceEffects` now uses it), and
  `MapDoorwayRule` holds each copy's doors to the map's entrances; the passage
  agreement leaves those door cells to it.
- Keycaps for every control, drawn by one owner: `ControlGlyphPresentation`
  graphically and `TerminalControlText` in the Terminal ("[Enter]"). A theme's
  `controls` sets in `Data/Presentation/menus.php` give each input device family
  its look: optional keycap frame artwork (nine-sliced, so its width follows
  the key's name), keycap colour roles and per-control glyph images. Without
  artwork the Engine draws a rounded, lipped key in the theme's colours.
  Swapping to a controller needs only a display provider reporting its family
  and a `controls` entry with its button glyphs. `ActionHints::resolveControl()`
  resolves a control for a hint that shows the key alone,
  `InputBindings::getControlsForAction()` lists every bound key,
  `MenuCanvas::renderControl()` places one control on its row's centre line,
  and `Window::setControlHints()` keeps a help line and its semantic hints
  together so graphical owners draw the same hints as keycaps (battle panels
  do).
- Declared guidance motion: a renderer advertising `sprite_bob` is told a
  cue mark's bob once (`PresentationSpriteBob`, height and seconds, from
  `cueStyle.bob`) and draws its rise and fall at display rate on its field
  clock; reduced motion leaves it off. A renderer advertising
  `pinned_sprite_motion` slides screen-pinned edge markers from where they
  are drawn to their new place with the motion of the camera's step (or of
  their moving subject), on the camera's clock. Other renderers receive the
  whole-pixel rise each frame and pinned markers as before.
- Joined corner-edged kinds: corner-edged kinds on one tile layer whose art
  names the same join group (`'joins' => 'water'`) share one edge
  (`CornerShape::resolveLayer` takes `Tileset::getCornerJoins()`). Each counts
  the others' cells when it chooses a corner's case and draws only its own
  cells and the ground beside them, so a river runs into a lake or the sea
  without a bank or strip of ground between them. A join without a corner
  sheet, or across tile layers, is refused. Kinds in no group draw exactly as
  before.
- Corner-edged terrain kinds (the dual-grid technique): a kind whose art
  names a corner sheet (`'art' => ['layer' => 'water', 'tile' => 2096,
  'corners' => 'Graphics/Tilesets/World/Water.corners.png']`) draws its edges
  on the corners between cells, one tile per corner chosen from which of the
  four cells around it hold the kind (`CornerShape`, 16 cases laid out as one
  seamless 4 x 4 picture per phase; one still phase or three cycled 0, 1, 2, 1
  as A1 water). Diagonals, bends and one-cell diagonal streams draw as
  continuous edges instead of stair steps. The kind's autotile stays the
  identity tile layers hold, so maps, the terminal, collision, encounters and
  saves are unchanged. Each cell's quarters are the facing quarters of its
  corner tiles, uploaded in a layer per kind after the kind's tile layer
  (`tiles:<layer>:corners:<kind>`), kinds sharing a tile layer in kind order;
  the corner sheet is sent only to maps that show the kind, so other maps
  upload byte for byte as before. An unusable corner sheet is reported and
  its kind's cells show their glyphs. `TileCoverage::findUncovered` takes a
  cell's corner shares. `tools/corner-sheet-placeholder.php` writes a
  placeholder water or sand corner sheet.
- Tileset stacks: `'stacks' => ['relief' => ['layers' => ['relief-1', ...]]]`
  names tile layers whose tiles overlap. A piece whose tiles go on a stack's
  first layer is decoration only (space glyphs, no occupancy, effect or kept
  layers), each of its tiles draws one piece cell, and `TilesetStack::layTile`
  orders a cell's tiles by the pieces' ground rows, then columns, so editors
  lay overlapping objects such as world map peaks as the field orders
  characters. A stacked piece's tile layers are all of its stack's
  (`Tileset::getPieceTileLayers`), owned by the piece's gameplay layer.
- Terrain kinds for classic world maps: a tileset's `kinds` give each kind a
  coloured terminal glyph, an autotile (or an explicit awaiting-art state),
  passage on foot, an encounter terrain, an atlas role and, for a feature, the
  ground kind it stands on. Each kind is placed through a one-cell piece. A
  map opts in with `'terrainKinds' => true`; its kinds then feed the existing
  collision dictionary (or, under declared occupancy, are written into it by
  editors), its encounter terrain (`MapEncounterTerrain`, troops limited with
  `terrains`) and its atlas roles. `MapTerrainKindRule` reports cells whose
  glyph and tiles show different kinds, and cells of kinds awaiting art.
- Quests may author `startWaymarks`, in the objective waymark shape, leading
  to where the quest starts while it can start (not yet accepted or finished,
  prerequisites holding). A main quest's start waymark keeps the story
  wayfinder from filling that step; the first main quest's leads the opening
  of a new game. `StoryGuidance::findUnguidedStarts` reports main quests
  nothing starts or nothing leads to, read from the same story index as the
  runtime. A start waymark also leads to a story gate whose flag is set by
  what starts its quest.
- The optional v2 renderer capability `canvas_stroke_dashes`: a composite
  stroke may carry `dash` (`length`, `gap`) and is drawn dashed as one
  operation. The Engine offers it to every v2 renderer and sends dashes only
  once negotiated; requiring it needs `graphical_canvas` and
  `canvas_compositing`. Install the updated renderer to use it; older
  renderers keep a masked, budgeted dashed style.
- A mastery branch may grow `within` another branch of its region
  (`['id' => 'time', 'within' => 'diamonds-non-elemental', ...]`) as a
  sub-family of that branch's family. The Magi Arts board sets it inside the
  outer family's tree under that family's top, with its own heading or
  foundation, name, Info summary, count and icon; the discipline lists only
  the families growing from it, and the Terminal lists the sub-family in its
  family's list with its count on its top row. Where the outer branch has a
  foundation, the sub-family's chain grows from it. Node ids are unchanged, so
  moving a branch in or out of another needs no save migration. The tree
  refuses an unknown outer branch, one in another region, and branches
  growing within each other in a loop.
- Knowledge record types may carry an authored `label`
  (`['id' => 'summon', 'label' => '...']`); Records shows a type by its label,
  or its id while it has none. A subject may name the `summon` it is a record
  of: a summon met in battle is discovered and counted like any other record,
  and its Records detail reads that summon's Summon Info (lore, Move, Profile,
  Resistances) through the shared `SummonInfoSheet`. A subject's `quickCard`
  may be left empty until it is written.
- Battle events: troops author `events` pages (conditions on turn, enemy HP,
  party member HP and world state; a `battle`, `turn` or `moment` span) that
  run the shared event interpreter over the paused battle. Battle commands
  `concede` (an enemy yields: defeated for victory and rewards, kept on screen
  in a `concede` pose or a subdued hold, no knockout fade) and `end_battle`
  (victory, escape or defeat). Troop members take `knockoutFloorPercent`.
  Registered commands declare `scopes`; they run in field scripts unless they
  declare `battle`. Every battle ending goes through `BattleConclusion`. See
  [docs/battle-events.md](docs/battle-events.md).
- `cueStyle` in `Data/Presentation/field.php` keeps cue marks readable on any
  ground: an optional gentle bob (still under reduced motion), the edge pointer spacing, and a Terminal
  background cell and bold for every cue glyph (default black and bold).
- Edge markers are composed from the upright cue mark and a separate pointer
  that alone turns, on the outward side. Each cue colour may name a `plate`
  drawn behind its marks.
- NPCs take the same authored `cue` as events, drawn above the NPC with the
  shared cue treatment; a waymark on that NPC lets its cue stand in.

- Event speakers: a map event names the NPCs who speak it (`speakers`), and
  map validation (`event.speaker`) reports a speaker who is not on the map,
  is never present while the event can run, wanders freely or stands more
  than one cell from the event's area.
- World-object resource doorways: a resource declares the cells where its art
  paints a door (`doorway`) and where each door's entrance lies (`entrance`:
  `'on'` the door, the default, or `'front'` of a door painted on a solid
  face), and map validation (`object.doorway`) reports a placement whose
  doors do not meet an entrance exactly as declared, or an entrance
  behind an object's ground row inside its covered cells, where the art hides
  the player.
- `Core\WorldConditionExclusion`, the proof that two condition lists never
  hold together, moved from the Editor so the Engine's rules share it.
- Objective waymarks: quest objectives author `waymarks` (a map and one
  stable event, NPC, world object or landmark, with their own conditions for
  phases and free-order work). Off-screen waymarks hug the field view edge
  with an arrow in both renderers; on-screen ones draw the game's waymark
  symbol. The local map and the world map mark objectives with legend
  entries, and the next usable exit is marked for objectives on other maps
  through `Field\MapRouteGraph` (transfers and reusable scripted transports).
  The style is `waymarks` in `Data/Presentation/field.php`; map markers use
  the menu theme's new `objective` colour and `map.objective` icon roles.

- Held walking in the graphical field, following RPG Maker MZ: while a
  direction is held the player keeps walking one committed cell at a time at
  180 field pixels per second (8/60 s sideways, 16/60 s vertically), the most
  recently pressed held direction winning. The renderer slides characters
  between cells over each step and moves a following camera with the player.
  The terminal keeps its event-only stepping unchanged.
- The `key_transitions` renderer subscription (key presses with a stable
  control identity, releases and input resets), bounded held state in
  `InputManager` (`isButtonHeld()`, `wasButtonPressed()`,
  `getButtonPressOrder()`), and the `field_motion` drawing feature (sprite
  `motion` and viewport `follow`).

### Changed

- Config and the title Options play the cursor (or confirm) sound after a
  setting changes instead of before, so a volume is heard at its new level.
  The Volume row is labelled Master Volume and SFX is labelled Sound Effects.
- A sound effect whose gain is zero (master, Effects or its own volume at 0)
  starts no player process. A master or sound volume that is not a number
  plays at its default with a warning; a numeric string master volume used to
  be read as its number.
- Dialogue's advance hint is the confirm key alone ("Enter"), the same while
  text types and when it waits; "Reveal" and "Continue" are removed from the
  footer, graphical and Terminal. The Auto key ("Space") is a keycap and every
  footer control shares the Auto button's centre line (the hint had sat at the
  top of its row).
- Key names come from one mapping, `ControlHint::keyboard()`: Esc, PgUp, PgDn
  and arrow symbols (↑ ↓ ← →) replace Escape, Page Up, Page Down and Up/Down/
  Left/Right, and `describeKeys()` uses it (Controls lists "Space, Enter"
  rather than "SPACE, ENTER"). Hint strips read "keycap label" without a colon.
- Every hand-written key help line (battle, Magic, Abilities, Status,
  Equipment, Config, party selection, shop quantity, arena, modals, title
  Options, item paging) now comes from semantic hints, so a rebound key shows
  everywhere. Fixed-key controls (battle Info and Back, Controls' Restore
  Defaults, cancelling a rebind) name their fixed keys through the same owner.
- Field guidance draws above every map layer: `PresentationLayerPolicy` now
  owns all field bands, with a guidance band (`FIELD_GUIDANCE`, 1000) above
  the field world and below the interface. The action prompt, event and NPC
  cues, quest waymarks and their edge markers draw there in the graphical
  field (plates at 1000, marks and pointers at 1001), and Terminal waymark
  glyphs too, so an above-character tile (a roof) on any map layer no longer
  hides them. Map tile bands come from
  `PresentationLayerPolicy::getTileLayerOrder()` and stay within 0..99 orders
  (`-100 + NN` and `900 + NN`). The interface band moves from 1000 to 1100
  (`PresentationLayerPolicy::UI`); cinematics, transitions and notifications
  keep their bands. Characters, world objects, signs and world canvases are
  refused at 1000 and above, as before; `FIELD_CUE_PLATE` and
  `PresentationWorld::BELOW_TILES`/`ABOVE_TILES` are removed in favour of the
  policy's bands.
- `TilePiece::$sheet` is the sheet's key as a string (an RPG Maker sheet name
  such as `'A2'`, or a corner sheet's key) instead of a `TilesetSheet` case,
  so a piece can be copied from a corner sheet. Compare with
  `TilesetSheet::A2->value`.

- Collision dictionaries read a glyph's own entry before its ASCII
  transliteration, so entries beyond ASCII (`▲`, `♣`) apply as authored; every
  existing example map resolves to the same collision. `resolveMap` and
  `resolveLegacyOccupancy` take the map's terrain kinds, and a map painted in
  kinds whose tileset cannot be read is refused rather than played with
  unknown passage. `EncounterManager::configure` takes the map's encounter
  terrain. `MapGraphics` loaders and `resolveLayerOwners` count kinds' tile
  layers only on maps painted in kinds.

- The drawn Magi Arts (Mastery) board is laid out the same whether Info is
  open or not: the Info sheet lies over the board as meta text and never
  moves, reframes or rescales the tree, the family cards or the camera. It
  stands at the top of the board's area, or at the bottom while the view is
  scrolled all the way up (the families' tops whole in view), so it does not
  cover the tops and headings; on its side it stops short of the focus, and
  takes the other side when the focus leaves less than a line there. The
  camera frames whole rows measured with each place's focus rings, badge and
  name: the focus with what it hangs from (and any wrapped rows of siblings
  between them) and what hangs from it, else the focus and what it hangs
  from, else the focus alone. A line leaves a node under its name and runs
  across just above the row it leads to, so no line runs behind a name.
  Focus rings part around the rim badge and the learned or locked mark
  instead of being bitten into by them. `MasteryBoardView` owns this layout
  and `MasteryMenuPresentation::layoutBoard()` returns it. The default
  `stageSpacing` is 132 (was 150), so three stages of named places fit.
- `MasteryBoard` wraps a row of siblings wider than `MasteryBoard::ROW_LANES`
  (four) onto rows below, evened out, so every sibling stands within one and
  a half lanes of what it hangs from and shares the board's view with it.
  What they hang from stands on a straight line down through a gap in each
  earlier row; the last row is centred under it. Board places carry the
  `row` they stand in; Up and Down on the drawn board step between those rows
  before reaching what they hang from. List order is unchanged, so the
  Terminal and saves are unaffected. `MasteryCamera` carries how many stages
  above the focus to keep in frame.
- `MenuShapes::strokeDashedLine()` takes a line's points (was two end points)
  and draws the whole line as one operation through negotiated
  `canvas_stroke_dashes`, or one per straight piece masked to its dashes for
  a renderer without it, so long dashed lines no longer exceed the frame's
  256 composite operations and send the menu back to the Terminal
  presentation. `MenuShapes::strokeCircle()`, `strokeArc()` and `strokeLine()`
  take areas to part around.
- Removed: places that do not fit wholly in the board's view are no longer
  drawn cut at its edges; the lines to them run off the edge. An Info text too
  long for the room beside the focus ends its last shown line with an
  ellipsis. The earlier unreleased behaviour that gave the open Info sheet a
  band of its own and drew the tree or cards below it (hiding the discipline's
  name above the cards) is removed.
- The terminal battle's troop zone starts at column 3, so the focus marker
  two cells left of a troop sprite stays inside the border.
  `TerminalBattleEffects::getTroopPlaceProblems()` names what an authored troop
  position leaves out (its target badge row, the focus marker, its step towards
  the party, the bottom border), by the zone `findTroopPlace()` places members in.
- Removed: superseded intermediate native frames are no longer delivered while
  the renderer is behind. At most two complete frames wait for the renderer,
  and changes made meanwhile go out as one newest frame when it catches up, so
  input no longer waits behind a queue of stale frames (title key-to-screen
  p50 681 ms to 76 ms under heavy CPU load).
- Retained element orders are sparse ranks that survive insertions, removals
  and moves, so one appearing canvas image, sprite or text layer no longer
  re-sends every element painted after it.
- Removed: cue bindings' `edges` (one baked image rotated whole, which laid the
  `!` on its side) are refused; author `pointer` images instead. Terminal cue
  glyphs now draw bold on a black cell by default.
- Removed: story cues (`cue.kind` `story`) no longer draw their own edge
  arrows. Off-screen guidance now comes from quest objective waymarks, which
  use the same cue binding edge images. Cue kind remains authored data.
- The Game's GPUI session now requires `key_transitions`; install the updated
  renderer with this Engine.
- Field walking frames advance by distance travelled instead of one stride per
  step, so both axes animate at one pace and continuous walking no longer
  stands between steps. `CharacterWalkAnimation::STRIDE_SECONDS` is replaced
  by `STOP_SECONDS`, and `step()` takes the `CharacterStep` it presents.
- Under reduced motion the player and staged actors now show the standing
  frame, as NPCs already did; previously they kept animating.

- Standardized whole-image factories on `getFromPng`. Removed the former
  `CanvasNineSlice::fromPng` name; callers now use `CanvasNineSlice::getFromPng`,
  matching `BattlerArtwork::getFromPng`. Image loading behaviour is unchanged.

### Removed

- Control glyphs in a theme's general `icons` map (`input.<family>.<control>`
  roles) and `ControlHint::iconRole()`: control artwork now lives only in the
  theme's `controls` sets. `ActionHint::describeControls()` is removed; the
  Terminal formats controls through `TerminalControlText`.
- Key words in prose: "Press enter to continue." under battle defeat and
  escape results, "R restores defaults" and "Escape cancels" in Controls, and
  "Use the arrow keys" in party selection and shop quantity text. Their help
  lines show those keys instead.
- `BattleTestMenuList::$help`: the arena's Terminal help line is derived from
  its semantic hints. Its separate "q:Quit" mention is gone (Q still quits from
  the troop list); "test grants only" is now the list's note.

- Automatic pause-on-unfocus for title animation and rolling credits was removed
  in `eb5e19e`. Removing `window_activation` from their capability requirements
  decoupled graphical presentation from renderer startup requirements, but also
  removed the automatic activation-event subscription. Normal launches therefore
  keep playback running when the window loses focus. Scene/modal/suspension
  pausing and explicitly configured activation subscriptions remain supported.
  This records the removal; it does not restore or newly approve that behaviour.

## 0.5.0

Ichiloto Engine 0.5.0 expands the runtime from a field-and-battle foundation
into a complete authoring target for story-driven terminal RPGs.

### Highlights

- Added cinematic and summon definitions, playback sessions, checkpoints,
  skip policies, finalizers, map triggers, party wielders, and the Summons
  menu.
- Added resumable story-event execution with typed command vocabulary,
  cooperative presentation lanes, conditional cues, and recovery after save
  and load.
- Completed the runtime identity and catalogue model for actors, classes,
  inventory, skills, enemies, knowledge entries, progression, equipment, and
  combat resolution.
- Expanded maps with regions, transitions, NPC state, random encounters,
  background music, camera scrolling, collision handling, and persistent world
  state.
- Added versioned save envelopes, engine schema migrations, project content
  migrations, aliases, tombstones, legacy-save detection, and compatibility
  diagnostics.
- Added battle simulation and reporting, escape policy, summon integrity,
  quest progression, achievements, shops, notifications, and audio lifecycle
  support.
- Hardened terminal rendering around partial writes, cursor placement, modal
  restoration, text wrapping, selection highlights, pause overlays, and the
  field HUD.

### Requirements

- PHP 8.4.1 or newer within the PHP 8 release line.
- Symfony Console 8.
- Pest 5.1 for the development test suite.
