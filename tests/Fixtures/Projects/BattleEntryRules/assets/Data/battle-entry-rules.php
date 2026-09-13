<?php

return [
  'rules' => [
    [
      'id' => 'fixture.ordinary-active',
      'priority' => 10,
      'classification' => 'ordinary',
      'actors' => [
        ['actor' => 'actor.alpha', 'presence' => 'active'],
      ],
      'effects' => [
        ['type' => 'stat_stage', 'actor' => 'actor.alpha', 'stat' => 'speed', 'delta' => 1],
      ],
    ],
    [
      'id' => 'fixture.boss-reserve',
      'priority' => 20,
      'classification' => 'boss',
      'actors' => [
        ['actor' => 'actor.delta', 'presence' => 'reserve'],
      ],
      'effects' => [
        ['type' => 'stat_stage', 'actor' => 'actor.delta', 'stat' => 'grace', 'delta' => -1],
      ],
    ],
  ],
];
