<?php

/** Manual, silent Native Terminal check. No Game, audio, settings or save initialization. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\UI\Text\TerminalMenuRow;

Console::syncDimensions(70, 16);
Console::setLayerTracking(true);
Console::clear();
Console::beginFrame();
Console::write('Native Terminal glyph alignment - silent, no game or saves', 0, 0);
Console::write('Names need a gap after icons; prices and | should line up.', 0, 1);
foreach (['Ordinary fixture', '⚔️ Sword fixture', '🗡️ Dagger fixture', '⚚ Staff fixture',
  'o~ Flail fixture', '😀 Emoji fixture', '界 Wide text fixture', "e\u{0301} Combining fixture"] as $index => $label) {
  $row = TerminalMenuRow::compose($label, '101 G', 54, prefix: ' > ', valueWidth: 10) . '|';
  if ($index === 1 || $index === 2) { $row = "\e[35m" . $row . "\e[0m"; }
  Console::write($row, 0, 3 + $index);
}
Console::write('Close this test window when finished.', 0, 13);
Console::endFrame();
echo "\e[16;1H\n";
