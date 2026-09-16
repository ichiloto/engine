<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGlyphEffects;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;
use RuntimeException;

/** A disposable overlay over the caller's real final battlefield, never a gameplay owner. */
final class GraphicalBattleResults
{
  private const BASE_LAYER = 10000;
  private array $images = [];
  private array $text = [];
  private float $opacity;

  private function __construct(private BattleResultsSkin $skin, private BattleResultsPlayback $playback)
  {
    $this->opacity = $playback->opacity();
  }

  /** Validate the catalog, but budget only concurrently displayed families.
   * @param list<CanvasImage> $battlefield
   * @param list<string>|null $actorIds Party order, including reserves; null checks the whole catalog.
   * @return array<string, int> Catalog source costs, not a concurrent frame budget.
   */
  public static function preflight(BattleResultsSkin $skin, string $root, array $battlefield = [], ?array $actorIds = null): array
  {
    foreach (['panel' => [754, 502], 'quiet' => [470, 164], 'track' => [582, 12],
      'selector' => [16, 16], 'portrait' => [96, 96], 'exp' => [578, 8],
      'divider' => [440, 16], 'button' => [230, 46]] as $role => [$width, $height]) {
      $skin->textures[$role]->images('results-preflight', new CanvasRectangle(0, 0, $width, $height), 0);
    }
    $assets = [];
    $art = [...array_values($skin->textures), ...array_values($skin->icons)];
    foreach ($skin->portraits as $families) {
      array_push($art, ...array_values(array_filter($families)));
    }
    foreach ($art as $texture) {
      PngAssetPreflight::inspect($root, $texture->asset, $texture->source);
      $size = PngAssetPreflight::inspect($root, $texture->asset);
      $assets[$texture->asset] = $size['width'] * $size['height'] * 4;
    }
    foreach ([...array_values($skin->icons), ...array_merge(...array_map(
      static fn(array $families): array => array_values(array_filter($families)), array_values($skin->portraits)))] as $texture) {
      $size = PngAssetPreflight::inspect($root, $texture->asset);
      if ($texture->source->x !== 0 || $texture->source->y !== 0
        || $texture->source->width !== $size['width'] || $texture->source->height !== $size['height']) {
        throw new RuntimeException('Results portrait and icon families use full-source contain, not arbitrary crops.');
      }
    }
    $base = [...$battlefield, ...CanvasImagePreflight::textures([
      ...array_values($skin->textures), ...array_values($skin->icons),
    ])];
    CanvasImagePreflight::inspect($base, $root);
    $actorIds ??= array_keys($skin->portraits);
    foreach ($actorIds as $index => $id) {
      // Primary pages show at most four consecutive actors. Continued names may shift page edges.
      $menus = [];
      foreach (array_slice($actorIds, $index, 4) as $visibleId) {
        $menu = $skin->portraits[$visibleId]['menu'] ?? null;
        if ($menu !== null) { $menus[] = $menu; }
      }
      CanvasImagePreflight::inspect([...$base, ...CanvasImagePreflight::textures($menus)], $root);
      $bust = $skin->portraits[$id]['bust'] ?? null;
      if ($bust !== null) {
        // Event pages replace Primary, and use one actor's bust; no outgoing/incoming union.
        CanvasImagePreflight::inspect([...$base, ...CanvasImagePreflight::textures([$bust])], $root);
      }
    }
    return $assets;
  }

  public static function frame(PresentationCanvas $battlefield, BattleResultsSkin $skin,
    BattleResultsPlayback $playback): PresentationCanvas
  {
    if ($battlefield->width !== 1350 || $battlefield->height !== 720) {
      throw new InvalidArgumentException('This Results layout requires a 1350x720 battle canvas.');
    }
    $playback->setScrollLimit(BattleResultsContent::pageCount($playback) - 1);
    if ($playback->isFinished() || $playback->opacity() <= 0) { return $battlefield; }
    $view = new self($skin, $playback);
    $stage = $playback->currentStage();
    $view->heading(match ($stage['kind']) {
      'primary' => 'VICTORY', 'level' => 'LEVEL UP', 'ability' => 'NEW ABILITY', default => 'SPECIAL REWARD',
    });
    if ($stage['kind'] === 'primary') { $view->primary(); } else { $view->event(); }
    $view->controls();
    return new PresentationCanvas(1350, 720, [...$battlefield->images, ...$view->images],
      $battlefield->indicators, [...$battlefield->textLayers, ...$view->text]);
  }

  private function heading(string $title): void
  {
    $this->line('heading', $title, 80, 28, 1190, 36, 54, 'text', align: 'center');
    $this->image('heading-divider', 'divider', new CanvasRectangle(455, 123, 440, 16));
    $counter = $this->playback->eventCounter();
    if ($counter['current'] > 0) {
      $this->line('event-counter', $counter['current'] . '/' . $counter['total'], 1138, 90, 160, 11, 22, 'muted', align: 'right');
    }
  }

  private function primary(): void
  {
    $this->image('party', 'panel', new CanvasRectangle(52, 146, 754, 502));
    $this->image('rewards', 'panel', new CanvasRectangle(828, 146, 470, 324));
    $this->image('summary', 'quiet', new CanvasRectangle(828, 484, 470, 164));
    $this->line('party-title', 'PARTY PROGRESS', 80, 168, 698, 11, 22, 'accent');
    $this->line('rewards-title', 'REWARDS', 856, 168, 414, 11, 22, 'accent');
    $this->line('summary-title', 'BATTLE SUMMARY', 856, 508, 414, 11, 22, 'accent');
    $rewards = $this->playback->rewards;
    $rows = $this->page(BattleResultsContent::partyRows($rewards), 4);
    foreach ($rows as $slot => $row) {
      $actor = $row['actor'];
      $award = $rewards->progression[$actor];
      $progress = $this->playback->progress($actor);
      $y = 198 + $slot * 106;
      $alpha = $this->playback->reveal(0.36 + $slot * 0.055, 0.32);
      $this->portrait('party-' . $slot, $award->after->actorId, $award->after->name,
        'menu', new CanvasRectangle(80, $y, 96, 96), $alpha);
      foreach ($row['name'] as $line => $name) {
        $this->line('party-name-' . $slot . '-' . $line, $name, 196, $y + $line * 28,
          $row['nameColumns'] * 14, 14, 28, 'text', alpha: $alpha);
      }
      $this->line('party-level-' . $slot, 'Lv ' . $progress['level'], 680, $y, 98, 13, 26, 'accent', 'right', $alpha);
      $gain = $award->experienceAwarded > 0 ? '+' . $award->experienceAwarded : '';
      $this->line('party-gain-' . $slot, $gain, 778 - $row['gainWidth'], $y + 28,
        $row['gainWidth'], 13, 26, 'positive', 'right', $alpha);
      if ($progress['maximum']) {
        $this->line('party-cap-' . $slot, 'MAX LEVEL', 196, $y + 60, 260, 11, 22, 'muted', alpha: $alpha);
      } else {
        $this->gauge('party-' . $slot, new CanvasRectangle(196, $y + 64, 582, 12), $progress['ratio'], $alpha);
      }
      $markers = [];
      if ($row['continued']) { $markers[] = 'NAME CONTINUED'; }
      if ($this->playback->isComplete()) {
        if ($award->levelledUp()) { $markers[] = 'LEVEL UP'; }
        if ($award->learnedDetails !== []) { $markers[] = 'NEW ABILITY'; }
      }
      $this->line('party-markers-' . $slot, implode(' / ', $markers), 196, $y + 80, 582, 9, 18, 'accent', alpha: $alpha);
    }
    $this->total('experience', 'EXP / MEMBER', $rewards->experiencePerMember, 207);
    $this->total('gold', 'MONEY', $rewards->gold, 241);
    $this->line('items-title', 'ITEMS', 856, 278, 414, 11, 22, 'muted');
    $items = BattleResultsContent::itemLines($rewards);
    if ($items === []) { $this->line('items-empty', 'None', 856, 308, 414, 13, 26, 'muted'); }
    foreach ($this->page($items, 4) as $index => $item) {
      $y = 307 + 27 * $index;
      $alpha = $this->playback->reveal(0.65 + $index * 0.075);
      $quantityWidth = max(26, mb_strlen($item['quantity']) * 13);
      $this->line('item-name-' . $index, $item['name'], 856, $y, 403 - $quantityWidth, 13, 26, 'text', alpha: $alpha);
      $this->line('item-quantity-' . $index, $item['quantity'], 1270 - $quantityWidth, $y, $quantityWidth,
        13, 26, 'text', 'right', $alpha);
    }
    if (count($items) > 4) {
      $start = min($this->playback->scrollOffset, (int)ceil(count($items) / 4) - 1) * 4;
      $remaining = max(0, count($items) - $start - 4);
      $this->line('items-overflow', $remaining > 0 ? '+' . $remaining . ' more lines' : 'End of item list',
        856, 431, 414, 11, 22, 'muted');
    }
    $summary = BattleResultsContent::summaryLines($rewards);
    foreach ($this->page($summary, 3) as $row => $line) {
      $this->line('summary-' . $row, $line, 856, 544 + 26 * $row, 414, 11, 22, 'text');
    }
  }

  private function event(): void
  {
    $this->image('event', 'panel', new CanvasRectangle(100, 146, 1150, 502));
    $stage = $this->playback->currentStage();
    $actor = $stage['actor'];
    if ($actor !== null) {
      $person = $this->playback->rewards->progression[$actor]->after;
      $this->portrait('event', $person->actorId, $person->name, 'bust', new CanvasRectangle(178, 216, 304, 382));
      if ($stage['kind'] === 'ability') {
        $this->symbol('ability', 'skill', new CanvasRectangle(538, 560, 48, 48));
      }
    } else {
      $this->symbol('special', 'item', new CanvasRectangle(608, 212, 134, 134));
    }
    foreach ($this->page(BattleResultsContent::eventLines($this->playback), BattleResultsContent::eventPageSize($this->playback)) as $index => $line) {
      $this->line('event-line-' . $index, $line['text'], $actor === null ? 350 : 538,
        ($actor === null ? 386 : 198) + $index * 32, 650, 13, 26,
        $line['tone'], $actor === null ? 'center' : 'left', $this->playback->reveal(0.12 + $index * 0.06, 0.28));
    }
    if ($actor !== null && $stage['kind'] === 'level') {
      $progress = $this->playback->progress($actor);
      $label = $progress['maximum'] ? 'MAX LEVEL' : $progress['current'] . ' / ' . $progress['needed'];
      $this->line('event-progress', $label, 538, 553, 650, 13, 26, 'muted', 'right');
      if (!$progress['maximum']) {
        $this->gauge('event-progress', new CanvasRectangle(538, 594, 650, 12), $progress['ratio']);
      }
    }
  }

  private function controls(): void
  {
    $locked = $this->playback->isExiting();
    $prompt = $this->playback->confirmation();
    if ($prompt['opacity'] > 0) {
      $this->image('confirm', 'button', new CanvasRectangle(560, 662, 230, 46), alpha: $prompt['opacity']);
      $this->line('confirm', $prompt['label'], 560, 671, 230, 14, 28, 'text', 'center', $prompt['opacity']);
      $offset = GraphicalBattleHud::cursorOffset($this->playback->time(), $this->playback->reducedMotion || $locked);
      $this->image('selector', 'selector', new CanvasRectangle(579 + $offset, 677, 16, 16), alpha: $prompt['opacity']);
    }
    if ($this->playback->pageCount() > 1) {
      $this->line('pages', 'Page ' . ($this->playback->scrollOffset + 1) . '/' . $this->playback->pageCount()
        . ' - Navigate to view', 810, 673, 488, 11, 22, 'muted');
    }
  }

  private function total(string $id, string $label, int $value, int $y): void
  {
    $this->line($id . '-label', $label, 856, $y + 2, 143, 11, 22, 'muted');
    $this->line($id . '-value', (string)$value, 1010, $y, 260, 13, 26, 'accent', 'right');
  }

  private function portrait(string $id, string $actorId, string $name, string $family, CanvasRectangle $bounds, float $alpha = 1): void
  {
    $this->image($id . '-portrait-frame', 'portrait', $bounds, alpha: $alpha);
    $art = $this->skin->portraits[$actorId][$family] ?? null;
    if ($art !== null) {
      $this->contain($id . '-portrait', $art,
        new CanvasRectangle($bounds->x + 10, $bounds->y + 10, $bounds->width - 20, $bounds->height - 20), $alpha);
      return;
    }
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = implode('', array_map(static fn(string $word): string => mb_substr($word, 0, 1, 'UTF-8'), array_slice($words, 0, 2)));
    $this->line($id . '-initials', $initials === '' ? '?' : $initials, $bounds->x + 12,
      $bounds->y + ($bounds->height - 40) / 2, $bounds->width - 24, 20, 40, 'accent', 'center', $alpha);
  }

  private function symbol(string $id, string $category, CanvasRectangle $bounds): void
  {
    $art = $this->skin->icons[$category] ?? $this->skin->icons['unknown'] ?? null;
    if ($art !== null) { $this->contain($id, $art, $bounds); return; }
    $this->line($id . '-symbol', '?', $bounds->x, $bounds->y + ($bounds->height - 54) / 2,
      $bounds->width, 36, 54, 'accent', 'center');
  }

  private function contain(string $id, CanvasNineSlice $art, CanvasRectangle $bounds, float $alpha = 1): void
  {
    $scale = min($bounds->width / $art->source->width, $bounds->height / $art->source->height);
    $width = $art->source->width * $scale;
    $height = $art->source->height * $scale;
    $this->images[] = new CanvasImage('results-' . $id, $art->asset,
      new CanvasRectangle($bounds->x + ($bounds->width - $width) / 2, $bounds->y + ($bounds->height - $height) / 2, $width, $height),
      self::BASE_LAYER + 2, $art->source, $alpha * $this->opacity, $bounds);
  }

  private function gauge(string $id, CanvasRectangle $bounds, float $ratio, float $alpha = 1): void
  {
    $this->image($id . '-track', 'track', $bounds, alpha: $alpha);
    if ($ratio <= 0) { return; }
    $inner = new CanvasRectangle($bounds->x + 2, $bounds->y + 2, $bounds->width - 4, $bounds->height - 4);
    $this->image($id . '-fill', 'exp', $inner,
      new CanvasRectangle($inner->x, $inner->y, $inner->width * min(1, $ratio), $inner->height), $alpha);
  }

  private function image(string $id, string $role, CanvasRectangle $bounds, ?CanvasRectangle $clip = null, float $alpha = 1): void
  {
    foreach ($this->skin->textures[$role]->images('results-' . $id, $bounds, self::BASE_LAYER + 1, $clip) as $image) {
      $this->images[] = new CanvasImage($image->id, $image->asset, $image->destination, $image->layer,
        $image->sourceRect, $this->opacity * $alpha, $image->clipRect);
    }
  }

  private function line(string $id, string $label, float $x, float $y, float $width, int $cellWidth, int $cellHeight,
    string $color, string $align = 'left', float $alpha = 1): void
  {
    if ($label === '') { return; }
    $columns = (int)floor($width / $cellWidth);
    $length = mb_strlen($label, 'UTF-8');
    if ($length > $columns) { throw new RuntimeException('Results text must be paginated before composition: ' . $id); }
    if ($align === 'center') {
      // Center the actual glyph cells, not a rounded number of padding cells.
      $x += ($width - $length * $cellWidth) / 2;
      $columns = $length;
      $width = $length * $cellWidth;
    }
    $column = $align === 'right' ? $columns - $length : 0;
    $effects = $id === 'heading' ? new CanvasGlyphEffects(1, $this->skin->colors['ink'],
      1, 2, 1, 0.8, $this->skin->colors['ink']) : null;
    // Counters and navigation hints sit over arbitrary arena art, unlike panel text.
    $background = in_array($id, ['event-counter', 'pages'], true) ? $this->skin->colors['ink'] : null;
    $this->text[] = new CanvasTextLayer('results-' . $id, self::BASE_LAYER + 3, $x, $y,
      new RendererGridConfig($columns, 1, $cellWidth, $cellHeight),
      [new PresentationTextRun(0, $column, $label, $this->skin->colors[$color], $background)],
      $effects === null ? new CanvasRectangle($x, $y, $width, $cellHeight) : null,
      $alpha * $this->opacity, $effects);
  }

  private function page(array $rows, int $size): array
  {
    $page = min($this->playback->scrollOffset, max(0, (int)ceil(count($rows) / $size) - 1));
    return array_slice($rows, $page * $size, $size);
  }
}
