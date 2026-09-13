# Region Map Viewport Validation

Date: 2026-09-13. Branch: `fix/region-map-viewport`.
Base: `daba78936449d1107b5e0028375e3b83399e624a`, the terminal viewport-cap prerequisite (PR #86).

## Defect and Scope

The reported missing maps exist on disk. The fixed map panel silently dropped
labels whose region-grid stations projected outside its dimensions. Garden's
four normalized stations were `(6,0)`, `(30,10)`, `(0,18)`, and `(0,28)`;
with 18-column labels, these projected to `(139,1)`, `(691,21)`, `(1,37)`,
and `(1,57)` in a 108x26 content area. None could be drawn.

The initial 28-map audit found six clipped current locations, five entirely
blank panes, and overlapping BSA Assessment/Field Sector pins at `(6,4)`.
This fix addresses viewport access and collisions in the existing region
graph, not geographical/cartographic redesign or station-data interpretation.

## Changes

- Open the map on the current location; movement bindings pan, `Home` returns here, and existing close actions remain unchanged.
- Keep the authored grid spacing. Allocate only a viewport-sized canvas and clip connector loops before iteration.
- Reserve all distinct authored pins before resolving duplicates or inferring unpinned locations. Search beyond 32 occupied cells without returning an occupied fallback.
- Use each Window's actual padded content dimensions and fixed height. Map/info panels fit both 135x36 and 80x24 logical screens.
- Retain discovery rules, unknown adjacent places, compass, and current-place highlighting. No gameplay coordinates, saves, map content, or renderer protocol changed in this fix.

## Automated Validation

- Full Engine suite: **1,369 passed, 1 existing skip, 5,530 assertions**. The skip is the existing Enemy construction test.
- Focused RegionMap/MapState suite: **30 passed, 276 assertions**.
- Serial PHPStan: **no errors**. All five changed PHP files pass syntax checks; `git diff --check` passes.
- New tests cover sparse pins, stable geometry, duplicate-plus-unique pins, pinned traversal, more than 32 collisions, million-cell link spans, pan reachability, actual Window output, and Home/input handling.
- Renderer task's independent read-only audit: all 28 Last Legend maps show the current label with current-only and all-visited discovery. Every discovered place is reachable by panning at content sizes 106x26, 76x18, and 36x8. No visibility or reachability failures.

Local diagnostic evidence: `/tmp/ichiloto-region-viewport-review.php` and
`/tmp/ichiloto-region-viewport-review.json`. Source reflection confirmed the
isolated `engine-map-viewport` worktree. No game construction, window, or save
mutation was needed for that audit.

## Separate Map-Close Restoration

The incomplete field after closing the map was a separate CLI stdout-forwarding
defect. It is fixed by Console commit
`10dc0b3eefe38cdc39ab7f1c3d7a08e0b4be6eea`, not by adding MapState exit clears.

An independent private PTY capture used unchanged Engine
`bef20a24754df1358c8825b4fc9f4d0b0a658f64` with that CLI fix and a copied
legitimate Garden checkpoint. Field -> map -> field -> eight idle frames kept
the player at `(8,3)`. All 36 restored rows matched the original field and the
idle canonical frame. The capture was sampled at 500ms while the child remained
alive: 83,664 bytes, zero differing rows; SHA-256
`a24149c73755ecefcffee440aedc99593b0fff146641ba535a23fc1acb358677`.

Local capture result:
`/var/folders/x8/x3v4z6ks2wjctdp5hd90dmq40000gn/T/ichiloto-map-dismissal-70xx1r04/result.json`.
Audio and autosave were disabled only in the private fixture. No native window
was opened and the user's running game was not controlled or restarted.

## Limits and Integration

These are automated/windowless PTY results, not native visual acceptance or a
scrolling-performance benchmark. Linux/WSLg remains untested. The broad Last
Legend suite was not completed because of the unrelated 180,000-battle
simulation baseline. The dark-player-on-dark-field issue is an art/background
concern. No renderer protocol or game-content changes were part of this slice.

This branch requires ordinary review and integration into `develop`, including
the viewport-cap prerequisite. No protected-branch bypass is authorized. The
map redesign remains separate. S8-B native acceptance, renderer installation,
and the matched scrolling comparison remain pending their existing integration
gates; this map repair does not establish acceptance for S8-B.
