<?php

return [
  'id' => 'sky-caravan',
  'name' => 'The Sky Caravan',
  'description' => 'An original Engine acceptance fixture about three signal kites crossing a dawn plain.',
  'version' => 1,
  'authoring' => [
    'purpose' => 'cinematic-runtime-acceptance',
    'license' => 'test-fixture',
  ],
  'startMap' => 'cinematic/skyfield-night',
  'presentation' => ['initial' => 'hidden', 'reducedMotion' => 'final-state'],
  'cast' => [
    ['kind' => 'staged_actor', 'id' => 'kite-red', 'sprite' => ['/R\\'], 'x' => 2, 'y' => 2],
    ['kind' => 'staged_actor', 'id' => 'kite-blue', 'sprite' => ['/B\\'], 'x' => 2, 'y' => 4],
    ['kind' => 'staged_actor', 'id' => 'kite-gold', 'sprite' => ['/G\\'], 'x' => 2, 'y' => 6],
  ],
  'skip' => ['policy' => 'authored'],
  'checkpoints' => ['formation-crossed', 'dawn-arrival'],
  'finalizer' => [
    ['type' => 'move_player', 'x' => 4, 'y' => 4],
    ['type' => 'transfer', 'map' => 'cinematic/skyfield-dawn', 'x' => 4, 'y' => 4, 'sprite' => ['@']],
    ['type' => 'camera', 'operation' => 'attach'],
    ['type' => 'remove_actor', 'actorId' => 'kite-red'],
    ['type' => 'remove_actor', 'actorId' => 'kite-blue'],
    ['type' => 'remove_actor', 'actorId' => 'kite-gold'],
    ['type' => 'clear_presentation'],
    ['type' => 'set_switch', 'name' => 'sky_caravan_arrived', 'value' => true],
    ['type' => 'record_event', 'name' => 'sky_caravan_finalized'],
  ],
  'testFixture' => ['mapWidth' => 100, 'mapHeight' => 60],
];
