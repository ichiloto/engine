<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Entities\Enemies\EnemyHabitat;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use InvalidArgumentException;
use RuntimeException;

/** Read-only scene/formation composition shared with runtime artwork placement. */
final readonly class BattleFormationLayout
{
  /**
   * @param list<CanvasImage> $backgrounds
   * @param list<BattleFormationBattler> $party
   * @param list<BattleFormationBattler> $enemies
   * @param array<string, string> $arenaChoices
   */
  private function __construct(
    public BattleCanvasLayout $layout,
    public ?BattleArenaDefinition $arena,
    public BattleStaging $staging,
    public array $backgrounds,
    public array $party,
    public array $enemies,
    public array $arenaChoices,
  ) {}

  /**
   * Arena selection is preview context only; this never mutates a troop or combat state.
   * Members without a place are arranged by the Engine beside the placed ones,
   * exactly as the battle arranges them ({@see BattleStaging}).
   * @param list<array{enemyId: string, slot: ?BattleStageSlot, habitat?: EnemyHabitat}> $enemies Each member, where it is
   * placed, and where it lives (land when not given).
   * @param list<string> $partyActorIds
   */
  public static function compose(BattlePresentationCatalog $catalog, ?string $arenaId, array $enemies,
    array $partyActorIds, string $assetRoot, float $elapsedSeconds = 0, bool $reducedMotion = false): self
  {
    if (!is_finite($elapsedSeconds) || $elapsedSeconds < 0 || !array_is_list($enemies)
      || !array_is_list($partyActorIds) || count($enemies) > 64) {
      throw new InvalidArgumentException('Formation previews require bounded lists and finite non-negative pose time.');
    }
    $arenaId ??= $catalog->defaultArena;
    $arena = $arenaId === null ? null : $catalog->getArena($arenaId);
    $layout = $catalog->ui ?? throw new RuntimeException('A graphical formation requires an explicit battle canvas layout.');
    if ($arena !== null) { $layout = $layout->getForArena($arena); }
    if ($layout->partySlots !== [] && count($partyActorIds) > count($layout->partySlots)) {
      throw new InvalidArgumentException('The formation requires a slot for every preview party member.');
    }
    $backgrounds = [];
    if ($arena !== null) {
      $arena->background->destination->assertWithin($layout->width, $layout->height);
      if (PngAssetPreflight::getAvailableSize($assetRoot, $arena->background->asset) !== null) {
        $backgrounds[] = $arena->background;
      }
    }
    foreach ($partyActorIds as $id) {
      if (!is_string($id)) { throw new InvalidArgumentException('Party preview identities must be strings.'); }
      CanvasValidation::id($id);
    }
    $places = [];
    foreach ($enemies as $entry) {
      if (!is_array($entry) || !is_string($entry['enemyId'] ?? null) || !array_key_exists('slot', $entry)
        || !($entry['slot'] === null || $entry['slot'] instanceof BattleStageSlot)
        || !(($entry['habitat'] ?? null) === null || $entry['habitat'] instanceof EnemyHabitat)) {
        throw new InvalidArgumentException('Enemy previews require enemyId, a typed stage place or null, and a habitat when given.');
      }
      CanvasValidation::id($entry['enemyId']);
      $places[] = $entry['slot'];
    }
    $getSize = static fn(string $id, bool $party, ?BattleStageSlot $place): array => self::getStandingSize(
      self::getAvailableArtwork($catalog, $id, $party, $assetRoot), (($party ? $catalog->actorPoses : $catalog->enemyPoses)[$id] ?? null),
      $catalog->scale?->getProfile($id, $party), $catalog->scale, $place, $assetRoot);
    $staging = BattleStaging::stage($layout, $arena,
      array_map(static fn(string $id): array => $getSize($id, true, null), $partyActorIds),
      $places, array_map(static fn(array $entry): array => $getSize($entry['enemyId'], false, $entry['slot']), $enemies),
      array_map(static fn(array $entry): EnemyHabitat => $entry['habitat'] ?? EnemyHabitat::LAND, $enemies));
    $party = $troop = [];
    foreach ($partyActorIds as $index => $id) {
      $party[] = self::resolveBattler($catalog, $layout, $assetRoot, $id, true,
        $staging->party[$index], $index, $elapsedSeconds, $reducedMotion, $arena);
    }
    foreach ($enemies as $index => $entry) {
      $troop[] = self::resolveBattler($catalog, $layout, $assetRoot, $entry['enemyId'], false,
        $staging->enemies[$index], $index, $elapsedSeconds, $reducedMotion, $arena, $entry['habitat'] ?? EnemyHabitat::LAND);
    }
    return new self($layout, $arena, $staging, $backgrounds, $party, $troop, $catalog->getArenaChoices());
  }

  /**
   * A battler's drawn size before slot depth, at rest: what staging spaces it
   * by. Read from the current artwork (its idle pose when it has one) at
   * battle scale, or the legacy width or contain fit; the reference body when
   * no art is usable, so a name stand-in still takes a place.
   *
   * @return array{width: float, height: float, pivotX: float, pivotY: float}
   */
  public static function getStandingSize(?BattlerArtwork $art, ?BattlePoseSet $poses, ?BattlerScale $profile,
    ?BattleScale $scale, ?BattleStageSlot $place, string $assetRoot): array
  {
    $pose = $poses?->getPose(BattlePoseRole::IDLE);
    try {
      $posed = $pose?->getArtwork($assetRoot, 0, true);
    } catch (InvalidArgumentException|RuntimeException) {
      $posed = null;
    }
    $drawn = $posed ?? $art;
    if ($drawn === null) {
      $unit = $scale === null ? 1.0 : $scale->referenceHeight * ($profile?->relativeSize ?? 1.0);
      return ['width' => $unit, 'height' => $unit, 'pivotX' => .5, 'pivotY' => 1.0];
    }
    $pixelScale = match (true) {
      $profile !== null && $scale !== null => $profile->getPixelScale($scale->referenceHeight, $drawn,
        $posed === null ? null : $pose->scaleSpan),
      $poses?->displayWidth !== null && $art !== null => $poses->displayWidth / $art->width,
      $place !== null => min($place->width / $drawn->width, $place->height / $drawn->height),
      default => 1.0,
    };
    return ['width' => $drawn->width * $pixelScale, 'height' => $drawn->height * $pixelScale,
      'pivotX' => $drawn->pivotX / $drawn->width, 'pivotY' => $drawn->pivotY / $drawn->height];
  }

  private static function getAvailableArtwork(BattlePresentationCatalog $catalog, string $id, bool $party, string $assetRoot): ?BattlerArtwork
  {
    $art = ($party ? $catalog->actors : $catalog->enemies)[$id] ?? null;
    $size = $art === null ? null : PngAssetPreflight::getAvailableSize($assetRoot, $art->asset);
    return $size === null ? null : $art->clampedTo($size['width'], $size['height']);
  }

  /** Metadata for the existing name-only runtime fallback; no substitute image is drawn. */
  public static function getUnavailableArtwork(): BattlerArtwork
  {
    return new BattlerArtwork('unavailable-battler.png', 1, 1, .5, 1);
  }

  /** @return array{party: list<list<string>>, enemies: list<list<string>>} */
  public function getClearanceDiagnostics(string $assetRoot): array
  {
    $getImages = static fn(array $members): array => array_values(array_filter(array_column($members, 'image')));
    $labels = [];
    foreach ([$this->party, $this->enemies] as $members) {
      foreach ($members as $index => $member) {
        if ($member->image !== null) {
          $labels[$member->image->id] = BattleFormationClearance::getBattlerLabel($member->id, $member->party, $index);
        }
      }
    }
    $issues = BattleFormationClearance::inspect($this->layout, $getImages($this->party), $getImages($this->enemies), $assetRoot, $labels);
    $getIssues = static fn(array $members): array => array_map(static fn(BattleFormationBattler $member): array =>
      [...$member->diagnostics, ...($member->image === null ? [] : ($issues[$member->image->id] ?? []))], $members);
    return ['party' => $getIssues($this->party), 'enemies' => $getIssues($this->enemies)];
  }

  /** @return array{bounds: CanvasRectangle, diagnostic: ?string} */
  public static function getBasePlacement(BattlerSlot $slot, ?BattlerArtwork $art, BattleCanvasLayout $layout,
    ?BattlePoseSet $poses, ?BattlerScale $profile, ?BattleScale $scale): array
  {
    // No image pixels exist to calibrate. Preserve only the name-fallback anchor.
    if ($art === null) {
      if ($slot->x > $layout->width || $slot->y > $layout->height) {
        throw new InvalidArgumentException('A name-fallback anchor must be inside the graphical canvas.');
      }
      $bounds = new CanvasRectangle(max(0, min($layout->width - 1, $slot->x - .5)),
        max(0, min($layout->height - 1, $slot->y - 1)), 1, 1);
      $bounds->assertWithin($layout->width, $layout->height);
      return ['bounds' => $bounds, 'diagnostic' => null];
    }
    $diagnostic = null;
    if ($profile !== null && $scale !== null) {
      $bounds = $slot->placeAtScale($art, $profile->getPixelScale($scale->referenceHeight, $art));
    } elseif ($poses?->displayWidth !== null) {
      try {
        $bounds = $slot->place($art, $poses->displayWidth);
        $bounds->assertWithin($layout->width, $layout->height);
      } catch (InvalidArgumentException|RuntimeException $error) {
        $diagnostic = 'Registered base battler placement unavailable; retaining contain fit: ' . $error->getMessage();
        $bounds = $slot->place($art);
      }
    } else {
      $bounds = $slot->place($art);
    }
    $bounds->assertWithin($layout->width, $layout->height);
    return ['bounds' => $bounds, 'diagnostic' => $diagnostic];
  }

  /** $basePixelScale excludes slot depth, which placement applies exactly once. */
  public static function getPosePlacement(BattlerSlot $slot, BattlerArtwork $art, ?float $basePixelScale,
    BattleCanvasLayout $layout, ?BattlerScale $profile, ?BattleScale $scale, ?float $scaleSpan = null,
    ?BattlePoseSet $poses = null): CanvasRectangle
  {
    if (($profile === null || $scale === null) && $basePixelScale === null) {
      return self::getBasePlacement($slot, $art, $layout, $poses, null, null)['bounds'];
    }
    $pixelScale = $profile !== null && $scale !== null
      ? $profile->getPixelScale($scale->referenceHeight, $art, $scaleSpan) : $basePixelScale;
    $bounds = $slot->placeAtScale($art, $pixelScale);
    $bounds->assertWithin($layout->width, $layout->height);
    return $bounds;
  }

  private static function resolveBattler(BattlePresentationCatalog $catalog, BattleCanvasLayout $layout,
    string $assetRoot, string $id, bool $party, BattlerSlot $slot, int $index,
    float $elapsedSeconds, bool $reducedMotion, ?BattleArenaDefinition $arena = null, ?EnemyHabitat $habitat = null): BattleFormationBattler
  {
    CanvasValidation::id($id);
    $diagnostics = [];
    $registered = ($party ? $catalog->actors : $catalog->enemies)[$id] ?? null;
    $poses = ($party ? $catalog->actorPoses : $catalog->enemyPoses)[$id] ?? null;
    $profile = $catalog->scale?->getProfile($id, $party);
    $size = $registered === null ? null : PngAssetPreflight::getAvailableSize($assetRoot, $registered->asset);
    $available = $size !== null;
    $art = $registered ?? self::getUnavailableArtwork();
    if ($size !== null) { $art = $art->clampedTo($size['width'], $size['height']); }
    else { $diagnostics[] = "Base battler artwork unavailable: {$id}"; }
    $placed = self::getBasePlacement($slot, $available ? $art : null, $layout, $poses, $profile, $catalog->scale);
    $bounds = $placed['bounds'];
    if ($placed['diagnostic'] !== null) { $diagnostics[] = $placed['diagnostic']; }
    $pose = $poses?->getPose(BattlePoseRole::IDLE);
    if ($pose !== null) {
      try {
        $idle = $pose->getArtwork($assetRoot, $elapsedSeconds, $reducedMotion);
        if ($idle === null) { throw new RuntimeException('Idle pose image is unavailable.'); }
        $bounds = self::getPosePlacement($slot, $idle, $available ? $bounds->width / $art->width / $slot->displayScale : null,
          $layout, $profile, $catalog->scale, $pose->scaleSpan, $poses);
        $art = $idle;
        $available = true;
      } catch (InvalidArgumentException|RuntimeException $error) {
        $diagnostics[] = "Idle pose unavailable for {$id}: {$error->getMessage()}";
      }
    }
    // The body rises or sinks by its elevation, hidden below the surface line; its contact stays at its feet.
    $grounding = ($party ? $catalog->actorGrounding : $catalog->enemyGrounding)[$id] ?? null;
    $habitat = $party ? null : $habitat;
    $elevation = BattlerGroundContact::getElevation($grounding, $habitat, $layout);
    $contact = BattlerGroundContact::getContact($grounding, $habitat, $elevation);
    $offset = BattlerGroundContact::getOffset($elevation, $slot, $catalog->scale);
    [$bounds, $clip] = BattlerGroundContact::place($bounds, $offset, $slot->y, $layout);
    $bodySpan = $profile === null ? null : $catalog->scale->referenceHeight * $profile->relativeSize * $slot->displayScale;
    $horizontal = $profile?->horizontal ?? false;
    $image = $available ? new CanvasImage(($party ? 'party-' : 'enemy-') . $index, $art->asset,
      $bounds, 100, $art->sourceRect, clipRect: $clip) : null;
    $surface = $available ? BattlerGroundContact::compose(($party ? 'party-contact-' : 'enemy-contact-') . $index, $slot->x, $slot->y,
      $bodySpan ?? $bounds->height, $horizontal, $bounds->width, $elevation, $contact, $layout, $arena, $grounding, $assetRoot,
      $elapsedSeconds, $reducedMotion) : ['images' => [], 'composites' => []];
    return new BattleFormationBattler($id, $party, $slot, $registered === null && !$available ? null : $art,
      $bounds, $image, $bodySpan, $horizontal, $diagnostics, $contact, $surface['images'], $surface['composites'], $offset);
  }
}
