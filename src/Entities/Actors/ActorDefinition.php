<?php

namespace Ichiloto\Engine\Entities\Actors;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats\StatKey;
use Ichiloto\Engine\Exceptions\UnresolvedSaveReferenceException;
use InvalidArgumentException;

/**
 * One project-owned actor definition and its optional natural variants.
 *
 * Fixed natural values remain in project data. Saves retain only the selected
 * variant identity and genuine mutable character state.
 */
final class ActorDefinition
{
  /** @var array<string, int> */
  private array $fixedNaturalAdjustments;
  /** @var array<string, array<string, int>> */
  private array $naturalVariants;

  /**
   * @param array<string, mixed> $data Canonical actor data block.
   */
  public function __construct(
    public string $id,
    private array $data,
    public ?string $defaultNaturalVariantId = null,
    array $fixedNaturalAdjustments = [],
    array $naturalVariants = [],
    public string $source = 'project actor definition',
  )
  {
    $id = trim($id);

    if ($id === '') {
      throw new InvalidArgumentException(sprintf('%s has an empty actor identity.', $source));
    }

    $this->id = $id;

    $this->fixedNaturalAdjustments = self::normalizeAdjustments($fixedNaturalAdjustments, $source);
    $this->naturalVariants = [];

    foreach ($naturalVariants as $variantId => $adjustments) {
      $variantId = is_string($variantId) ? trim($variantId) : '';

      if ($variantId === '' || ! is_array($adjustments)) {
        throw new InvalidArgumentException(sprintf('%s has a malformed natural variant.', $source));
      }

      $this->naturalVariants[$variantId] = self::normalizeAdjustments(
        is_array($adjustments['adjustments'] ?? null) ? $adjustments['adjustments'] : $adjustments,
        sprintf('%s variant %s', $source, $variantId),
      );
    }

    if ($this->naturalVariants !== []) {
      $default = trim(strval($defaultNaturalVariantId));

      if ($default === '' || ! isset($this->naturalVariants[$default])) {
        throw new InvalidArgumentException(sprintf(
          '%s must declare a valid defaultNaturalVariantId when natural variants exist.',
          $source,
        ));
      }

      $this->defaultNaturalVariantId = $default;
    } elseif ($defaultNaturalVariantId !== null && trim($defaultNaturalVariantId) !== '') {
      throw new InvalidArgumentException(sprintf(
        '%s declares default natural variant "%s" without defining naturalVariants.',
        $source,
        $defaultNaturalVariantId,
      ));
    }
  }

  /** @param array<string, mixed> $actorFile */
  public static function fromArray(array $actorFile, string $source = 'project actor definition'): self
  {
    $data = is_array($actorFile['data'] ?? null) ? $actorFile['data'] : $actorFile;
    $id = trim(strval($data['id'] ?? $data['name'] ?? ''));

    return new self(
      $id,
      $data,
      isset($data['defaultNaturalVariantId']) ? strval($data['defaultNaturalVariantId']) : null,
      is_array($data['actorNaturalAdjustments'] ?? null) ? $data['actorNaturalAdjustments'] : [],
      is_array($data['naturalVariants'] ?? null) ? $data['naturalVariants'] : [],
      $source,
    );
  }

  /**
   * Builds a character from current project data, then restores only mutable
   * save state. This is the authoritative actor reconstruction boundary.
   *
   * @param array<string, mixed> $savedState
   */
  public function createCharacter(array $savedState = [], ?string $savePath = null): Character
  {
    $variantId = $this->resolveVariantId($savedState, $savePath);
    $data = $this->data;
    $data['actorId'] = $this->id;
    $data['actorNaturalAdjustments'] = $this->naturalAdjustmentsFor($variantId);
    $data['naturalVariantId'] = $variantId;

    $character = Character::fromArray($data);

    if ($savedState !== []) {
      $character->restoreMutableState($savedState);
    }

    return $character;
  }

  /** @return array<string, mixed> */
  public function data(): array
  {
    return $this->data;
  }

  /** @return array<string, int> */
  public function naturalAdjustmentsFor(?string $variantId): array
  {
    $adjustments = $this->fixedNaturalAdjustments;

    foreach ($variantId !== null ? $this->naturalVariants[$variantId] ?? [] : [] as $stat => $amount) {
      $adjustments[$stat] = ($adjustments[$stat] ?? 0) + $amount;
    }

    return $adjustments;
  }

  /** @param array<string, mixed> $savedState */
  private function resolveVariantId(array $savedState, ?string $savePath): ?string
  {
    $savedVariant = array_key_exists('naturalVariantId', $savedState)
      ? trim(strval($savedState['naturalVariantId']))
      : '';
    $variantId = $savedVariant !== '' ? $savedVariant : $this->defaultNaturalVariantId;

    if ($variantId !== null && ! isset($this->naturalVariants[$variantId])) {
      throw new UnresolvedSaveReferenceException(sprintf(
        'Actor "%s" references unknown natural variant "%s" while loading save %s.',
        $this->id,
        $variantId,
        $savePath ?? '[new game]',
      ));
    }

    return $variantId;
  }

  /** @param array<string, mixed> $adjustments @return array<string, int> */
  private static function normalizeAdjustments(array $adjustments, string $source): array
  {
    $normalized = [];

    foreach ($adjustments as $key => $amount) {
      $stat = StatKey::require(strval($key));

      if (! is_int($amount)) {
        throw new InvalidArgumentException(sprintf(
          '%s actor-natural adjustment for %s must be an integer.',
          $source,
          $stat->value,
        ));
      }

      $normalized[$stat->value] = $amount;
    }

    return $normalized;
  }
}
