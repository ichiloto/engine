<?php

namespace Ichiloto\Engine\Inn;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Messaging\Dialogue\ConfirmDialogue;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Cutscenes\Presentation\PartyStageSelection;
use InvalidArgumentException;
use stdClass;

/**
 * What an inn asks of the party and where they wake.
 *
 * One reading of the authored inn data serves every way a stay is offered:
 * a sleep trigger in the field and the `inn` script command an NPC runs.
 *
 * @package Ichiloto\Engine\Inn
 */
final readonly class InnOffer
{
  /**
   * @param ConfirmDialogue $confirmDialogue The question the party answers before staying.
   * @param int $cost What the stay costs.
   * @param Vector2|null $spawnPoint Where the party wakes, or null to wake where they stand.
   * @param string[]|null $spawnSprite The player's sprite on waking, or null to keep the current one.
   * @param string|null $backgroundMusic The rest music this inn declares, or null for the project's sleep theme.
   * @param string|PartyStageSelection|null $presentation A stage identity or explicit leader/party treatment; null uses graphics.inn.presentation.
   */
  public function __construct(
    public ConfirmDialogue $confirmDialogue,
    public int $cost = 0,
    public ?Vector2 $spawnPoint = null,
    public ?array $spawnSprite = null,
    public ?string $backgroundMusic = null,
    public string|PartyStageSelection|null $presentation = null,
  )
  {
    if (is_string($presentation)) { EffectTimelineLibrary::assertId($presentation); }
  }

  /**
   * Reads an inn's authored data: `confirmDialogue` (its `text`, and an
   * optional `name` and `face`), and optional `cost`, `spawnPoint` (`x`
   * and `y`), `spawnSprite`, `bgm` and `presentation`. A presentation descriptor
   * declares `treatment` (`leader` or `party`) and its corresponding `leaders`
   * or `parties` bindings; it never implicitly falls back from party to leader.
   *
   * @param array<string, mixed>|object $data The authored data, as an array or decoded object.
   * @throws RequiredFieldException When a required field is missing.
   */
  public static function fromData(array|object $data): self
  {
    $data = (array) $data;
    $confirmDialogue = $data['confirmDialogue'] ?? throw new RequiredFieldException('confirmDialogue');
    $spawnPoint = null;

    if (isset($data['spawnPoint'])) {
      $point = (array) $data['spawnPoint'];
      $spawnPoint = new Vector2(
        $point['x'] ?? throw new RequiredFieldException('spawnPoint.x'),
        $point['y'] ?? throw new RequiredFieldException('spawnPoint.y'),
      );
    }

    $backgroundMusic = trim(strval($data['bgm'] ?? ''));
    $presentation = $data['presentation'] ?? null;
    if ($presentation instanceof stdClass) { $presentation = (array) $presentation; }
    if (is_array($presentation)) {
      // Trigger JSON decoding objectifies records, not the party/actor lists the schema requires.
      if (($presentation['leaders'] ?? null) instanceof stdClass) {
        $presentation['leaders'] = (array) $presentation['leaders'];
      }
      if (is_array($presentation['parties'] ?? null)) {
        $presentation['parties'] = array_map(
          static fn(mixed $party): mixed => $party instanceof stdClass ? (array) $party : $party,
          $presentation['parties'],
        );
      }
      $presentation = PartyStageSelection::fromArray($presentation);
    }
    if ($presentation !== null && !is_string($presentation) && !$presentation instanceof PartyStageSelection) {
      throw new InvalidArgumentException('Inn presentation must be a stable timeline identity, party stage selection or null.');
    }

    return new self(
      ConfirmDialogue::fromArray((array) $confirmDialogue),
      intval($data['cost'] ?? 0),
      $spawnPoint,
      isset($data['spawnSprite']) ? array_values((array) $data['spawnSprite']) : null,
      $backgroundMusic === '' ? null : $backgroundMusic,
      is_string($presentation) ? trim($presentation) : $presentation,
    );
  }
}
