# Battle events

A troop can author event pages that run during its battles, in the manner of
RPG Maker's troop events. Pages run the shared `EventInterpreter` over the
paused battle, so dialogue, waits, sounds, switches, variables and story
events behave as they do in field scripts.

## Page data

Pages live under the troop's `events` key in `Data/troops.php`:

```php
[
  'id' => 'troop.rival-trial',
  'name' => 'Rival Trial',
  'enemies' => [
    ['enemy' => 'Rival', 'position' => [10, 2], 'knockoutFloorPercent' => 25],
  ],
  'events' => [
    [
      'name' => 'Concession',
      'span' => 'battle',
      'conditions' => ['enemy' => 0, 'enemyHpPercent' => 25],
      'commands' => [
        ['type' => 'text', 'name' => 'Rival', 'text' => 'Enough.'],
        ['type' => 'concede', 'enemy' => 0],
      ],
    ],
  ],
],
```

- `name` (optional): a label for authors and diagnostics.
- `span`: `battle` (at most once per battle, the default), `turn` (at most
  once per turn) or `moment` (at every check where the conditions hold).
- `conditions`: every given condition must hold, and a page needs at least one.
  - `turn` (1 or more), optional `turnEvery` (1 or more): turn `turn`, then
    every `turnEvery` turns after it. Turn 1 is the opening turn.
  - `enemy` (the member's index in `enemies`) with `enemyHpPercent` (0..100):
    that enemy is still fighting with HP at or below that share of its maximum.
  - `actor` (an actor ID) with `actorHpPercent` (0..100): that party member is
    in the battle with HP at or below that share of its maximum.
  - `world`: world conditions, the shapes `WorldConditionEvaluator` reads
    (switches, variables, story events, items, quests).
- `commands`: the commands to run (below). A page may hold none yet; it then runs nothing.

`BattleEventPage::readPages()` validates pages when the troop loads and
refuses malformed ones with the troop, page and command path in the message.
Members are named by index, so a troop with pages refuses a member whose
enemy does not exist instead of dropping it.

## When pages are checked

The battle engine checks the troop's pages at the start of each turn and at
the end of each battler's turn, in both the traditional and the active-time
engines. Each check is evaluated once. Due pages are queued in page order;
before a queued page starts, its span and conditions are checked again, since
an earlier page may have changed them. While pages run, `BattleScene` keeps
its running state and engine context, as pause does, and the battle resumes
where it stopped.

## Commands

Battle pages may use `text`, `choice`, `wait`, `set_switch`, `set_variable`,
`record_event`, `give_item`, `give_gold`, `play_sound`, `play_music`, `branch`,
`sequence` and `common_event`, and registered commands that declare the
`battle` scope (`ScriptCommandDefinition::$scopes`; a registered command runs
in field scripts only unless it declares otherwise). Field-only commands are
refused when the page is validated and again by the interpreter when a Common
Event reaches one. A failing page is logged and abandoned; the battle goes on.

The Engine registers two battle commands:

- `concede` (`enemy`: a troop member): the enemy stops fighting and counts as
  defeated for victory and rewards. It is not knocked out: it stays on screen
  in its `concede` pose when its artwork registers one, and otherwise holds
  its current artwork at `ui.battle.defeat.concededOpacity` (default 0.6). The
  Terminal draws it dim. There is no knockout fade.
- `end_battle` (`outcome`: `victory`, `escape` or `defeat`): the battle ends
  with that outcome once the page finishes; later queued pages do not run.
  Victory counts every enemy still fighting as conceded and awards the
  ordinary victory rewards. Defeat follows the battle's defeat policy.

## Outcomes

Every way a battle ends goes through `BattleConclusion`: the last enemy
falling or conceding, the party falling, an escape, and `end_battle`. A field
script's `start_battle` therefore reads the same `victory`, `escape` or
`defeat` result and the same rewards, quest defeat records and knowledge
records however the battle ended.

## Knockout floor

A troop member's `knockoutFloorPercent` (0..99) is the share of its maximum HP
that battle damage, including damaging states, cannot take it below. It lets a
page such as a concession run before an ordinary knockout. A conceded enemy
has no floor. `BattleSimulator` runs no battle events, so it counts a battler
held at its floor as finished.

## Participation

`CharacterInterface::$isFighting` says whether a battler still takes part:
it acts, can be targeted and keeps its side from defeat. Turn order, the
active-time gauges, targeting, counters and victory and defeat checks read it.
`$isKnockedOut` remains the HP-0 state that revival and knockout presentation
read.
