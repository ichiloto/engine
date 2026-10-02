<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

/**
 * The script commands the Engine registers for every project, declared in
 * the same form a project declares its own.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
final class EngineScriptCommands
{
  public const array DECLARATIONS = [
    [
      'type' => 'shop',
      'class' => ShopCommand::class,
      'label' => 'Shop',
      'description' => 'Opens a shop. The script continues once the player leaves it.',
      'fields' => [
        [
          'key' => 'items',
          'label' => 'Merchandise',
          'kind' => 'list',
          'required' => true,
          'fields' => [
            ['key' => 'item', 'label' => 'Item', 'kind' => 'reference', 'reference' => 'item', 'required' => true],
            [
              'key' => 'price',
              'label' => 'Price',
              'kind' => 'integer',
              'minimum' => 0,
              'description' => "Replaces the item's own price in this shop.",
            ],
          ],
        ],
        [
          'key' => 'buyRate',
          'label' => 'Buy Rate',
          'kind' => 'number',
          'minimum' => 0,
          'description' => 'What the party pays, as a share of each price; 1 when not given.',
        ],
        [
          'key' => 'sellRate',
          'label' => 'Sell Rate',
          'kind' => 'number',
          'minimum' => 0,
          'description' => 'What the party is paid, as a share of each price; 0.5 when not given.',
        ],
      ],
    ],
    [
      'type' => 'inn',
      'class' => InnCommand::class,
      'label' => 'Inn',
      'description' => 'Offers the party a paid rest that restores them, then the script continues.',
      'fields' => [
        ['key' => 'confirmDialogue.text', 'label' => 'Question', 'kind' => 'text', 'required' => true],
        ['key' => 'confirmDialogue.name', 'label' => 'Speaker', 'kind' => 'text'],
        ['key' => 'cost', 'label' => 'Cost', 'kind' => 'integer', 'minimum' => 0],
        [
          'key' => 'spawnPoint',
          'label' => 'Wake At',
          'kind' => 'position',
          'description' => 'Where on this map the party wakes; where they stand when not given.',
        ],
        [
          'key' => 'bgm',
          'label' => 'Rest Music',
          'kind' => 'reference',
          'reference' => 'music',
          'description' => "Played while the party sleeps; the project's sleep theme when not given.",
        ],
        [
          'key' => 'resultVariable',
          'label' => 'Result Variable',
          'kind' => 'text',
          'description' => 'Receives stayed, declined or unaffordable.',
        ],
      ],
    ],
  ];
}
