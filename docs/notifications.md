# Notifications

Ichiloto notifications are queued, non-blocking overlays for brief system,
quest, achievement, and field updates. Their timing is resolved centrally by
`NotificationTimingPolicy`; game content should continue to request the
semantic `SHORT`, `MEDIUM`, and `LONG` presets instead of duplicating timing
values at each call site.

## Content and attention

Transient notices have a one-line headline and at most two short body lines,
measured at 36 terminal display cells per line. They announce an event, not its
whole report. Quest completion shows the quest name; reward and party progression
details use one acknowledged summary. Per-level "Party Progress" toasts are
removed. Battle level gains remain in the battle results presentation.

`NotificationManager` applies this policy to every producer, in both terminal
and graphical sessions. Oversized content is preserved in a queued
`PagedAlertModal`, never truncated, ellipsized or left indefinitely in the toast
queue. A short notice that cannot fit the current graphical line budget takes
the same route. Alert delivery belongs to `ModalManager`, at the next normal
game-frame boundary with no active modal. The blocked-frame pump never opens
these alerts, so they do not interrupt dialogue, replay its input or re-enter
the scene. Each page requires acknowledgement; full text and counts remain
available. Producers should use concise wording rather than depend on automatic
promotion for routine updates.

## Standard timing

| Preset | Stationary hold |
| --- | ---: |
| `SHORT` | 4 seconds |
| `MEDIUM` | 6 seconds |
| `LONG` | 8 seconds |

Entry and exit take 0.30 seconds each: slides in the terminal, opacity fades
on the optional graphical surface. Reduced-motion mode suppresses both while
retaining the full stationary hold time.

## Project policy

A project may override the generic defaults in `config.php`:

```php
'ui' => [
  'notifications' => [
    'durations' => [
      'short' => 4.0,
      'medium' => 6.0,
      'long' => 8.0,
    ],
    'animation_duration' => 0.30,
  ],
],
```

Explicit float durations passed to `notify()` remain supported. The player's
duration profile scales both semantic presets and explicit durations.

## Player profiles

The title and in-game configuration menus expose three profiles:

- **Standard**: 1× the project timing.
- **Long**: 2× the project timing.
- **Extended**: 10× the project timing.

The selected multiplier is persisted at
`accessibility.notificationDurationScale`.

## Graphical presentation

Notifications use the existing queue and shared `Data/Presentation/menus.php`
theme (`ichiloto.menu/1`), not a game-specific renderer or a second queue.
Graphical presentation requires the menu canvas capabilities plus
`canvas_overlay`. Without those capabilities or a usable theme, the original
terminal notification remains available. Custom `NotificationInterface`
implementations keep that fallback unless they also implement the optional
`GraphicalNotificationInterface`.

The optional theme `notifications` section accepts `width` (444), `maxWidth`
(520), `margin` (24), `padding` (24), `iconSize` (32), `iconGap` (16), `textGap`
(6), `maxHeightRatio` (0.4), and semantic RGB `colors`. Values in parentheses
are Engine defaults, not requirements for a game's artwork. Compact surfaces
reduce spacing and icon size. Typography, panel artwork and body color come
from the shared menu theme. A theme can bind icons such as
`notification.quest`, `notification.quest.complete`, `notification.reward`,
`notification.save`, `notification.info`, `notification.warning`, and
`notification.error`. Icon source dimensions come from the current PNG and
replacement artwork is contained without stretching.

`notify(..., presentationRole: 'save')` supplies optional semantic meaning.
Absent that role, the channel supplies it. Titles and translations are never
parsed to guess icons or color. Quest completion and
quick saves supply explicit roles; identity, outcomes, sound and timing remain
with their existing owners. No graphical button, countdown or input binding is
added.

Titles, bodies, explicit line breaks and counts wrap as live text without
ellipsizing. All graphical notices use the same viewport-relative top-right
anchor, including while menus are open. Automatic top-left fallback and
placement below occupied menu headings are removed. Panel width may grow for
complete content, keeping the same top and right margins; compact surfaces
use the theme's reduced margin.

Andrew's 2026-10-06 decision: notifications have the highest rendering
priority and never wait for scene content to clear. Protected-area suppression
and transition deferral are removed. Notices overlay menus, dialogue, skits,
modals and transition covers immediately at their normal anchor. They do not
reserve space, widen text gutters or enlarge menu headers. The compact
`MenuInfoPanel` measures only its own two-line information page.

The queue belongs to the running game, not a scene or map. Transfers, battle
entry/return and scene replacements do not clear, reopen or restart an active
notice. The renderer retains a scene-only frame and composes the live notice
above the transition every frame, including callbacks outside the normal game
render loop. A notice can arrive or expire during a handoff without being
baked into either scene snapshot. Its ordinary entry/hold/exit lifecycle and
queue order continue; only existing engine-clock stall compensation applies.
Content that cannot fit the terse reading budget still becomes an acknowledged
alert, not a growing toast.

Missing replaceable artwork has diagnostics and a readable unskinned panel.
Invalid theme data falls back to the existing terminal presentation. On a
canvas-capable native session, that styled Terminal contribution is projected
as a live canvas overlay too, above opaque menus and transition covers without
replacing the scene. Lower-capability and Terminal sessions keep the named
notification overlay above other UI. Removing a notice removes only its own
contribution; field/world retention and underlying menu state are unchanged.
ANSI-styled producer text is measured and converted into scalar-aligned runs,
preserving highlights without passing escape sequences to the native renderer.

Shared canvas composition applies `CanvasTextBatch` to the complete scene and
live overlay, not only to individual menu builders. Dense scenes can therefore
retain their content while a notice is added without exceeding the unchanged
64-text-layer protocol limit. Only compatible fully visible, nonoverlapping
text on identical font-cell lattices is batched; clipping, glyph effects,
opacity, ordering and positions are not approximated. The retained scene is
immutable: repeated notices do not accumulate and dismissal restores it.
Headless retained/runtime tests exercise a full-budget scene plus a live notice
and menu-to-field replacement. The exact Happyville shop exit has not been
replayed in a native window by these checks.
