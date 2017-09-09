<?php

namespace Drupal\entity_layout\Service;

use Drupal\Console\Core\Utils\NestedArray;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\entity_layout\FieldUniqueId;

class AddableItemsHandler implements AddableItemsHandlerInterface {

  /** @var EntityFieldManagerInterface $entityFieldManager */
  protected $entityFieldManager;

  /**
   * AddableItemsHandler constructor.
   * @param EntityFieldManagerInterface $entity_field_manager
   */
  public function __construct(EntityFieldManagerInterface $entity_field_manager) {
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * @param FieldableEntityInterface $entity
   * @param array $used
   * @param $addable_item_id
   *
   * @throws \LogicException
   * @throws \InvalidArgumentException
   *
   * @return array
   */
  public function getAddableItemsElement(FieldableEntityInterface $entity, array $used, $addable_item_id) {
    $components = $this->getRootComponents($entity);
    $component_list = [
      '#type' => 'radios',
      '#options' => [],
      '#options_details' => [],
    ];
    /** @var array $options */
    $options = &$component_list['#options'];
    $options_details = &$component_list['#options_details'];
    foreach ($components as $id => $component) {
      $options[$id] = $id;
      $options_details[$id] = [
        'id' => $id,
        'delta' => null,
      ];
      if ('field' === $component['type']) {
        $this->buildFieldItemsAddableItemsElement(
          $entity,
          $component,
          $options,
          $options_details
        );
      }
    }

    foreach ($options as $id => $label) {
      if (array_key_exists($id, $used)) {
        $component_list[$id] = [
          '#disabled' => true,
        ];
      }
    }

    return $component_list;
  }

  /**
   * @param FieldableEntityInterface $entity
   * @param array $component
   * @param array $options
   * @param array $options_details
   *
   * @throws \InvalidArgumentException
   */
  protected function buildFieldItemsAddableItemsElement(FieldableEntityInterface $entity, array $component, array &$options, array &$options_details) {
    $item_count = $entity->get($component['id'])->count();
    $cardinality = $entity->getFieldDefinition($component['id'])
      ->getFieldStorageDefinition()
      ->getCardinality();
    if (
      1 === $cardinality
      || $item_count < 2
    ) {
      return;
    }
    $delta = 0;
    while ($delta < $item_count) {
      $item_id = $component['id'] . ':' . $delta;
      // @todo: move EntityLayoutBasicFieldWidget::getItemLabel() in utilities and use it here
      $options[$item_id] = '-- ' . $item_id;
      $options_details[$item_id] = [
        'id' => $item_id,
        'delta' => $delta,
      ];
      $delta++;
    }
  }

  /**
   * @param FieldableEntityInterface $entity
   *
   * @return array
   * @throws \LogicException
   */
  protected function getRootComponents(FieldableEntityInterface $entity) {
    $entity_type = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    $field_definitions = $this->entityFieldManager->getFieldDefinitions(
      $entity_type,
      $bundle
    );
    $extra_fields = $this->entityFieldManager->getExtraFields(
      $entity_type,
      $bundle
    )['display'];
    $component_definitions = array_merge(
      $field_definitions,
      $extra_fields
    );
    $components = [];
    foreach ($component_definitions as $id => $definition) {
      $label = $id;
      $type = 'extrafield';
      if (false === is_array($definition)) {
        /** @var FieldDefinitionInterface $definition */
        if (
          false === $definition->isDisplayConfigurable('view')
          || 'entity_layout_field_type' === $definition->getType()
        ) {
          continue;
        }
        $type = 'field';
      } else {
        $label = $definition['label'];
      }
      $components[$id] = [
        'id' => $id,
        'label' => $label,
        'type' => $type,
      ];
    }

    return $components;
  }

  /**
   * Build a list of used addable items in the current dataset, from the model,
   * or from the request in case of ajax rebuild.
   *
   * @param FieldItemListInterface $items
   * @param FormStateInterface $form_state
   *
   * @return array
   */
  public function getUsedAddableItems(FieldItemListInterface $items, FormStateInterface $form_state) {
    $field_name = $items->getFieldDefinition()->getName();
    $used = [];
    /** @noinspection ReferenceMismatchInspection */
    $field_values = $form_state->getValue($field_name);
    if (null !== $field_values) {
      /** @var array $field_values */
      foreach ($field_values as $delta => $item) {
        if (true === array_key_exists('regions', $item)) {
          $this->addUsedAddableItem($used, $item['regions']);
        }
      }
      /** @noinspection ReferenceMismatchInspection */
      $trigger = $form_state->getTriggeringElement();
      switch ($trigger['#action']) {

        case 'add_item':
          $addable_items_id = FieldUniqueId::getUniqueId(
            $items->getFieldDefinition(),
            'addable-items'
          );
          /** @noinspection ReferenceMismatchInspection */
          $addable_items_values = $form_state->getValue('addable_items');
          $added_item_id = $addable_items_values[$addable_items_id];
          // Add current added_item to used
          $used[$added_item_id] = $added_item_id;
          break;

        case 'remove_item':
          // Remove current item from used
          unset($used[$trigger['#item_id']]);
          break;

        case 'change_layout':
          foreach ($field_values as $delta => $field_item) {
            if (false === array_key_exists('layout', $field_item)) {
              continue;
            }
            if (
              true === array_key_exists('regions', $field_item)
              && '' === $field_item['layout']
            ) {
              /** @var array $regions */
              $regions = $field_item['regions'];
              foreach ($regions as $item_id => $item) {
                if (true === array_key_exists('region', $item)) {
                  unset($used[$item_id]);
                }
              }
            }
          }
          break;
      }
    } else {
      foreach ($items as $delta => $item) {
        $item = $items[$delta];
        if (
          isset($item->regions)
          && '' !== $item->regions
        ) {
          $this->addUsedAddableItem($used, $item->regions);
        }
      }
    }

    return $used;
  }

  /**
   * Simply add an element to used addable items list.
   *
   * @param array $used
   * @param array $addable_items
   */
  protected function addUsedAddableItem(array &$used, array $addable_items) {
    foreach ($addable_items as $item) {
      /** @noinspection ReferenceMismatchInspection */
      if (
        false === array_key_exists('region', $item)
        || true === array_key_exists($item['id'], $used)
      ) {
        continue;
      }
      $used[$item['id']] = $item['id'];
    }
  }

  /**
   * Return a reference to an up-to-date addable_items list
   *
   * @param array $trigger
   * @param array $form
   * @param $id
   *
   * @return array|null
   */
  public function grabAddableItemsElement(array $trigger, array $form, $id) {
    if (false === array_key_exists('#action', $trigger)) {

      return null;
    }
    $addable_items_form_path = null;
    switch($trigger['#action']) {
      case 'add_item':
      case 'remove_item':
      case 'change_layout':
        $addable_items_form_index = array_search(
          'widget',
          $trigger['#array_parents'],
          true
        );
        $addable_items_form_path = array_splice(
          $trigger['#array_parents'],
          0,
          $addable_items_form_index
        );
        $addable_items_form_path[] = 'addable_items';
        $addable_items_form_path[] = $id;
        break;
    }
    if (null === $addable_items_form_path) {
      return null;
    }
    /** @noinspection ReferenceMismatchInspection */
    $addable_items = NestedArray::getValue(
      $form,
      $addable_items_form_path
    );

    return $addable_items;
  }
}
