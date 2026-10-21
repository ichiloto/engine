# UI language and presentation

How Ichiloto screens should read and look to players, based on a study of real JRPG menus and growth
screens (21 October 2026). Use it for every player-facing word, menu and growth screen, in the Engine
defaults and in game themes. Engineering words must never reach the screen.

The study looked at actual screenshots, mainly from the [Game UI Database](https://www.gameuidatabase.com/),
for Final Fantasy X, XIII, VII Remake and XVI, Persona 5 Royal, Dragon Quest XI, Xenoblade Chronicles 1 and 3,
Tales of Arise, Trails of Cold Steel III and Octopath Traveler. Motion was checked in menu videos for FFX,
FFXIII, FFXVI and Persona 5 Royal. The Game UI Database's terms forbid using its content for AI asset
generation, so its screenshots are reference only and must never be fed to an image generator.

## Words

- **Name the thing, not the data structure.** Players see "Fire", not "node"; "Hearts 3/7", not
  "Region: Hearts". Only FFX shows the word "node" on screen, once, in a help line.
- **A growth screen has a proper name.** Sphere Grid, Crystarium, Character Builder, Skill Panel, Soul
  Tree. Ichiloto's equivalent screens take the game's chosen name ("Mastery" in Last Legend).
- **Points are a short label and a number.** "SP 120", "Current SP 6925", "Ability Points 6054",
  "JP Obtained 928". An unspent pool gets no special word ("banked", "stored", "reserve"). Show the
  amount.
- **Cost is a bare number** on or under each item, repeated in its detail: "Required SP: 4",
  "SP: 1000", "Cost to Learn Next Skill 30 JP".
- **Status is one word or icon.** Learned (with a check or star), Learning (with progress, e.g.
  "120/300"), a padlock for locked. Never a field labelled "State".
- **Locked items say the condition, not the mechanism.** "Unlocks at Lv 12", "Unlocked after learning
  Fire", "Not enough SP", "???" for unknown. Not "Needs:" or "Opens at".
- **Confirm with a verb and the cost.** "Spend 3 skill points to learn 'Flame Slash'?" Yes/No, or a
  hold prompt: "(Hold) Learn 300". Major spends use hold-to-confirm or Yes/No.
- **Feedback is one plain sentence.** "Magic increased by 3!", "Barret is now level 15.",
  "5 SP gained.", "Emblem obtained".
- **Help lines are short imperatives.** "Select a party member whose weapon you wish to upgrade."
- **Groups show their name and a Learned/Total count.** "Swords 1/10" (DQXI).
- **Avoid:** banked, study/studying/studies, region, node, state, subject, pool, owner, queue, payload,
  resolve, and any other word taken from code or data files.

- **Level is "Lv" in rows, lists and status lines** (the game's short level term). The full word is for
  sentences only ("Level 5!").

## Alignment

- Things line up. Values sit in real columns: each label in a fixed place, each figure right-aligned to the
  widest figure in its column, so a shorter number never shifts its row.
- A list row is one line. Names never wrap; size the column to the widest name and truncate with an ellipsis
  only when a name genuinely cannot fit.
- Graphical presentations keep at least the Terminal's tidiness: the same column model drives both, and no
  presentation joins several values into one string.

## Text and icons

- Growth screens are about 15 to 25 percent text (FFX, FFXVI, Xenoblade 3, Tales of Arise). The board
  is icons, each with its cost under it. Text-heavy menus (Trails, Octopath) still keep descriptions to
  one help line or a focus-only card.
- Descriptions show for the focused item only, in one help line or a detail card. Never list every
  description at once.
- Portrait, name, level and the points total sit together in one corner block.

## Colour

- A dark or neutral background.
- One accent colour per group (FFXVI by Eikon, FFX by character). One separate focus colour, usually
  teal or white.
- Learned is bright or saturated; available is dim with its cost; locked is grey with an icon.
- "Not affordable" is said in words ("Not enough SP"), never by colour alone.

## Motion

- The camera pans and zooms to the focused group; the cursor ring pulses.
- The points total counts down visibly when spent.
- One short accent on unlocking: gold line art lighting up (FFXVI), a glow ring and sparkle (DQXI), a
  flare and popup text (FFXIII).
- Menu transitions stay under about half a second (Persona 5 Royal).
- Reduced motion and the Terminal keep the same words, order and information, without the flare.

## Battle resources

A resource built up and spent in battle (RPG Maker's TP; Ichiloto's AP) is a two or three letter label
with a bar or pips beside HP and MP, and skill costs read "AP 20" where "MP Cost 2" would appear.
Precedents: Trails "CP", Octopath "BP" pips, Tales of Arise "AG", FFXIII "TP", Xenoblade's
"Talent Gauge", FF7 Remake's "Limit" and "ATB". Because FFX, FFIX and FFXVI use "AP" for growth points,
spell AP out once in its help line.

## Sources

- FFX: https://www.gameuidatabase.com/gameData.php?id=474
- FFXIII: https://www.gameuidatabase.com/gameData.php?id=796
- FFVII Remake: https://www.gameuidatabase.com/gameData.php?id=29
- FFXVI: https://www.gameuidatabase.com/gameData.php?id=1784
- Persona 5 Royal: https://www.gameuidatabase.com/gameData.php?id=618
- Dragon Quest XI: https://www.gameuidatabase.com/gameData.php?id=269
- Xenoblade Chronicles 3: https://www.gameuidatabase.com/gameData.php?id=1500
- Xenoblade Chronicles DE: https://www.gameuidatabase.com/gameData.php?id=576
- Tales of Arise: https://www.gameuidatabase.com/gameData.php?id=1195
- Trails of Cold Steel III: https://www.gameuidatabase.com/gameData.php?id=652
- Octopath Traveler: https://www.gameuidatabase.com/gameData.php?id=741
