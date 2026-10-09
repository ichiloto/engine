<?php

namespace Ichiloto\Engine\Entities\Skills;

use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Battle\CounterAttackRule;
use Ichiloto\Engine\Entities\Effects\SkillEffects\AddStateSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDrainSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\ModifyStatStageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPDrainSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPRecoverySkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\RemoveStateSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\SkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Entities\Character;
use InvalidArgumentException;

/**
 * The data form of a skill, as one record file authors it.
 *
 * A record holds plain values only: its kind (an attack, an ability or a
 * spell), what it costs, whom it reaches and when, how it is invoked, and
 * its effects, each a `type` with that type's values. Shared pieces are
 * references: an effect names its element and the states it adds or
 * removes, and a skill names its battle animation by id.
 *
 * Writing a record leaves out values equal to what reading would assume
 * (no target count, a per-hit roll, no animation), so a record states only
 * what its author chose. A spell always states its effect type.
 *
 * @package Ichiloto\Engine\Entities\Skills
 */
final class SkillRecord
{
  /** The keys a record's data may hold. */
  public const array KEYS = [
    'kind', 'name', 'description', 'icon', 'cost', 'cooldown', 'occasion', 'scope', 'invocation', 'effects',
    'animationId', 'effectType', 'counterAttack',
  ];

  /** Each kind's value, by the class it builds. */
  public const array KINDS = [
    'basic' => BasicSkill::class,
    'special' => SpecialSkill::class,
    'magic' => MagicSkill::class,
  ];

  /** The effects a formula drives, by type. */
  public const array FORMULA_EFFECTS = [
    'hp_damage' => HPDamageSkillEffect::class,
    'hp_drain' => HPDrainSkillEffect::class,
    'hp_recover' => HPRecoverSkillEffect::class,
    'mp_damage' => MPDamageSkillEffect::class,
    'mp_drain' => MPDrainSkillEffect::class,
    'mp_recover' => MPRecoverySkillEffect::class,
  ];

  /** The effects that change states and stages, by type. */
  public const array STATE_EFFECTS = [
    'add_state' => AddStateSkillEffect::class,
    'remove_state' => RemoveStateSkillEffect::class,
    'modify_stat_stage' => ModifyStatStageSkillEffect::class,
  ];

  /**
   * Builds a skill from its record data.
   *
   * @param array<string, mixed> $data The record's data.
   * @return Skill The skill.
   * @throws InvalidArgumentException If the data is not a valid skill record.
   */
  public static function readSkill(array $data): Skill
  {
    foreach (array_keys($data) as $key) {
      if (! in_array($key, self::KEYS, true)) {
        throw new InvalidArgumentException(sprintf('Unknown skill record key "%s".', $key));
      }
    }

    $kind = self::requireString($data, 'kind');
    $class = self::KINDS[$kind]
      ?? throw new InvalidArgumentException(sprintf('kind "%s" is not one of %s.', $kind, implode(', ', array_keys(self::KINDS))));
    $arguments = [
      'name' => self::requireString($data, 'name'),
      'description' => self::requireText($data, 'description'),
      'icon' => self::requireText($data, 'icon'),
      'cost' => self::requireInt($data, 'cost'),
      'cooldown' => self::requireInt($data, 'cooldown'),
      'scope' => self::readScope(self::requireArray($data, 'scope')),
      'occasion' => self::readEnum(Occasion::class, self::requireString($data, 'occasion'), 'occasion'),
      'invocation' => self::readInvocation(self::requireArray($data, 'invocation')),
      'effects' => self::readEffects($data['effects'] ?? []),
      'animationId' => self::readOptionalInt($data['animationId'] ?? null, 'animationId'),
    ];
    $counterAttack = CounterAttackRule::fromArray($data['counterAttack'] ?? null);
    if ($counterAttack !== null && $class === MagicSkill::class) {
      throw new InvalidArgumentException('counterAttack grants belong to non-magic abilities, not spells.');
    }
    if (array_key_exists('effectType', $data)) {
      if ($class !== MagicSkill::class) {
        throw new InvalidArgumentException('effectType belongs to a spell; this skill is not one.');
      }

      return new MagicSkill(...[
        ...$arguments,
        'effectType' => self::readEnum(MagicEffectType::class, self::requireString($data, 'effectType'), 'effectType'),
      ]);
    }

    if ($class === MagicSkill::class) { return new MagicSkill(...$arguments); }
    return new $class(...[...$arguments, 'counterAttack' => $counterAttack]);
  }

  /**
   * Returns a skill's record data.
   *
   * @param Skill $skill The skill.
   * @return array<string, mixed> The record's data.
   * @throws InvalidArgumentException When the skill holds what a record cannot (a required weapon, an effect of an unknown type).
   */
  public static function writeSkill(Skill $skill): array
  {
    if ($skill->requiredWeapons !== []) {
      throw new InvalidArgumentException(sprintf('%s requires weapons, which a skill record cannot name yet.', $skill->name));
    }

    $kind = array_search($skill::class, self::KINDS, true)
      ?: throw new InvalidArgumentException(sprintf('%s is a %s, which is not a skill kind a record names.', $skill->name, $skill::class));
    $data = [
      'kind' => $kind,
      'name' => $skill->name,
      'description' => $skill->description,
      'icon' => $skill->icon,
      'cost' => $skill->cost,
      'cooldown' => $skill->cooldown,
      'occasion' => $skill->occasion->value,
      'scope' => self::writeScope($skill->scope),
      'invocation' => self::writeInvocation($skill->invocation),
      'effects' => array_map(self::writeEffect(...), $skill->effects),
    ];

    if ($skill->animationId !== null) {
      $data['animationId'] = $skill->animationId;
    }
    if ($skill->counterAttack !== null) { $data['counterAttack'] = $skill->counterAttack->toArray(); }

    if ($skill instanceof MagicSkill && $skill->effectType !== null) {
      $data['effectType'] = $skill->effectType->value;
    }

    return $data;
  }

  /**
   * @param array<string, mixed> $scope
   */
  private static function readScope(array $scope): ItemScope
  {
    return new ItemScope(
      self::readEnum(ItemScopeSide::class, self::requireString($scope, 'side', 'scope.'), 'scope.side'),
      self::readEnum(ItemScopeNumber::class, self::requireString($scope, 'number', 'scope.'), 'scope.number'),
      self::readEnum(ItemScopeStatus::class, self::requireString($scope, 'status', 'scope.'), 'scope.status'),
      self::readOptionalInt($scope['targetCount'] ?? null, 'scope.targetCount'),
    );
  }

  /** @return array<string, mixed> */
  private static function writeScope(ItemScope $scope): array
  {
    $data = ['side' => $scope->side->value, 'number' => $scope->number->value, 'status' => $scope->status->value];

    if ($scope->targetCount !== null) {
      $data['targetCount'] = $scope->targetCount;
    }

    return $data;
  }

  /**
   * @param array<string, mixed> $invocation
   */
  private static function readInvocation(array $invocation): SkillInvocation
  {
    return new SkillInvocation(
      self::requireText($invocation, 'message', 'invocation.'),
      self::requireInt($invocation, 'speed', 'invocation.'),
      self::requireInt($invocation, 'accuracy', 'invocation.'),
      self::requireInt($invocation, 'repeat', 'invocation.'),
      self::requireInt($invocation, 'apGain', 'invocation.'),
      array_key_exists('hitScope', $invocation)
        ? self::readEnum(SkillResolutionScope::class, strval($invocation['hitScope']), 'invocation.hitScope')
        : SkillResolutionScope::PER_HIT,
      array_key_exists('criticalScope', $invocation)
        ? self::readEnum(SkillResolutionScope::class, strval($invocation['criticalScope']), 'invocation.criticalScope')
        : SkillResolutionScope::PER_HIT,
    );
  }

  /** @return array<string, mixed> */
  private static function writeInvocation(SkillInvocation $invocation): array
  {
    $data = [
      'message' => $invocation->message,
      'speed' => $invocation->speed,
      'accuracy' => $invocation->accuracy,
      'repeat' => $invocation->repeat,
      'apGain' => $invocation->apGain,
    ];

    if ($invocation->hitScope !== SkillResolutionScope::PER_HIT) {
      $data['hitScope'] = $invocation->hitScope->value;
    }

    if ($invocation->criticalScope !== SkillResolutionScope::PER_HIT) {
      $data['criticalScope'] = $invocation->criticalScope->value;
    }

    return $data;
  }

  /**
   * @return list<SkillEffect>
   */
  private static function readEffects(mixed $effects): array
  {
    if (! is_array($effects) || ! array_is_list($effects)) {
      throw new InvalidArgumentException('effects must be a list.');
    }

    $read = [];

    foreach ($effects as $index => $effect) {
      $path = sprintf('effects.%d.', $index);

      if (! is_array($effect)) {
        throw new InvalidArgumentException(sprintf('%s must be an effect.', rtrim($path, '.')));
      }

      $read[] = self::readEffect($effect, $path);
    }

    return $read;
  }

  /**
   * @param array<string, mixed> $effect
   */
  private static function readEffect(array $effect, string $path): SkillEffect
  {
    $type = self::requireString($effect, 'type', $path);

    if (isset(self::FORMULA_EFFECTS[$type])) {
      $class = self::FORMULA_EFFECTS[$type];
      self::refuseUnknownKeys($effect, ['type', 'formula', 'element', 'variance', 'isCriticalHit', ...($type === 'hp_damage' ? ['resolutionKind'] : [])], $path);
      $variance = $effect['variance'] ?? 0.2;

      if (! is_int($variance) && ! is_float($variance)) {
        throw new InvalidArgumentException(sprintf('%svariance must be a number.', $path));
      }

      $isCriticalHit = $effect['isCriticalHit'] ?? false;

      if (! is_bool($isCriticalHit)) {
        throw new InvalidArgumentException(sprintf('%sisCriticalHit must be true or false.', $path));
      }

      $arguments = [
        self::requireString($effect, 'formula', $path),
        self::readOptionalString($effect['element'] ?? null, $path . 'element'),
        floatval($variance),
        $isCriticalHit,
      ];

      if (array_key_exists('resolutionKind', $effect)) {
        $arguments[] = self::readEnum(ResolutionKind::class, strval($effect['resolutionKind']), $path . 'resolutionKind');
      }

      return new $class(...$arguments);
    }

    return match ($type) {
      'add_state' => (static function () use ($effect, $path): AddStateSkillEffect {
        self::refuseUnknownKeys($effect, ['type', 'stateId', 'chancePercent'], $path);

        return new AddStateSkillEffect(
          self::requireString($effect, 'stateId', $path),
          array_key_exists('chancePercent', $effect) ? self::requireInt($effect, 'chancePercent', $path) : 100,
        );
      })(),
      'remove_state' => (static function () use ($effect, $path): RemoveStateSkillEffect {
        self::refuseUnknownKeys($effect, ['type', 'stateIds'], $path);
        $stateIds = $effect['stateIds'] ?? null;

        if (! is_array($stateIds) || $stateIds === [] || ! array_is_list($stateIds)
          || array_filter($stateIds, static fn(mixed $id): bool => ! is_string($id) || trim($id) === '') !== []) {
          throw new InvalidArgumentException(sprintf('%sstateIds must list the states it removes.', $path));
        }

        return new RemoveStateSkillEffect($stateIds);
      })(),
      'modify_stat_stage' => (static function () use ($effect, $path): ModifyStatStageSkillEffect {
        self::refuseUnknownKeys($effect, ['type', 'stat', 'delta', 'affectsUser'], $path);
        $stat = self::requireString($effect, 'stat', $path);

        if (! in_array($stat, Character::buffableStats(), true)) {
          throw new InvalidArgumentException(sprintf('%sstat "%s" is not one of %s.', $path, $stat, implode(', ', Character::buffableStats())));
        }

        $affectsUser = $effect['affectsUser'] ?? false;

        if (! is_bool($affectsUser)) {
          throw new InvalidArgumentException(sprintf('%saffectsUser must be true or false.', $path));
        }

        return new ModifyStatStageSkillEffect($stat, self::requireInt($effect, 'delta', $path), $affectsUser);
      })(),
      default => throw new InvalidArgumentException(sprintf(
        '%stype "%s" is not one of %s.',
        $path,
        $type,
        implode(', ', [...array_keys(self::FORMULA_EFFECTS), ...array_keys(self::STATE_EFFECTS)]),
      )),
    };
  }

  /** @return array<string, mixed> */
  private static function writeEffect(SkillEffect $effect): array
  {
    $type = array_search($effect::class, self::FORMULA_EFFECTS, true);

    if ($type !== false) {
      $data = ['type' => $type, 'formula' => $effect->formula];

      if ($effect->element !== null) {
        $data['element'] = $effect->element;
      }

      $data['variance'] = $effect->variance;

      if ($effect->isCriticalHit) {
        $data['isCriticalHit'] = true;
      }

      if ($effect instanceof HPDamageSkillEffect && $effect->resolutionKind !== null) {
        $data['resolutionKind'] = $effect->resolutionKind->value;
      }

      return $data;
    }

    return match (true) {
      $effect instanceof AddStateSkillEffect => array_filter(
        ['type' => 'add_state', 'stateId' => $effect->stateId, 'chancePercent' => $effect->chancePercent],
        static fn(mixed $value, string $key): bool => $key !== 'chancePercent' || $value !== 100,
        ARRAY_FILTER_USE_BOTH,
      ),
      $effect instanceof RemoveStateSkillEffect => ['type' => 'remove_state', 'stateIds' => array_values(array_map('strval', $effect->stateIds))],
      $effect instanceof ModifyStatStageSkillEffect => array_filter(
        ['type' => 'modify_stat_stage', 'stat' => $effect->stat, 'delta' => $effect->delta, 'affectsUser' => $effect->affectsUser],
        static fn(mixed $value, string $key): bool => $key !== 'affectsUser' || $value === true,
        ARRAY_FILTER_USE_BOTH,
      ),
      default => throw new InvalidArgumentException(sprintf('%s is not an effect a skill record can name.', $effect::class)),
    };
  }

  /**
   * @template T of \BackedEnum
   * @param class-string<T> $enum
   * @return T
   */
  private static function readEnum(string $enum, string $value, string $key): \BackedEnum
  {
    return $enum::tryFrom($value) ?? throw new InvalidArgumentException(sprintf(
      '%s "%s" is not one of %s.',
      $key,
      $value,
      implode(', ', array_map(static fn(\BackedEnum $case): string => strval($case->value), $enum::cases())),
    ));
  }

  /**
   * @param array<string, mixed> $data
   * @param list<string> $keys
   */
  private static function refuseUnknownKeys(array $data, array $keys, string $path): void
  {
    foreach (array_keys($data) as $key) {
      if (! in_array($key, $keys, true)) {
        throw new InvalidArgumentException(sprintf('Unknown key "%s%s" for a %s effect.', $path, $key, strval($data['type'])));
      }
    }
  }

  private static function readOptionalInt(mixed $value, string $key): ?int
  {
    if ($value !== null && ! is_int($value)) {
      throw new InvalidArgumentException(sprintf('%s must be a whole number.', $key));
    }

    return $value;
  }

  private static function readOptionalString(mixed $value, string $key): ?string
  {
    if ($value !== null && ! is_string($value)) {
      throw new InvalidArgumentException(sprintf('%s must be text.', $key));
    }

    return $value;
  }

  /**
   * @param array<string, mixed> $data
   */
  private static function requireString(array $data, string $key, string $path = ''): string
  {
    $value = $data[$key] ?? null;

    if (! is_string($value) || trim($value) === '') {
      throw new InvalidArgumentException(sprintf('%s%s is required text.', $path, $key));
    }

    return $value;
  }

  /**
   * Text that may be empty, such as a skill with no icon.
   *
   * @param array<string, mixed> $data
   */
  private static function requireText(array $data, string $key, string $path = ''): string
  {
    $value = $data[$key] ?? null;

    if (! is_string($value)) {
      throw new InvalidArgumentException(sprintf('%s%s must be text.', $path, $key));
    }

    return $value;
  }

  /**
   * @param array<string, mixed> $data
   */
  private static function requireInt(array $data, string $key, string $path = ''): int
  {
    $value = $data[$key] ?? null;

    if (! is_int($value)) {
      throw new InvalidArgumentException(sprintf('%s%s must be a whole number.', $path, $key));
    }

    return $value;
  }

  /**
   * @param array<string, mixed> $data
   * @return array<string, mixed>
   */
  private static function requireArray(array $data, string $key): array
  {
    $value = $data[$key] ?? null;

    if (! is_array($value)) {
      throw new InvalidArgumentException(sprintf('%s is required.', $key));
    }

    return $value;
  }
}
