<?php

use Ichiloto\Engine\Exceptions\CorruptSaveException;
use Ichiloto\Engine\Exceptions\UnsupportedSaveSchemaException;
use Ichiloto\Engine\Exceptions\UnsupportedContentVersionException;
use Ichiloto\Engine\Exceptions\WrongProjectSaveException;
use Ichiloto\Engine\Exceptions\ActiveEventSaveException;
use Ichiloto\Engine\IO\Saves\SaveFeedback;

it('describes save failures to the player without exception details', function (string $class, string $expected) {
  $error = new $class('Could not unserialize /private/player/save.iedata: PrivateEngineClass at line 87');
  expect(SaveFeedback::describeLoadFailure($error))->toBe($expected)
    ->not->toContain('/private/', 'unserialize', 'PrivateEngineClass', '87');
})->with([
  [CorruptSaveException::class, 'This save could not be read. Please choose another save.'],
  [WrongProjectSaveException::class, 'This save belongs to a different game.'],
  [UnsupportedSaveSchemaException::class, 'This save needs a newer version of the game.'],
  [UnsupportedContentVersionException::class, 'This save needs a newer version of the game.'],
  [RuntimeException::class, 'This save could not be loaded. Please choose another save.'],
]);

it('explains write failures without exposing storage or event internals', function () {
  expect(SaveFeedback::describeWriteFailure(new ActiveEventSaveException('event-private.php:87')))
    ->toBe('Please wait until the current event finishes before saving.')
    ->and(SaveFeedback::describeWriteFailure(new RuntimeException('/private/player/path permission error')))
    ->toBe('Your progress could not be saved. Please try again.');
});
