# Voice-acted skits - authoritative plan

This plan grows the engine's existing skit system into fully voice-acted,
animated party conversations in the Tales tradition: emotional bust art,
lip and eye animation, a dedicated graphical stage, and Auto, Log and Skip
playback comfort - while the terminal presentation remains complete on its
own. Skits are an **engine capability**: every game built on Ichiloto gets
this functionality. Last Legend is the reference consumer. Related docs:
[story-events.md](story-events.md), [cinematics.md](cinematics.md),
[audio.md](audio.md), [effect-animation.md](effect-animation.md).

## Principles

1. **The terminal always represents the whole skit.** The authored beats
   (speaker, text, conditions) are the authoring truth, and voice is sound,
   not pixels: voice lines play identically on every presentation. Busts,
   emotions rendered as art, and lip/eye animation are GPUI fidelity,
   invisible in the terminal by design, exactly as decoration layers and
   image tracks are.
2. **Skits are data.** The authored file format extends compatibly; every
   existing skit keeps playing unchanged. No bespoke runtime: playback,
   gating and seen-tracking stay in `SkitManager`.
3. **Presentation never gates progression.** A skit with no voice files, no
   bust art, or a failed asset plays completely as text and records
   `skit_seen` exactly as a fully voiced one does. A voice line that cannot
   play logs a note and the beat continues silent.
4. **Assets are the developer's; the engine advises.** The engine publishes
   preferred bust shapes, animation-frame conventions and voice formats,
   loads what exists, fails an asset only when missing, wrong type or
   corrupt, and renders best-effort otherwise. Authored metadata never
   restates what a file knows about itself.
5. **Preview is the runtime.** The editor previews skits through the
   engine's own playback path, as summons already do.

## Current runtime scope

- **The skit system is real and shipping.** `Field\SkitManager` loads
  `assets/Data/Skits/*.php` (id, title, optional map gate, trigger
  conditions, beats of speaker + text, optional speed), announces
  availability by notification, plays beats through the standard dialogue
  box (`show_text`), and records `skit_seen:<id>`. The engine roadmap
  already defers "a dedicated compact skit overlay."
- **Beats accept optional emotion and voice.** Phase 0 resolves these softly
  and plays voice through the existing dialogue box on either presentation.
- **The bust art already exists.** Each Last Legend cast member ships eight
  emotional dialogue portraits (Angry, Concerned, Determined, Happy,
  Neutral, Sad, Surprised, Thinking), some with alternate sets (e.g. a
  vampiric variant). Today only battle Results binds any of them; field
  dialogue shows no portraits on either presentation.
- **A dialogue presentation catalog is beginning.** The in-flight
  `DialoguePresentationCatalog` (`Data/Presentation/dialogue.php`) is the
  natural home for per-actor bust bindings shared by dialogue and skits.
- **Audio owns one speech line independently of SFX.** Advance interrupts
  that line; optional BGM ducking uses seek-safe backend support.
- **Shared Auto playback is available, with skits its first adopter.** Log,
  Skip, the graphical stage and Editor emotion/voice authoring remain planned.

## The model

### Authored schema (backward compatible)

Beats gain optional fields; everything existing remains valid:

```php
'beats' => [
  ['actor' => 'Liora', 'emotion' => 'Concerned', 'voice' => 'liora-01',
   'text' => "You keep hiding how bad it was on the road.\nYou don't have to carry it all alone, Kaelion."],
  ['actor' => 'Kaelion', 'emotion' => 'Determined', 'voice' => 'kaelion-01',
   'text' => '…'],
  ['speaker' => 'Innkeeper', 'text' => 'Breakfast is on the house.'],
],
```

- `actor` identifies who speaks by the actor's stable id, selected from the
  project's actor registry (the actor definitions are the one source of
  truth). The displayed name comes from the actor, so renames, identity
  reveals, aliases and translations never break busts, voice folders or
  animation bindings. The editor offers an actor picker; actor identity is
  never typed.
- `speaker` remains for speakers who are not actors (a narrator, a
  shopkeeper, "???"): plain display text, no bust, no emotion. A beat names
  either an actor or a speaker.
- Existing beats that name an actor through `speaker` migrate to `actor`.
  During the migration window a `speaker` value equal to an actor id
  resolves to that actor with a validator notice; this fallback is
  temporary.
- `emotion` names a portrait in the actor's dialogue set, resolved through
  the dialogue presentation catalog by actor id; unknown or absent falls
  back to Neutral. Invalid or unknown authored values log a note; omission
  is a normal legacy default and does not warn.
- `voice` names an audio asset under the game's voice tree; the engine
  uses `assets/Audio/Voice/Skits/<skit-id>/<name>.mp3` in Phase 0. The basename
  may include its `.mp3` extension. Absent means unvoiced.
- The location plate on the stage comes from the current map's data
  (name/region), never authored into the skit: metadata never restates
  what the game already knows.

### Voice playback

`AudioManager` gains a speech channel: one line at a time, played when its
beat is shown, stopped when the beat is advanced past, queryable while
playing. Playing a new line stops the previous one. Optional music ducking
lowers BGM while a line plays and restores it after. Voice plays in every
presentation, terminal included - this is what makes the feature real
before any graphical work lands.

### The graphical skit stage

A dedicated full-screen presentation, canvas-emitted per frame exactly as
graphical battle and the shared menus are (no renderer or protocol
changes): skit title header, location plate, speaker nameplate, dialogue
panel, and one bust per conversing character with the active speaker
emphasized. The stage is deliberately not tied to the shared menu theme:
it carries its own presentation data, and its contract leaves room to
grow from the Tales-like treatment toward fuller animation. PHP always
drives the content; richness is the presentation's freedom. Bust art is
bound per actor and emotion through the dialogue presentation catalog, so
ordinary field dialogue can adopt the same busts without a second
registration.

### Lip and eye animation

Ambient, engine-driven, never authored per skit: nobody keyframes a blink.

The engine is technique-flexible: a game binds each bust's animation as
whole-bust frame variants, as a fixed bust with small mouth/eye overlay
patches positioned per portrait, or as sprite-sheet rows - the animator
consumes states, not files. Last Legend uses the fixed bust with overlay
patches: the base portrait is never re-rendered, only the small regions
animate.

- **Eyes**: a randomized blink loop on every visible bust.
- **Mouth**: the active speaker's mouth cycles while their voice line is
  playing (`isRunning`) and rests when it ends; unvoiced beats flap for
  the text-typing duration instead. This is the classic Tales treatment -
  presence, not phoneme accuracy.
- **Reduced motion**: static busts; voice, text, cues and seen-tracking
  are unchanged.

This ambient animator is deliberately not the effect-timeline runtime:
timelines are authored keyframes, blinking is idle behavior. Authored skit
flourishes (a shake on a punchline, a flash) can adopt effect timelines
later without changing this plan.

### Playback comfort

Auto, Log and Skip are a dialogue-wide facility from the start, designed
against the dialogue flow itself; skits are merely the first adopter.

- **Auto**: advances a beat when its voice line has ended and its text has
  finished typing (a text-length timer when unvoiced).
- **Log**: a scrollable transcript of the lines shown so far.
- **Skip**: ends the exchange after confirmation. For skits this records
  the skit seen - they are optional conversations, so skipping is always
  safe. Surfaces with stricter skip semantics (cinematics) keep their own
  policies.

All three work identically on both presentations.

## Phases

### Phase 0 - Voice-acted skits everywhere

1. Extend the skit schema with `emotion` and `voice`; validate softly
   (unknown emotion or missing voice logs, never fails a skit).
2. Add the speech channel to `AudioManager`: play/stop/isPlaying, advance
   interrupts, optional ducking.
3. Play voice lines from `SkitManager::play()` in the existing terminal
   flow. Voice-acted skits ship here, before any graphical work.
4. Auto mode driven by voice-line end / typing completion.

#### Runtime contract

`SkitBeatPresentation` resolves emotion names against
`DialoguePresentationCatalog::actors[<actor id>]['emotions']` keys. Neutral
is always available as a fallback; Phase 0 does not draw emotional portraits.
Voice references must be basenames within their own skit directory, including
after resolving symlinks. Invalid references, missing files, unsupported
playback and failed voice processes log diagnostics and leave text playable.

Auto is a player setting, not per-conversation state: once the player
turns it on it stays on across beats, skits and ordinary dialogue until the
player turns it off, and it is exposed as an option in the Config menu. `show_text(..., playback: $playback)` forwards the
same optional state through Console and ModalManager to TextBoxModal; other
dialogue callers keep their existing manual behaviour unless they adopt it.
Authored help remains visible alongside the shared controls. The semantic
`dialogue_auto` action defaults to Space on the keyboard while a dialogue box
is open (dialogue advances on confirm, so Space is free there), and to X on
Xbox and Square on PlayStation controllers once controller support lands.
Function keys are never Auto defaults. The Space press that opens a
conversation must not also toggle Auto in the box it opens. If Space
conflicts in some context, X is the keyboard fallback (a bare Shift press
is not detectable in a terminal). The action appears in Controls and
honours explicit remapping or unbinding. Confirm still
finishes typing before a later press advances; cancelling a beat stops its
owned speech. Modal and skit cleanup also stop that speech on interruption.

Auto waits for both voice completion and completed typing on a single-page
voiced beat. Unvoiced, muted or failed-voice pages use a reading timer that
starts after typing finishes: at least one second, or text length divided by
15 characters per second, whichever is longer. These are named defaults in
`DialoguePlayback`, not project configuration keys. Wrapped beats give each
page this reading interval; intermediate pages may advance while voice plays,
but the final page also waits for voice completion. Enabling Auto starts a
fresh minimum one-second hold, even if the voice has already ended.

Missing optional presentation does not prevent completed skits being marked
seen. An interrupted or stopped game no longer marks an unfinished skit seen.
This is not the planned Skip feature, which will explicitly complete an
optional skit after confirmation.

Voice has its own player setting, independent of the sound-effects
setting: muting sound effects never mutes voice acting. `audio.voice_music_duck` optionally
sets the BGM multiplier for skit speech, defaulting to 1.0 (no ducking).
Backend limitations and ownership rules are described in [audio.md](audio.md#speech).
Editor beat emotion/voice pickers, safe authoring round trips and runtime
preview remain Phase 4 work; runtime support is not full authoring completion.

### Phase 1 - The graphical stage

1. Bind per-actor emotional busts in the dialogue presentation catalog.
2. Implement the skit stage as a canvas presentation (title, location,
   nameplate, panel, static busts, active-speaker emphasis), with its own
   presentation data rather than the shared menu theme.
3. Terminal keeps the standard dialogue flow, gaining only the skit title
   announcement; the deferred compact overlay remains a separate decision.

### Phase 2 - Life

1. The ambient animator: blink loop, voice-driven lip flap, rest states.
2. Reduced-motion behavior.

### Phase 3 - Comfort and coverage

1. Log and Skip on both presentations.
2. Optionally extend voice and busts to ordinary field dialogue and
   cinematics through the same catalog and speech channel.

### Phase 4 - Authoring in the editor

1. Skits join the schema-driven record path: beat list editing, an emotion
   picker populated from the portrait files on disk, a voice asset picker,
   and condition/gate fields.
2. Preview through the engine's own playback (voice included), as the
   summon preview already works.

## Sequencing (decided 2026-09-22)

Phase 0 starts as early as possible: it is small and independent, and may
run alongside the already-decided Phase 0 pair (effect animation and
layered tilemaps). Phases 1 through 4 follow the current work queue, with
the graphical stage landing after the shared menu presentation work is
complete.

## Decisions already made (do not relitigate)

- Skits are voice-acted with lip and eye animation in the Tales tradition;
  the graphical stage presents title, location, nameplate, dialogue panel
  and emotional busts.
- Voice is presentation-independent and plays in the terminal.
- Bust art, emotions-as-art and lip/eye animation are graphical fidelity,
  invisible in the terminal by design.
- Missing or failed voice/bust assets degrade to text; presentation never
  gates progression or seen-tracking.
- Ambient animation is engine-driven, never authored per skit; reduced
  motion shows static busts.
- The engine serves the animation technique flexibly (whole-bust frames,
  fixed bust with overlay patches, or sprite-sheet rows); Last Legend
  uses a fixed bust with mouth/eye overlay patches.
- Lip sync is loop-while-the-line-plays; no amplitude analysis.
- The voice layout convention is
  `assets/Audio/Voice/Skits/<skit-id>/<name>`; voice lines are authored
  as mp3, the one compressed format every supported playback path
  decodes.
- Auto, Log and Skip are a dialogue-wide facility from the start; skits
  adopt it first.
- The skit stage is not tied to the shared menu theme: it owns its
  presentation data, PHP drives the content, and the presentation is free
  to grow from Tales-like toward fuller animation.
- Beats identify speakers by stable actor id selected from the actor
  registry, the one source of truth; displayed names come from the actor.
  Plain `speaker` text is only for speakers who are not actors, and carries
  no bust. The editor never asks an author to type an actor's identity.
- The Auto key is Space on the keyboard in dialogue, X (Xbox) or Square
  (PlayStation) on controllers; never a function key. X is the keyboard
  fallback wherever Space conflicts.
- Auto persists until the player turns it off, and is a Config menu option.
- Voice has its own setting, independent of sound effects.
- Audio playback moves to a native audio component, with the command-line
  players as fallback; see [native-audio.md](native-audio.md).
