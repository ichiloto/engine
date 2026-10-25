# Skill effects and battle states

A skill record (`assets/Data/Skills/NNNN-name.php`) lists its effects under
`effects`, each a `type` with that type's values. `SkillRecord` reads and
writes them; `SkillRecord::getEffectTypes()` lists every type, and the Editor
offers the same list. States are authored in `assets/Data/states.php` and read
by `StateRegistry`.

## Effect types

| Type | Values | What it does |
| --- | --- | --- |
| `hp_damage`, `hp_drain`, `hp_recover`, `mp_damage`, `mp_drain`, `mp_recover` | `formula`, `element`, `variance`, `isCriticalHit`; `hp_damage` also `resolutionKind` | Changes HP or MP by the formula's amount |
| `add_state` | `stateId`, `chancePercent` (default 100) | Afflicts each target with the state; the target's state resistance scales the chance |
| `remove_state` | `stateIds`, `chancePercent` (default 100) | Rolls to cure each listed state the target has |
| `modify_stat_stage` | `stat`, `delta`, `affectsUser` | Raises or lowers a stat stage for the battle |
| `reveal` | `facts`, `chancePercent` (1 to 100, default 100) | Reveals facts about each foe for the rest of the battle |

Effects apply in order to each target. `add_state` skips a target an earlier
effect of the same skill knocked out, so a spell that damages and then adds a
state leaves only its survivors in it.

### Reveal

`facts` lists what a reveal uncovers (`RevealFact`):

| Fact | Shows |
| --- | --- |
| `hp` | Current and maximum HP |
| `mp` | Current and maximum MP |
| `stats` | Attack, Defence, Magic Attack, Magic Defence, Speed, Grace and Evasion |
| `affinities` | The element that strikes the foe hardest, or that no element beats ordinary damage |

One roll per target decides whether every listed fact is revealed or none is.
No resistance scales it, so no target is immune. A failed roll reveals nothing;
the caster still spends the skill's MP and turn. What is revealed stays known
for the rest of the battle, and a later failed roll takes nothing back. The
party's own HP, MP and stats are always shown, so only foes
(`RevealableBattler`, which `Enemy` implements) keep revealed facts.

The strongest element is the project element whose damage multiplier against
the foe is highest and above 1.0 (`ElementalInsight`); elements that tie are
all named.

What the player sees, in the Terminal and in GPUI alike (`BattlerInsight`):

- **On the cast:** each revealed foe shows its HP over itself when HP was
  revealed; a failed reveal shows MISS. A reveal on one foe also fills the
  Info window with everything known about it:
  `Brush Wolf  HP 34/80  MP 0/10  ATK 12  DEF 8  MAT 5  MDF 6  SPD 10  GRC 4  EVA 3  Best element: Fire`.
- **While targeting:** a foe whose HP is known shows it beneath itself, and
  Info names everything known about it ahead of the option's own text. Values
  are the foe's as it is now, so HP, MP and stat stages update as the battle
  goes on.

The wording of the best-element line is the project's: `battle.insight.best`
("Best element") and `battle.insight.none` ("No element beats ordinary
damage") in the Editor's Terms.

**The foe's record.** Each revealed fact is recorded for the foe's knowledge
subject as the Engine-owned observation `reveal.<fact>`
(`KnowledgeProgressService::recordEnemyReveal()`). It is accepted for any
subject, beside the subject's own authored observations, and it does not
raise the subject's depth, so a reveal never opens a record's field research.
The Records menu then shows an Analysis section (`records.analysis`) with the
facts recorded so far, read from the enemy as it is authored now: its HP and
MP, its stats, and its best element. Standalone battles and previews record
nothing.

## State fields

| Field | Meaning |
| --- | --- |
| `id` | Stable identity; the only required field |
| `name`, `icon`, `label`, `description` | How menus and battle badges show it |
| `durationTurns` | The bearer's turns before it wears off; absent lasts until cured |
| `tickFormula` | HP change each turn, with `$target` bound to the bearer |
| `preventsAction` | The bearer loses its turn |
| `confusionChancePercent` | 0 to 100: the chance each turn that the bearer loses track of friend and foe |
| `persistsAfterBattle`, `fieldTickSteps`, `fieldTickKnocksOut`, `curedByRest` | Life outside battle |
| `disposition` | `harmful` (default), `beneficial` or `neutral` |
| `counterAttack`, `damageTakenRate`, `coversAllies` | Counters, protection and Cover |

### Confusion

A state with a `confusionChancePercent` rolls it as each of the bearer's turns
begins (`BattleConfusion`). On a hit the bearer makes its ordinary attack on a
random battler still fighting on either side, never itself, in place of the
action it chose. With several such states the highest chance applies. The
live battle and the battle simulator both ask `BattleConfusion`, so balance
runs measure the turn the game plays, and a battler with no such state rolls
nothing extra.

Severities of one ailment are separate states: a heavy one with a chance of
100 confuses its bearer every turn, and a lighter one with a lower chance and
fewer turns leaves the bearer acting as it chose some of the time.
