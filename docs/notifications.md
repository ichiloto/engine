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

Entry and exit slides take 0.30 seconds each. Reduced-motion mode suppresses
the slides while retaining the full stationary hold time.

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
