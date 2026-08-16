<?php

namespace Ichiloto\Engine\IO\SaveCompatibility;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\EquipmentSlot;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Scenes\Game\GameConfig;

/** Applies project-declared aliases and tombstones to decoded save state. */
final readonly class SaveContentResolver
{
  public function __construct(
    private SaveCompatibilityManifest $manifest,
    private string $savePath,
  )
  {
  }

  public function resolve(GameConfig $config): GameConfig
  {
    $data = $config->getSaveCompatibilityData();
    $data['mapId'] = $this->identity(ContentReferenceCategory::MAP, $data['mapId'] ?? '');
    $data['events'] = $this->identityList(ContentReferenceCategory::STORY_EVENT, $data['events'] ?? []);
    $data['gameState'] = $this->resolveGameState($data['gameState'] ?? []);
    $data['questLog'] = $this->resolveQuestLog($data['questLog'] ?? []);
    $data['achievements'] = $this->keyedIdentities(
      ContentReferenceCategory::ACHIEVEMENT,
      $data['achievements'] ?? []
    );
    $data['bestiary'] = $this->resolveBestiary($data['bestiary'] ?? []);

    $config->applySaveCompatibilityData($data);
    $this->resolveParty($config);

    return $config;
  }

  /** @param mixed $rawState */
  private function resolveGameState(mixed $rawState): array
  {
    $state = is_array($rawState) ? $rawState : [];
    $state['storyEvents'] = $this->identityList(
      ContentReferenceCategory::STORY_EVENT,
      $state['storyEvents'] ?? []
    );
    $state['visitedMaps'] = $this->keyedIdentities(
      ContentReferenceCategory::MAP,
      $state['visitedMaps'] ?? []
    );

    $completed = [];

    foreach (is_array($state['completedEvents'] ?? null) ? $state['completedEvents'] : [] as $identity => $value) {
      if (! is_string($identity)) {
        continue;
      }

      $completed[$this->manifest->resolveOneShotEvent($identity, $this->savePath)] = $value;
    }

    $state['completedEvents'] = $completed;

    return $state;
  }

  /** @param mixed $rawLog */
  private function resolveQuestLog(mixed $rawLog): array
  {
    $log = is_array($rawLog) ? $rawLog : [];
    $log['active'] = $this->keyedIdentities(ContentReferenceCategory::QUEST, $log['active'] ?? []);
    $log['completed'] = $this->identityList(ContentReferenceCategory::QUEST, $log['completed'] ?? []);

    return $log;
  }

  /** @param mixed $rawBestiary */
  private function resolveBestiary(mixed $rawBestiary): array
  {
    $bestiary = is_array($rawBestiary) ? $rawBestiary : [];
    $bestiary['seen'] = $this->keyedIdentities(ContentReferenceCategory::ENEMY, $bestiary['seen'] ?? []);
    $bestiary['defeated'] = $this->keyedIdentities(ContentReferenceCategory::ENEMY, $bestiary['defeated'] ?? []);

    return $bestiary;
  }

  private function resolveParty(GameConfig $config): void
  {
    foreach ($config->party->inventory->all->toArray() as $item) {
      if ($item instanceof InventoryItem) {
        $this->resolveInventoryItem($item);
      }
    }

    foreach ($config->party->members->toArray() as $member) {
      if (! $member instanceof Character) {
        continue;
      }

      $raw = $member->getDeferredSaveData();

      if (! is_array($raw)) {
        $member->applySaveIdentity($this->identity(ContentReferenceCategory::ACTOR, $member->name));
        continue;
      }

      $raw['name'] = $this->identity(ContentReferenceCategory::ACTOR, $raw['name'] ?? '');
      $raw['summons'] = $this->identityList(ContentReferenceCategory::SUMMON, $raw['summons'] ?? []);
      $raw['abilities'] = $this->resolveBook($raw['abilities'] ?? $raw['abilityBook'] ?? [], false);
      $raw['magic'] = $this->resolveBook($raw['magic'] ?? $raw['spellbook'] ?? [], true);
      $raw['states'] = $this->resolvePersistentStates($raw['states'] ?? []);

      foreach (is_array($raw['equipment'] ?? null) ? $raw['equipment'] : [] as $slot) {
        if ($slot instanceof EquipmentSlot && $slot->equipment instanceof InventoryItem) {
          $this->resolveInventoryItem($slot->equipment);
        }
      }

      $member->completeDeferredSaveHydration($raw);
    }
  }

  private function resolveInventoryItem(InventoryItem $item): void
  {
    $category = $item instanceof Equipment
      ? ContentReferenceCategory::EQUIPMENT
      : ContentReferenceCategory::ITEM;
    $raw = $item->getDeferredSaveData();
    $reference = is_array($raw)
      ? strval($raw['definitionId'] ?? $raw['id'] ?? $raw['name'] ?? '')
      : $item->id;
    $item->applyDefinitionId($this->identity($category, $reference));
    $item->completeDeferredSaveHydration();
  }

  /** @param mixed $rawBook */
  private function resolveBook(mixed $rawBook, bool $isMagic): array
  {
    $book = is_array($rawBook) ? $rawBook : [];
    $skillCategory = $isMagic ? ContentReferenceCategory::SPELL : ContentReferenceCategory::ABILITY;
    $book['learned'] = $this->identityList($skillCategory, $book['learned'] ?? []);
    $learnables = [];

    foreach (is_array($book['learnables'] ?? null) ? $book['learnables'] : [] as $entry) {
      if (! is_array($entry)) {
        continue;
      }

      $entry['skill'] = $this->identity($skillCategory, $entry['skill'] ?? '');
      $requirement = is_array($entry['requirement'] ?? null) ? $entry['requirement'] : [];
      $requirement['itemCosts'] = $this->keyedIdentities(
        ContentReferenceCategory::ITEM,
        $requirement['itemCosts'] ?? []
      );
      $requirement['requiredEvents'] = $this->identityList(
        ContentReferenceCategory::STORY_EVENT,
        $requirement['requiredEvents'] ?? []
      );
      $entry['requirement'] = $requirement;
      $learnables[] = $entry;
    }

    $book['learnables'] = $learnables;

    return $book;
  }

  /** @param mixed $rawStates */
  private function resolvePersistentStates(mixed $rawStates): array
  {
    $states = [];

    foreach (is_array($rawStates) ? $rawStates : [] as $entry) {
      if (! is_array($entry)) {
        $states[] = $entry;
        continue;
      }

      $entry['id'] = $this->identity(ContentReferenceCategory::STATE, $entry['id'] ?? '');
      $states[] = $entry;
    }

    return $states;
  }

  /** @param mixed $identity */
  private function identity(ContentReferenceCategory $category, mixed $identity): string
  {
    return $this->manifest->resolve($category, strval($identity), $this->savePath);
  }

  /** @param mixed $values @return string[] */
  private function identityList(ContentReferenceCategory $category, mixed $values): array
  {
    $resolved = [];

    foreach (is_array($values) ? $values : [] as $value) {
      if (is_string($value)) {
        $resolved[] = $this->identity($category, $value);
      }
    }

    return array_values(array_unique($resolved));
  }

  /** @param mixed $values @return array<string, mixed> */
  private function keyedIdentities(ContentReferenceCategory $category, mixed $values): array
  {
    $resolved = [];

    foreach (is_array($values) ? $values : [] as $identity => $value) {
      if (is_string($identity)) {
        $resolved[$this->identity($category, $identity)] = $value;
      }
    }

    return $resolved;
  }
}
