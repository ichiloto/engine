<?php

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Text\TerminalMenuRow;

/** A terminal's cursor advance is independent of the Engine's reserved cells. */
final class GlyphAdvanceTerminal
{
  public array $cells = [];
  private int $column = 0;
  private int $row = 0;

  public function __construct(private readonly int $emojiAdvance) {}

  public function paint(string $payload): void
  {
    preg_match_all('/\x1b\[[0-9;?]*[ -\/]*[@-~]|\X/u', $payload, $matches);
    foreach ($matches[0] as $token) {
      if (preg_match('/\A\x1b\[(\d+);(\d+)H\z/', $token, $address)) {
        $this->row = (int) $address[1] - 1;
        $this->column = (int) $address[2] - 1;
      } elseif (str_starts_with($token, "\e[")) {
        if (!str_ends_with($token, 'm')) { throw new RuntimeException('Unexpected terminal control in fixture.'); }
      } else {
        // These two hosts render the same authored emoji differently. No Engine width helper
        // decides their cursor advance, otherwise this would repeat the production assumption.
        $width = in_array($token, ['⚔️', '🗡️', '😀', '🚶'], true) ? $this->emojiAdvance
          : (preg_match('/[\x{4E00}-\x{9FFF}]/u', $token) ? 2 : 1);
        $this->cells[$this->row][$this->column] = $token;
        for ($part = 1; $part < $width; $part++) { $this->cells[$this->row][$this->column + $part] = ''; }
        $this->column += $width;
      }
    }
  }

  public function getCell(int $column, int $row): string { return $this->cells[$row][$column] ?? ' '; }
}

beforeEach(function () {
  $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'usingAlternateScreen' => false, 'output' => null, 'terminalOutputStream' => null,
    'terminalOutputEnabled' => true, 'terminalViewport' => null, 'terminalOffsetX' => 0,
    'terminalOffsetY' => 0] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  Console::syncDimensions(60, 4);
  Console::setLayerTracking(true);
  ob_start();
  Console::clear();
  ob_clean();
});

afterEach(function () {
  ob_end_clean();
  foreach ($this->consoleState as $name => $value) { new ReflectionProperty(Console::class, $name)->setValue(null, $value); }
});

it('paints icon labels and prices at reserved cells even when native emoji advance differs', function (int $advance, string $icon) {
  Console::syncTerminalViewport(80, 8, repaint: false);
  $terminal = new GlyphAdvanceTerminal($advance);
  foreach ([false, true, false] as $selected) {
    $row = TerminalMenuRow::compose($icon . ' Fixture', '101 G', 50, prefix: ' > ', valueWidth: 10);
    if ($selected) { $row = "\e[35m" . $row . "\e[0m"; }
    Console::beginFrame();
    Console::write($row, 0, 1);
    Console::endFrame();
    $terminal->paint(ob_get_contents());
    ob_clean();
    expect($terminal->getCell(10 + 3, 2 + 1))->toBe($icon)
      ->and($terminal->getCell(10 + 5, 2 + 1))->toBe(' ')
      ->and($terminal->getCell(10 + 6, 2 + 1))->toBe('F')
      ->and($terminal->getCell(10 + 49, 2 + 1))->toBe('G')
      ->and(TerminalText::displayWidth(Console::getBuffer()[1]))->toBe(60);
  }
})->with([1, 2])->with(['⚔️', '🗡️', '😀', '🚶']);

it('clears a reserved continuation when a host paints a wide glyph narrowly', function () {
  $terminal = new GlyphAdvanceTerminal(1);
  Console::write('0123456789', 0, 0);
  $terminal->paint(ob_get_contents());
  ob_clean();
  Console::write('⚔️Z', 2, 0);
  $terminal->paint(ob_get_contents());
  expect($terminal->getCell(2, 0))->toBe('⚔️')
    ->and($terminal->getCell(3, 0))->toBe(' ')
    ->and($terminal->getCell(4, 0))->toBe('Z');
});

it('keeps plain ASCII output compact and preserves composed snapshots', function () {
  Console::write('ASCII fixture', 3, 1);
  expect(ob_get_contents())->toBe("\e[2;4HASCII fixture")
    ->and(Console::snapshot()->rows[1])->toStartWith('   ASCII fixture');
});
