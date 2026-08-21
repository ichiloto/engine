<?php

return [
  ['type' => 'transition', 'style' => 'fade', 'direction' => 'in', 'seconds' => 0.1],
  [
    'type' => 'cinematic_music',
    'track' => 'Fixtures/sky-caravan-theme',
    'loop' => false,
    'fadeIn' => 0.1,
    'fadeOut' => 0.1,
    'completionBehavior' => 'restore_previous',
  ],
  ['type' => 'camera', 'operation' => 'detach'],
  [
    'type' => 'parallel',
    'lanes' => [
      [
        'id' => 'red-kite-route',
        'commands' => [[
          'type' => 'move_route',
          'subject' => 'staged_actor',
          'actorId' => 'kite-red',
          'secondsPerStep' => 0.1,
          'steps' => [['direction' => 'right', 'count' => 3]],
        ]],
      ],
      [
        'id' => 'blue-kite-route',
        'commands' => [[
          'type' => 'move_route',
          'subject' => 'staged_actor',
          'actorId' => 'kite-blue',
          'secondsPerStep' => 0.1,
          'steps' => [['direction' => 'right', 'count' => 3]],
        ]],
      ],
      [
        'id' => 'gold-kite-route',
        'commands' => [[
          'type' => 'move_route',
          'subject' => 'staged_actor',
          'actorId' => 'kite-gold',
          'secondsPerStep' => 0.1,
          'steps' => [['direction' => 'right', 'count' => 3]],
        ]],
      ],
      [
        'id' => 'camera-route',
        'commands' => [[
          'type' => 'camera',
          'operation' => 'route',
          'points' => [
            ['kind' => 'position', 'x' => 12, 'y' => 5, 'seconds' => 0.15],
            ['kind' => 'position', 'x' => 20, 'y' => 5, 'seconds' => 0.15],
          ],
        ]],
      ],
      [
        'id' => 'narration',
        'commands' => [[
          'type' => 'narration',
          'title' => 'Before Sunrise',
          'text' => 'Three signal kites crossed the quiet plain in formation.',
          'seconds' => 0.3,
        ]],
      ],
      [
        'id' => 'signal-animation',
        'commands' => [[
          'type' => 'field_animation',
          'animation' => 'Signal Spark',
          'target' => ['kind' => 'position', 'x' => 8, 'y' => 4],
          'secondsPerFrame' => 0.1,
        ]],
      ],
    ],
  ],
  ['type' => 'checkpoint', 'name' => 'formation-crossed'],
  ['type' => 'title_card', 'title' => 'THE SKY CARAVAN', 'text' => 'Dawn Route', 'seconds' => 0.1],
  ['type' => 'transfer', 'map' => 'cinematic/skyfield-dawn', 'x' => 4, 'y' => 4, 'sprite' => ['@']],
  ['type' => 'checkpoint', 'name' => 'dawn-arrival'],
];
