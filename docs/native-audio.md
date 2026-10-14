# Native audio - authoritative plan

This plan replaces per-sound command-line players with a native audio
component: one persistent process that owns the audio device, decodes and
mixes every sound in software, and routes sound through buses with their
own volumes. PHP keeps owning what plays and when; the native component
owns decoding, mixing and the device. It serves terminal and GPUI sessions
alike, and the command-line players remain as the fallback when the
component is not installed. Related docs: [audio.md](audio.md),
[skits.md](skits.md), [rendering/process-transport.md](rendering/process-transport.md).

## Principles

1. **One audio path for every presentation.** Audio is not graphics. The
   terminal and GPUI get the same device, mixer and timing; terminal-first
   holds for sound as it does for everything else.
2. **PHP owns intent, native owns sound.** PHP decides what plays, when, on
   which bus and at what volume, and owns settings and lifecycle. The
   native component decodes, mixes, schedules fades and reports what
   happened. It never makes gameplay decisions.
3. **Audio never gates the game.** A missing component, a failed device, an
   unsupported file or a crashed process degrades to the command-line
   players and then to silence, logged once. Nothing waits forever on a
   sound.
4. **Fail the asset, never the surface.** One undecodable file fails that
   sound only; the mixer and every other sound keep playing.
5. **Installed like the renderer.** Players receive a verified platform
   package; nobody installs Rust or build tools to hear the game.

## Current state

- `AudioManager` delegates every sound to a host command-line player
  (`afplay`, `mpv`, `ffplay`, `paplay`, `mpg123`, `aplay`), spawning one
  process per sound through `proc_open`. PHP has no audio API; this was the
  only zero-dependency option.
- Consequences of that design:
  - latency and occasional hiccups from spawning a process per sound
    (observed on WSL);
  - format support depends on which player the host happens to have
    (`aplay` cannot decode mp3);
  - volume changes and music ducking restart the music process, which is
    audible;
  - "is this line still playing" is answered by process liveness, not by
    the audio itself;
  - native Windows has no audio: the backends refuse it outright.
- Speech has an owned channel (`playSpeech`, `stopSpeech`,
  `isSpeechPlaying`) and optional music ducking, built on the same players.

## The model

### The native component

A separate executable, built from its own repository in Rust and shipped
as a verified platform package through the same package format and
installation boundary as the renderer. The recommended stack:

- `cpal` for device output (CoreAudio on macOS, WASAPI on Windows,
  ALSA/PulseAudio/PipeWire on Linux and WSL);
- `symphonia` for decoding (mp3, ogg/vorbis, opus, wav, flac);
- `kira` for mixing, buses, tweened volume and a sample-accurate clock.

The crates are MIT/Apache-2.0 licensed, except symphonia (MPL-2.0).

### Buses

Every sound plays on one bus. The buses are **Master**, and beneath it
**Music**, **Effects** and **Voice**; system sounds play on Effects. Each
bus has its own volume and mute, driven by the player's settings.
Ducking is a tweened volume change on the Music bus while Voice is active,
never a restart.

### Protocol

Versioned NDJSON over the child's stdin/stdout, following the renderer
transport's ownership rules (one direct child, argv without a shell,
bounded queues, explicit shutdown). PHP sends commands; the component
reports events.

- Commands: `hello` (asset root, protocol version), `play` (sound id, bus,
  asset-relative path, loop, volume, fade-in, start offset), `stop` (sound
  id, fade-out), `set_bus` (bus, volume, mute, fade), `duck` (bus, factor,
  fade), `pause_all` / `resume_all`, `shutdown`.
- Events: `ready` (protocol version, decodable formats, device name),
  `started`, `finished` (sound id, whether it ended or was stopped),
  `error` (sound id or device, message), `device_changed`.
- Paths are resolved only inside the asset root, with the same symlink-safe
  containment the renderer applies.

### Engine integration

`AudioManager` keeps its public API; game code does not change. Beneath it
sits one output seam with two implementations:

- **Native output**, used when the component is installed and its
  handshake succeeds.
- **Command-line output**, the existing players, used otherwise.

A component that fails its handshake or exits during play is logged once
and replaced by the command-line output for the rest of the session.
`finished` events give speech an exact end, which drives Auto advance and
the lip-flap state, and replace process-liveness polling.

### Settings

The Config menu exposes Master, Music, Effects and Voice, each with volume
and on/off. Voice is independent of Effects. The command-line output
honours the same settings as far as its players allow.

## Phases

### Phase 0 - The engine seam (no native code)

1. Introduce the output seam inside `AudioManager` and move the existing
   players behind it as the command-line output, with no behaviour change.
2. Buses as a concept in PHP: every play call names its bus; settings map
   to bus volumes.
3. Independent Voice setting and per-bus volumes in the Config menu.

### Phase 1 - The native component

1. New repository, protocol v1, device output, decoding, the four buses,
   play/stop/fade/duck, `finished` events.
2. PHP packaging script producing the verified platform package, following
   the renderer's `scripts/package.php`.
3. Installation through the Console, reusing the verified package
   installer.

### Phase 2 - Engine integration

1. Native output implementation with handshake, capability check and
   fallback to the command-line output.
2. Ducking as a Music bus tween; speech `finished` events drive Auto and
   lip flap.
3. Pause and resume on game suspension and pause menus.
4. Development updates follow the renderer's source preparation, so a
   changed component rebuilds before a new session.

### Phase 3 - Platforms and documentation

1. Validate macOS, Linux, WSLg and native Windows: startup, latency,
   device loss and recovery, long sessions.
2. Update [audio.md](audio.md) to describe the native component first and
   the command-line players as the fallback.

## Decisions already made (do not relitigate)

- Audio moves to a standalone native component serving terminal and GPUI
  sessions alike, not audio inside the GPUI renderer.
- The command-line players remain as the fallback when the component is
  absent or fails.
- The component is installed as a verified platform package, like the
  renderer; players never build it.
- Voice has its own setting, independent of sound effects.
- Voice lines remain authored as mp3.
