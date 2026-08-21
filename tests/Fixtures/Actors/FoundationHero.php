<?php

return [
  'data' => [
    'id' => 'actor.hero',
    'name' => 'Hero',
    'currentExp' => 0,
    'actorNaturalAdjustments' => [
      'maxHp' => 5,
    ],
    'defaultNaturalVariantId' => 'standard',
    'naturalVariants' => [
      'standard' => [
        'adjustments' => [],
      ],
      'alternate' => [
        'adjustments' => [
          'attack' => 3,
        ],
      ],
    ],
    'stats' => [
      'currentHp' => 80,
      'currentMp' => 10,
      'currentAp' => 3,
      'totalHp' => 100,
      'totalMp' => 10,
      'totalAp' => 3,
      'attack' => 8,
      'defence' => 7,
      'magicAttack' => 6,
      'magicDefence' => 5,
      'speed' => 4,
      'grace' => 3,
      'evasion' => 2,
    ],
  ],
];
