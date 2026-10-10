<?php

use Ichiloto\Engine\Util\Debug;

beforeEach(function () {
  $this->debug = new ReflectionClass(Debug::class)->getStaticProperties();
});

afterEach(function () {
  foreach ($this->debug as $key => $value) {
    new ReflectionProperty(Debug::class, $key)->setValue(null, $value);
  }
});

it('writes errors to the error log even when debug mode is off', function () {
  $logDirectory = createTestDirectory('ichiloto-debug-test-');

  Debug::configure([
    'log_level' => Debug::INFO,
    'log_directory' => $logDirectory,
  ]);

  Debug::error('The realm has fallen.');

  $errorLogPath = $logDirectory . '/error.log';

  expect(is_file($errorLogPath))->toBeTrue()
    ->and(file_get_contents($errorLogPath))->toContain('The realm has fallen.');
});
