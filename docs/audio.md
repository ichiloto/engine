# Audio

The engine plays background music (BGM) and sound effects (SFX) through the
`AudioManager`. Like the rest of Ichiloto, audio is data-driven: game authors
declare tracks in the project config and map data, and the engine's systems
play them at the right moments. Direct calls on `$game->audioManager` exist
for custom systems, but a typical game never needs them.

## Declaring music (the normal way)

Scene themes live in the project config:

```php
'audio' => [
  'master_volume' => 70,
  'music' => true,
  'sfx' => true,
  'bgm' => [
    'title' => 'title-theme',        // plays on the title screen
    'battle' => 'clash-of-steel',    // plays during battles
    'victory' => 'fanfare',          // plays over the battle results
    'sleep' => 'lullaby',            // plays while the party rests at an inn
    'game_over' => 'requiem',        // plays on the game-over screen
  ],
],
```

Field music belongs to maps. A map declares its theme through the `bgm` entry
in its `.data.php` file:

```php
return [
  'name' => 'Overworld',
  'region' => 'Overworld',
  'bgm' => 'overworld-theme',
  // ...
];
```

The rules, mirroring RPG Maker:

- Entering a scene plays its declared theme and stops the previous scene's
  music; a scene with no declared theme is silent. Scene transitions are the
  single choke point (`SceneManager`), so music can never bleed into a scene
  that did not ask for it.
- Entering a map plays its declared theme. Moving between maps that share a
  theme is seamless (replaying the current track is a no-op). A map with no
  `bgm` entry keeps whatever is playing (autoplay-off semantics).
- Winning a battle starts the victory theme over the results screen; leaving
  the results restores the map's theme. It plays like any other track (it
  loops), so a long results screen never falls silent. A project that
  configures no victory theme simply keeps the battle music playing.
- Resting at an inn plays the sleep theme, then restores whatever was playing
  before the rest — sleeping never changes maps, so the music resumes exactly
  where it left off. A specific inn can declare its own track through the
  `bgm` entry in its `SleepEventTrigger` data, and a project that configures
  no sleep theme keeps the field music playing.
- After a battle, the current map's theme resumes automatically.
- A specific battle can override the project battle theme (e.g. a boss theme)
  through `bgm` in its runtime battle settings, and its victory theme through
  `victory_bgm`. Troops declare the battle theme directly in
  `assets/Data/troops.php`:

  ```php
  [
    'name' => 'Shadow Colossus',
    'bgm' => 'boss-theme',
    'enemies' => [/* ... */],
  ],
  ```

## Event-driven audio

For authored moments — a boss appearing, an eerie chamber — maps can place a
`PlayAudioEventTrigger` in their event data, the equivalent of RPG Maker's
"Play BGM" / "Play SE" commands:

```php
'M' => [
  'class' => 'Ichiloto\\Engine\\Events\\Triggers\\PlayAudioEventTrigger',
  'data' => [
    'bgm' => 'boss-approach',      // optional: replaces the music
    'sfx' => 'roar',               // optional: one-shot sound effect
    'once' => true,                // optional: fire a single time
    'restoreMapBgmOnExit' => true, // optional: restore the map theme on exit
  ],
],
```

Animation frames can carry a `soundEffect` cue that plays through the same
pipeline when the frame is shown during battle.

## System sounds

The engine plays RPG-Maker-style system sounds at built-in interaction points.
Games opt in per sound by declaring a track under `audio.sounds`:

```php
'audio' => [
  'sounds' => [
    'cursor' => 'cursor-blip',
    'confirm' => 'confirm',
    'cancel' => 'cancel',
    'buzzer' => 'buzzer',
    'save' => 'save-chime',
    'battle_start' => 'battle-start',
    'escape' => 'escape',
    'actor_damage' => 'actor-hit',
    'enemy_damage' => 'enemy-hit',
    'enemy_collapse' => 'enemy-down',
    'item_get' => 'item-get',
    'shop' => 'coin',
  ],
],
```

Sounds without a configured track are silent. The available keys are the
cases of `Ichiloto\Engine\Audio\Enumerations\SystemSound`.

The engine fires these at its built-in interaction points: cursor movement,
confirm and cancel across every menu surface (title, main menu, item, shop,
equipment, magic, ability, status, save, modals, and battle command
selection), a buzzer for disabled or invalid choices, the shop sound on
checkout, battle start, damage landing (party vs. enemy, with a separate
collapse sound for defeated enemies), chest loot, and saving. Dialogue boxes
deliberately stay silent when advancing text.

## Direct playback (custom systems)

The global helpers follow the same idiom as `alert()` — callable from any
engine or game code without threading the `Game` instance through, and safe
no-ops when audio is unavailable:

```php
play_sound(SystemSound::CONFIRM);  // a system sound by catalog entry
play_sound('roar');                // a one-shot sound effect by reference
play_music('overworld-theme');     // loops by default; replaces current track
play_music('jingle', loop: false); // one-shot track
current_music();                   // what is playing, or null
stop_music();
```

`current_music()` pairs with `play_music()` to interrupt the music for a
moment and put back exactly what was there — the pattern the inn rest uses.

The `AudioManager` on `$game->audioManager` exposes the same operations as
methods (`playBackgroundMusic()`, `playSoundEffect()`, `playSystemSound()`,
`stopBackgroundMusic()`) for code that already holds the game instance.

## Zero dependencies, graceful degradation

Audio is strictly optional. The engine ships no audio libraries and requires no
PHP extensions: playback is delegated to whichever command line player is
already installed on the host, detected once at startup.

| Player   | Platforms            | Formats                 | Notes                              |
|----------|----------------------|-------------------------|------------------------------------|
| `mpv`    | Linux, WSL, macOS    | everything              | preferred when installed           |
| `ffplay` | Linux, WSL, macOS    | everything              | part of FFmpeg                     |
| `paplay` | Linux, WSL (WSLg)    | wav, ogg, flac, opus    | present on most desktop Linux      |
| `mpg123` | Linux, WSL           | mp3                     | complements paplay                 |
| `aplay`  | Linux                | wav                     | ALSA last resort                   |
| `afplay` | macOS                | wav, mp3, m4a, aiff     | ships with macOS                   |

If none of these are installed — or a particular file's format is not supported
by any installed player — every audio call degrades to a silent no-op (logged
once to the debug log, never spammed) and the game remains fully playable.
`AudioManager::$isSupported` reports whether any player was found.

To get sound on a minimal Linux or WSL system, any one of these is enough:

```bash
sudo apt install mpv
```

## Track resolution

Audio references may be absolute paths, paths relative to the game's `assets`
directory, or bare names resolved against the conventional directories
`assets/Audio/BGM` and `assets/Audio/SFX`. The file extension may be omitted,
in which case `.ogg`, `.wav`, `.mp3`, `.flac`, `.m4a`, `.opus` and `.aiff` are
tried in that order.

```text
assets/
  Audio/
    BGM/
      overworld.ogg     <- playBackgroundMusic('overworld')
    SFX/
      menu-select.wav   <- playSoundEffect('menu-select')
```

## Project settings

The manager honours the same project config keys the title options menu edits,
and it honours them live — no restart required:

| Key                   | Default | Effect                                        |
|-----------------------|---------|-----------------------------------------------|
| `audio.music`         | `false` | Toggling off stops BGM; on resumes the track. |
| `audio.sfx`           | `false` | Gates sound effects.                          |
| `audio.master_volume` | `75`    | 0–100; changes restart BGM at the new volume. |

Note that music and SFX default to **off**, matching the title options menu;
enable them in the game's project config to ship with sound on.

## Looping

BGM loops by default (`playBackgroundMusic($path, loop: false)` for one-shot
tracks). Players that loop natively (`mpv`, `ffplay`, `mpg123`) are used as
such; for the rest the manager respawns the player when the track ends, from
`update()` in the game loop. A track whose player dies repeatedly right after
spawning is abandoned after three attempts so a broken file can never put the
game loop into a respawn storm.
