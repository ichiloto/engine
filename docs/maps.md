# Maps, regions, and the map screen

A map lives in its own directory under `assets/Maps`, with its three files
named after it:

```
assets/Maps/happyville/town-center/town-center.data.php
                                   town-center.map.php
                                   town-center.event.php
```

The directory path is the map's **id**: `happyville/town-center`. That is what
`destinationMap` names, what save files record, and what the map screen uses.

## Regions

`.data.php` gives a map its `name` and its `region`:

```php
return [
  'name' => 'Town Center',
  'region' => 'Happyville',
  // ...
];
```

Maps sharing a region are the same place as far as the player is concerned,
and the map screen draws them together.

## The map screen

The `map` action (M by default) opens the region the player is standing in,
drawn the way the region actually is:

```
  [    Home     ]──┐                                    N
                   │                                  W ─ E
                   └──[ Town Center ]──┐                 S
                             │         │
                      [    Shop     ]  └──[  Inn Front  ]
```

Nothing about this is authored twice. `Field\RegionMap` reads every map's
`name`, `region`, and `TransferPlayerTrigger` destinations, so a door drawn on
a map appears on the region map by existing.

### Where a place is drawn

**Where a door sits on its map is where the place behind it lies.** The house's
door is up on the north-west side of the square because the house is north-west
of it; the shop's is straight down at the south. The engine reads the door's
position out of the map's event layer and places the destination that way, so
a region that was drawn sensibly maps sensibly, for free.

A door in the middle of a map says nothing about direction, and its
destination is simply set down beside what it connects to.

The region is laid out around its **hub**, the place with the most doors,
rather than around the player, so it does not rearrange itself depending on
where the player is standing.

When the doors get it wrong, a map can say where it belongs:

```php
return [
  'name' => 'Sealed Vault',
  'region' => 'Crypt',
  // Grid position on the region map. Beats anything the doors imply.
  'station' => ['x' => 4, 'y' => 2],
];
```

What the player has seen governs what it says:

- places the party has been are named,
- places one door from somewhere they have been show as `?????`,
- anything further is not drawn at all.

Visits are recorded in the world state (`GameState::markMapVisited()`, called
when a map loads) and ride the save file, so a map fills in as the game is
played. Doors leading out of the region are named under the map as exits.

A region can hold a map no door reaches, one entered only by a cutscene, say.
It is still part of the region and still drawn.

## Random encounters

A map turns on random encounters by naming the troops that can appear and how
often:

```php
'encounters' => [
  // Troop name (from assets/Data/troops.php) => weight.
  'troops' => [
    'Bat x 2' => 5,
    'Rat + Bat' => 4,
    'Great Wolf' => 2,
  ],
  // The average number of steps between fights.
  'rate' => 10,
  // Optional: 'any' counts every tile, the default counts encounter tiles.
  'tiles' => 'encounter',
],
```

Weights are relative, so a `Bat x 2` above is met roughly five times as often
as `Great Wolf` twice. A map with no `encounters` key never rolls one.

The engine warns when a map declares `encounters` in a shape it cannot read,
because a silent no-op is indistinguishable from a design decision.
