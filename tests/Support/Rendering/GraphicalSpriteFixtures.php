<?php

namespace Tests\Support\Rendering;

use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;

/** @return array{sheet: string, index: int, layer: int} An RPG Maker character sheet definition. */
function characterSheetData(string $sheet = 'Graphics/Characters/Heroes.png', int $index = 0, int $layer = 100): array
{
  return ['sheet' => $sheet, 'index' => $index, 'layer' => $layer];
}

/**
 * Writes a valid PNG in RPG Maker's character sheet shape: 12 x 8 frames for
 * a standard sheet, 3 x 4 for a `$` single-character sheet.
 */
function writeCharacterSheetPng(string $path, int $frameWidth = 4, int $frameHeight = 4, ?bool $single = null): void
{
  $single ??= str_starts_with(basename($path), CharacterSheet::SINGLE_CHARACTER_PREFIX);
  $columns = CharacterSheet::FRAMES_PER_DIRECTION * ($single ? 1 : CharacterSheet::SHEET_CHARACTER_COLUMNS);
  $rows = CharacterSheet::DIRECTION_ROWS * ($single ? 1 : CharacterSheet::SHEET_CHARACTER_ROWS);
  writeTestPng($path, $columns * $frameWidth, $rows * $frameHeight);
}

/** Writes a minimal valid RGBA PNG of the given size. */
function writeTestPng(string $path, int $width, int $height): void
{
  if (!is_dir(dirname($path))) {
    mkdir(dirname($path), 0777, true);
  }
  $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data
    . pack('N', crc32($type . $data));
  file_put_contents($path, "\x89PNG\r\n\x1a\n"
    . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
    . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\x70\x90\xB0\xFF", $width), $height)))
    . $chunk('IEND', ''));
}
