<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Progression\Mastery\MasteryVocabulary;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImagePreflight;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasGlyphEffects;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextBatch;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextureFallback;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use InvalidArgumentException;
use RuntimeException;
use Ichiloto\Engine\IO\ActionHint;
use Ichiloto\Engine\UI\Presentation\MenuActionHints;
use Ichiloto\Engine\UI\Presentation\MenuLayout;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuRow;
use Ichiloto\Engine\UI\Presentation\MenuRowKind;
use Ichiloto\Engine\UI\Presentation\MenuRowPainter;

/** A disposable overlay over the caller's real final battlefield, never a gameplay owner. */
final class GraphicalBattleResults
{
  private const BASE_LAYER = 10000;
  private const PANEL_BOTTOM = 648;
  /** Where the reward totals sit, by how many there are: EXP and gold, or EXP, SP and gold. */
  private const array TOTAL_ROWS = [2 => [207, 241], 3 => [196, 222, 248]];
  private array $images = [];
  private array $text = [];
  private float $opacity;

  private function __construct(private BattleResultsSkin $skin, private BattleResultsPlayback $playback, private ?MenuPresentationCatalog $controls)
  {
    $this->opacity = $playback->opacity();
    $this->controls ??= new MenuPresentationCatalog($skin->assetRoot ?? '', ['schema' => 'ichiloto.menu/1']);
  }

  /** Validate the catalog, but budget only concurrently displayed families.
   * @param list<CanvasImage> $battlefield
   * @param list<string>|BattleRewards|null $actorIds Actual rewards select the measured pages; an ID list checks every possible four-actor window.
   * @return array<string, int> Catalog source costs, not a concurrent frame budget.
   */
  public static function preflight(BattleResultsSkin $skin, string $root, array $battlefield = [], array|BattleRewards|null $actorIds = null): array
  {
    return self::preflightPrepared($skin->withCurrentImages($root), $root, $battlefield, $actorIds);
  }

  /** Startup validates art and the base frame; rewards do not exist until the battle finishes. */
  public static function prepareCatalog(BattleResultsSkin $skin, string $root, array $battlefield = []): BattleResultsSkin
  {
    $skin = $skin->withCurrentImages($root);
    self::preflightPrepared($skin, $root, $battlefield, []);
    return $skin;
  }

  /** Resolve replaceable whole images once for the disposable Results presentation. */
  public static function prepare(BattleResultsSkin $skin, string $root, array $battlefield = [], array|BattleRewards|null $actorIds = null): BattleResultsSkin
  {
    $skin = $skin->withCurrentImages($root);
    self::preflightPrepared($skin, $root, $battlefield, $actorIds);
    return $skin;
  }

  private static function preflightPrepared(BattleResultsSkin $skin, string $root, array $battlefield, array|BattleRewards|null $actorIds): array
  {
    foreach (['panel' => [754, 502], 'quiet' => [470, 164], 'track' => [582, 12],
      'selector' => [16, 16], 'portrait' => [96, 96], 'exp' => [578, 8],
      'divider' => [440, 16]] as $role => [$width, $height]) {
      $skin->textures[$role]->images('results-preflight', new CanvasRectangle(0, 0, $width, $height), 0);
    }
    $assets = [];
    // Legacy catalogs may share their old Results button texture with Pause.
    $textures = CanvasTextureFallback::getAvailableTextures(array_diff_key($skin->textures, ['button' => true]), $root);
    $art = [...$textures, ...array_values($skin->icons)];
    foreach ($skin->portraits as $families) {
      array_push($art, ...array_values(array_filter($families)));
    }
    foreach ($art as $texture) {
      PngAssetPreflight::inspect($root, $texture->asset, $texture->source);
      $size = PngAssetPreflight::inspect($root, $texture->asset);
      $assets[$texture->asset] = $size['width'] * $size['height'] * 4;
    }
    $base = [...$battlefield, ...CanvasImagePreflight::textures([
      ...$textures, ...array_values($skin->icons),
    ])];
    CanvasImagePreflight::inspect($base, $root);
    if ($actorIds instanceof BattleRewards) {
      // Long names can continue onto another card. Use exactly the same rows as drawing/navigation.
      $pages = [];
      foreach (array_chunk(BattleResultsContent::partyRows($actorIds), 4) as $rows) {
        $pages[] = array_values(array_unique(array_map(
          static fn(array $row): string => $actorIds->progression[$row['actor']]->after->actorId, $rows)));
      }
      $ids = array_values(array_unique(array_merge([], ...$pages)));
    } else {
      $ids = $actorIds ?? array_keys($skin->portraits);
      // Catalog-only callers have no names/reward widths; conservatively check possible page edges.
      $pages = array_map(static fn(int $index): array => array_slice($ids, $index, 4), array_keys($ids));
    }
    foreach ($pages as $page) {
      $menus = [];
      foreach ($page as $id) {
        $menu = $skin->portraits[$id]['menu'] ?? null;
        if ($menu !== null) { $menus[] = $menu; }
      }
      CanvasImagePreflight::inspect([...$base, ...CanvasImagePreflight::textures($menus)], $root);
    }
    foreach ($ids as $id) {
      $bust = $skin->portraits[$id]['bust'] ?? null;
      if ($bust !== null) {
        // Event pages replace Primary, and use one actor's bust; no outgoing/incoming union.
        CanvasImagePreflight::inspect([...$base, ...CanvasImagePreflight::textures([$bust])], $root);
      }
    }
    return $assets;
  }

  public static function frame(PresentationCanvas $battlefield, BattleResultsSkin $skin,
    BattleResultsPlayback $playback, ?MenuPresentationCatalog $controls = null): PresentationCanvas
  {
    if ($battlefield->width !== PresentationCanvas::DEFAULT_WIDTH || $battlefield->height !== PresentationCanvas::DEFAULT_HEIGHT) {
      throw new InvalidArgumentException(sprintf('This Results layout requires a %dx%d battle canvas.',
        PresentationCanvas::DEFAULT_WIDTH, PresentationCanvas::DEFAULT_HEIGHT));
    }
    $playback->setScrollLimit(BattleResultsContent::pageCount($playback) - 1);
    if ($playback->isFinished() || $playback->opacity() <= 0) { return $battlefield; }
    $view = new self($skin, $playback, $controls);
    $stage = $playback->currentStage();
    $view->heading(match ($stage['kind']) {
      'primary' => Vocabulary::getTerm('battle.victory', 'VICTORY'),
      'level' => Vocabulary::getTerm('battle.level_up', 'LEVEL UP'),
      'ability' => Vocabulary::getTerm('battle.new_ability', 'NEW ABILITY'),
      default => Vocabulary::getTerm('battle.special_reward', 'SPECIAL REWARD'),
    });
    if ($stage['kind'] === 'primary') { $view->primary(); } else { $view->event(); }
    $view->controls();
    return new PresentationCanvas(PresentationCanvas::DEFAULT_WIDTH, PresentationCanvas::DEFAULT_HEIGHT, [...$battlefield->images, ...$view->images],
      $battlefield->indicators, CanvasTextBatch::compact([...$battlefield->textLayers, ...$view->text]), $battlefield->composites,
      [...$battlefield->getOverlayProtection(),
        ...array_map(static fn(CanvasImage $image) => $image->clipRect ?? $image->destination, $view->images),
        ...array_map(static fn(CanvasTextLayer $text) => $text->clipRect ?? $text->paintBounds, $view->text)],
      viewportFit: $battlefield->viewportFit, groups: $battlefield->groups);
  }

  private function heading(string $title): void
  {
    $this->line('heading', $title, 80, 28, 1190, 36, 54, 'text', align: 'center');
    $this->renderImage('heading-divider', 'divider', new CanvasRectangle(455, 123, 440, 16));
  }

  private function primary(): void
  {
    $this->renderImage('party', 'panel', new CanvasRectangle(52, 146, 754, self::PANEL_BOTTOM - 146));
    $this->renderImage('rewards', 'panel', new CanvasRectangle(828, 146, 470, 324));
    $this->renderImage('summary', 'quiet', new CanvasRectangle(828, 484, 470, self::PANEL_BOTTOM - 484));
    $this->line('party-title', Vocabulary::getTerm('battle.party_progress', 'PARTY PROGRESS'), 80, 168, 698, 11, 22, 'accent');
    $this->line('rewards-title', Vocabulary::getTerm('battle.rewards', 'REWARDS'), 856, 168, 414, 11, 22, 'accent');
    $this->line('summary-title', Vocabulary::getTerm('battle.summary', 'BATTLE SUMMARY'), 856, 508, 414, 11, 22, 'accent');
    $rewards = $this->playback->rewards;
    $rows = BattleResultsContent::getVisiblePartyRows($this->playback);
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
      $this->line('party-level-' . $slot, Vocabulary::getTerm('stats.level_short') . ' ' . $progress['level'], 680, $y, 98, 13, 26, 'accent', 'right', $alpha);
      $gain = $award->experienceAwarded > 0 ? '+' . $award->experienceAwarded : '';
      $this->line('party-gain-' . $slot, $gain, 778 - $row['gainWidth'], $y + 28,
        $row['gainWidth'], 13, 26, 'positive', 'right', $alpha);
      if ($progress['maximum']) {
        $this->line('party-cap-' . $slot, Vocabulary::getTerm('battle.max_level', 'MAX LEVEL'), 196, $y + 60, 260, 11, 22, 'muted', alpha: $alpha);
      } else {
        $this->renderGauge('party-' . $slot, new CanvasRectangle(196, $y + 64, 582, 12), $progress['ratio'], $alpha);
      }
      $markers = [];
      if ($row['continued']) { $markers[] = 'NAME CONTINUED'; }
      if ($this->playback->isComplete()) {
        if ($award->levelledUp()) { $markers[] = Vocabulary::getTerm('battle.level_up', 'LEVEL UP'); }
        if ($award->learnedDetails !== []) { $markers[] = Vocabulary::getTerm('battle.new_ability', 'NEW ABILITY'); }
      }
      $this->line('party-markers-' . $slot, implode(' / ', $markers), 196, $y + 80, 582, 9, 18, 'accent', alpha: $alpha);
    }
    // EXP, then SP when the battle gave any, then gold, in the band above the item list.
    $totals = [['experience', Vocabulary::getTerm('stats.exp'), $rewards->experiencePerMember]];
    if ($rewards->skillPointsPerMember > 0) {
      $totals[] = ['skill-points', MasteryVocabulary::getPointsLabel(), $rewards->skillPointsPerMember];
    }
    $totals[] = ['gold', Vocabulary::getTerm('currency.name', 'Gold'), trim($rewards->gold . ' ' . Vocabulary::getTerm('currency.symbol', 'G'))];
    foreach ($totals as $index => [$id, $label, $value]) {
      $this->total($id, $label, $value, self::TOTAL_ROWS[count($totals)][$index]);
    }
    $items = BattleResultsContent::itemLines($rewards);
    $this->line('items-title', Vocabulary::getTerm($items === [] ? 'battle.no_items' : 'battle.items'), 856, 278, 414, 11, 22, 'muted');
    foreach ($this->page($items, 4) as $index => $item) {
      $y = 307 + 27 * $index;
      $alpha = $this->playback->reveal(0.65 + $index * 0.075);
      $quantityWidth = max(26, mb_strlen($item['quantity']) * 13);
      $this->line('item-name-' . $index, $item['name'], 856, $y, 403 - $quantityWidth, 13, 26, 'text', alpha: $alpha);
      $this->line('item-quantity-' . $index, $item['quantity'], 1270 - $quantityWidth, $y, $quantityWidth,
        13, 26, 'text', 'right', $alpha);
    }
    $summary = BattleResultsContent::summaryLines($rewards);
    foreach ($this->page($summary, 3) as $row => $line) {
      $this->line('summary-' . $row, $line, 856, 544 + 26 * $row, 414, 11, 22, 'text');
    }
  }

  private function event(): void
  {
    $this->renderImage('event', 'panel', new CanvasRectangle(100, 146, 1150, self::PANEL_BOTTOM - 146));
    $counter = $this->playback->eventCounter();
    if ($counter['current'] > 0) {
      $this->line('event-counter', $counter['current'] . '/' . $counter['total'],
        538, 170, 650, 13, 26, 'accent', align: 'right');
    }
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
    foreach ($this->page(BattleResultsContent::getEventLines($this->playback), BattleResultsContent::eventPageSize($this->playback)) as $index => $line) {
      $this->line('event-line-' . $index, $line['text'], $actor === null ? 350 : 538,
        ($actor === null ? 386 : 198) + $index * 32, 650, 13, 26,
        $line['tone'], $actor === null ? 'center' : 'left', $this->playback->reveal(0.12 + $index * 0.06, 0.28));
    }
    if ($actor !== null && $stage['kind'] === 'level') {
      $progress = $this->playback->progress($actor);
      $label = $progress['maximum'] ? Vocabulary::getTerm('battle.max_level', 'MAX LEVEL') : $progress['current'] . ' / ' . $progress['needed'];
      $this->line('event-progress', $label, 538, 553, 650, 13, 26, 'muted', 'right');
      if (!$progress['maximum']) {
        $this->renderGauge('event-progress', new CanvasRectangle(538, 594, 650, 12), $progress['ratio']);
      }
    }
  }

  private function controls(): void
  {
    $prompt = $this->playback->confirmation();
    if ($prompt['opacity'] > 0) {
      $this->renderConfirmation($prompt['label'], $prompt['opacity']);
    }
    $navigation = array_find($this->playback->getActionHints(), static fn(ActionHint $hint): bool => $hint->pairedAction !== null);
    if ($this->playback->currentStage()['kind'] === 'primary') {
      $pages = BattleResultsContent::getPrimaryPageCounts($this->playback->rewards);
      $this->renderFooter('party', 80, 620, 698, $pages['party'], $navigation);
      $this->renderFooter('items', 856, 438, 414, $pages['items'], $navigation);
      $this->renderFooter('summary', 856, 620, 414, $pages['summary'], $navigation);
    } else {
      $this->renderFooter('event', 128, 620, 1094, $this->playback->pageCount(), $navigation);
    }
  }

  private function renderConfirmation(string $label, float $alpha): void
  {
    $width = PresentationCanvas::DEFAULT_WIDTH;
    $height = PresentationCanvas::DEFAULT_HEIGHT;
    $footerHeight = $height - self::PANEL_BOTTOM;
    $buttonHeight = min(MenuLayout::getButtonHeight($this->controls), $footerHeight);
    $buttonWidth = MenuLayout::getButtonWidth($this->controls, $label, $width - 2 * MenuLayout::OUTER_MARGIN);
    $bounds = new CanvasRectangle(($width - $buttonWidth) / 2,
      self::PANEL_BOTTOM + ($footerHeight - $buttonHeight) / 2, $buttonWidth, $buttonHeight);
    $layout = MenuLayout::getButtonLayout($this->controls, $bounds);
    $row = new MenuRow('confirm', $label, kind: MenuRowKind::BUTTON, selected: true, focused: true);
    $this->appendControls(MenuRowPainter::compose($width, $height, 'results', [$row], $layout,
      $this->controls->rows, layer: self::BASE_LAYER + 4), $alpha);
  }

  /** A complete, measured paging hint stays together inside each continuing panel. */
  private function renderFooter(string $id, int $x, int $y, int $width, int $pages, ?ActionHint $navigation = null): void
  {
    if ($pages <= 1) { return; }
    $page = min($this->playback->scrollOffset + 1, $pages);
    if ($navigation === null) {
      $this->line($id . '-pages', $page . ' / ' . $pages, $x, $y, $width, 9, 18, 'muted', 'center');
      return;
    }
    $hint = new ActionHint($navigation->action, $navigation->label . ' ' . $page . ' / ' . $pages,
      $navigation->control, $navigation->pairedControl, $navigation->pairedAction);
    $hintWidth = MenuActionHints::measureCells([$hint], $this->controls, [9, 18]) * 9;
    if ($hintWidth > $width) { throw new RuntimeException('Results paging hint exceeds its panel footer.'); }
    $this->appendControls(MenuActionHints::compose(PresentationCanvas::DEFAULT_WIDTH, PresentationCanvas::DEFAULT_HEIGHT,
      'results-' . $id . '-pages', [$hint], $this->controls,
      new CanvasRectangle($x + ($width - $hintWidth) / 2, $y, $hintWidth, 18), required: true, cell: [9, 18],
      layer: self::BASE_LAYER + 4));
  }

  private function appendControls(PresentationCanvas $canvas, float $alpha = 1): void
  {
    $opacity = $alpha * $this->opacity;
    foreach ($canvas->images as $image) {
      $this->images[] = new CanvasImage($image->id, $image->asset, $image->destination, $image->layer,
        $image->sourceRect, $image->opacity * $opacity, $image->clipRect, $image->brightness, $image->flipX, $image->flipY);
    }
    foreach ($canvas->textLayers as $text) {
      $this->text[] = new CanvasTextLayer($text->id, $text->layer, $text->x, $text->y, $text->grid,
        $text->runs, $text->clipRect, $text->opacity * $opacity, $text->glyphEffects);
    }
  }

  private function total(string $id, string $label, int|string $value, int $y): void
  {
    $valueWidth = max(78, strlen((string)$value) * 13);
    $labelWidth = 414 - $valueWidth - 16;
    $cellWidth = max(1, min(11, (int)floor($labelWidth / max(1, mb_strlen($label)))));
    $this->line($id . '-label', $label, 856, $y + 2, $labelWidth, $cellWidth, 22, 'muted');
    $this->line($id . '-value', (string)$value, 1270 - $valueWidth, $y, $valueWidth, 13, 26, 'accent', 'right');
  }

  private function portrait(string $id, string $actorId, string $name, string $family, CanvasRectangle $bounds, float $alpha = 1): void
  {
    $this->renderImage($id . '-portrait-frame', 'portrait', $bounds, alpha: $alpha);
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

  private function renderGauge(string $id, CanvasRectangle $bounds, float $ratio, float $alpha = 1): void
  {
    $this->renderImage($id . '-track', 'track', $bounds, alpha: $alpha);
    if (!CanvasTextureFallback::isAvailable($this->skin->textures['exp'], $this->skin->assetRoot)) {
      $inner = new CanvasRectangle($bounds->x + 2, $bounds->y + 2, $bounds->width - 4, $bounds->height - 4);
      $this->skin->textures['exp']->images('results-' . $id . '-fill', $inner, self::BASE_LAYER + 2);
      $this->line($id . '-percentage', (string)round(max(0, min(1, $ratio)) * 100) . '%',
        $bounds->x, $bounds->y - 4, $bounds->width, 8, 16, 'positive', alpha: $alpha);
      return;
    }
    if ($ratio <= 0) { return; }
    $inner = new CanvasRectangle($bounds->x + 2, $bounds->y + 2, $bounds->width - 4, $bounds->height - 4);
    $this->renderImage($id . '-fill', 'exp', $inner,
      new CanvasRectangle($inner->x, $inner->y, $inner->width * min(1, $ratio), $inner->height), $alpha);
  }

  private function renderImage(string $id, string $role, CanvasRectangle $bounds, ?CanvasRectangle $clip = null, float $alpha = 1): void
  {
    if (in_array($role, ['track', 'portrait', 'divider'], true)
      && !CanvasTextureFallback::isAvailable($this->skin->textures[$role], $this->skin->assetRoot)) {
      $this->skin->textures[$role]->images('results-' . $id, $bounds, self::BASE_LAYER + 1, $clip);
      return;
    }
    $color = match ($role) { 'exp' => 'positive', 'selector', 'divider' => 'accent', 'track' => 'muted', default => 'ink' };
    $layer = self::BASE_LAYER + match ($role) { 'panel', 'quiet' => 0, 'exp' => 2, default => 1 };
    $parts = CanvasTextureFallback::render($this->skin->textures[$role], 'results-' . $id, $bounds,
      $layer, $this->skin->colors[$color], $this->skin->assetRoot, $clip, $this->opacity * $alpha);
    array_push($this->images, ...$parts['images']);
    array_push($this->text, ...$parts['textLayers']);
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
    $background = str_ends_with($id, '-percentage') ? $this->skin->colors['ink'] : null;
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
