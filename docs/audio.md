# Audio

The engine plays background music (BGM) and sound effects (SFX) through the
`AudioManager`, available on every `Game` instance as `$game->audioManager`.

```php
// Loops by default; replaces whatever track is playing.
$game->audioManager->playBackgroundMusic('overworld');

// One-shot, non-blocking.
$game->audioManager->playSoundEffect('menu-select');

$game->audioManager->stopBackgroundMusic();
```

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
