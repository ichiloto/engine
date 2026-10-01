# Notifications

Ichiloto notifications are queued, non-blocking overlays for brief system,
quest, achievement, and field updates. Their timing is resolved centrally by
`NotificationTimingPolicy`; game content should continue to request the
semantic `SHORT`, `MEDIUM`, and `LONG` presets instead of duplicating timing
values at each call site.

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
parsed to guess icons or color. Quest completion, progression rewards and
quick saves supply explicit roles; identity, outcomes, sound and timing remain
with their existing owners. No graphical button, countdown or input binding is
added.

Titles, bodies, explicit line breaks and counts wrap as live text without
ellipsizing. At entry, the composer chooses a clear top-right or top-left
corner using actual panel, text, actor, prompt, HUD and transition bounds.
The anchor stays stable; if it becomes occupied, presentation is deferred.
Dense menus and skit stages can therefore delay a notice rather than cover
dialogue, selections or actors. Deferred time pauses both decorative phase
time and the stationary hold, preserving order and full delivery time.
Content that cannot fit the finite readable viewport is diagnosed once and
remains queued, not silently truncated or expired. Such a message needs a
larger usable viewport or shorter authored content; notifications remain a
brief-message surface, not a long-document viewer.

Missing replaceable artwork has diagnostics and a readable unskinned panel.
Invalid theme data falls back to the existing terminal presentation. During a
terminal fallback, an opaque graphical menu yields to the terminal scene so
the notice cannot be hidden behind it. Removing a graphical notice removes
only its canvas entities; field/world retention and underlying menu state are
unchanged.
