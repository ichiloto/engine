<?php

use Ichiloto\Engine\Util\Debug;

it('writes errors to the error log even when debug mode is off', function () {
  $logDirectory = sys_get_temp_dir() . '/ichiloto-debug-test-' . uniqid('', true);

  Debug::configure([
    'log_level' => Debug::INFO,
    'log_directory' => $logDirectory,
  ]);

  Debug::error('The realm has fallen.');

  $errorLogPath = $logDirectory . '/error.log';

  expect(is_file($errorLogPath))->toBeTrue()
    ->and(file_get_contents($errorLogPath))->toContain('The realm has fallen.');
});
