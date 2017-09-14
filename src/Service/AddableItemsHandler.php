<?php

namespace Drupal\entity_layout\Service;

use Drupal\Console\Core\Utils\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\entity_layout\FieldUniqueId;

class AddableItemsHandler implements AddableItemsHandlerInterface {

  /** @var EntityFieldManagerInterface $entityFieldManager */
  protected $entityFieldManager;

  /**
   * AddableItemsHandler constructor.
   *
   * @param EntityFieldManagerInterface $entity_field_manager
   */
  public function __construct(EntityFieldManagerInterface $entity_field_manager) {
    $this->entityFieldManager = $entity_field_manager;
  }
  
  /**
   * Perform alterations in entity form to keep addable items up-to-date
   * over ajax partial submit
   *
   * @param array $form
   * @param FormStateInterface $form_state
   */
  public function processEntityForm(array &$form, FormStateInterface $form_state) {
    // Iterate over $form children and work on all multiple fields except
    // entity_layout fields obviously
    foreach (Element::children($form) as $key) {
      if (
        true === array_key_exists($key, $form['#entity_layout_fields'])
        || false === array_key_exists('widget', $form[$key])
        || false === array_key_exists('#field_name', $form[$key]['widget'])
        || true !== $form[$key]['widget']['#cardinality_multiple']
      ) {
        continue;
      }
      // Grab a reference to the add_more key and work on it
      $add_more = &$form[$key]['widget']['add_more'];
      $add_more_children_keys = Element::children($add_more);
      if (0 !== count($add_more_children_keys)) {
        // if $add_more is mulitple, iterate and alter each single button
        foreach ($add_more_children_keys as $add_more_child_key) {
          $this->alterAddMoreTrigger($add_more[$add_more_child_key]);
        }
      } else {
        // if $add_more is a unique button, simply alter it
        $this->alterAddMoreTrigger($add_more);
      }
    }
  }
  
  /**
   * Alteration function for add more item trigger
   *
   * @param $add_more
   *
   * @return null
   */
  protected function alterAddMoreTrigger(&$add_more) {
    // Ensure we workon a ajax enabled button
    /** @noinspection ReferenceMismatchInspection */
    if (false === array_key_exists('#ajax', $add_more)) {
      
      return null;
    }
    // Store ajax callback in a separate entry then override it
    $add_more['#ajax']['initial_callback'] = $add_more['#ajax']['callback'];
    $add_more['#ajax']['callback'] = [
      get_class($this),
      'addMoreAjaxOverride'
    ];
    
    return null;
  }
  
  /**
   * Overriden addMoreAjax callback
   * See Drupal\core\Field\WidgetBase::addMoreAjax()
   *
   * @param array $form
   * @param FormStateInterface $form_state
   *
   * @return AjaxResponse
   */
  public static function addMoreAjaxOverride(array &$form, FormStateInterface $form_state) {
    // @todo: we need to override any #ajax button in a field widget ...
    // Initialize AjaxResponse
    $response = new AjaxResponse();
    /** @noinspection ReferenceMismatchInspection */
    // First execute the initial callback, and store the result
    $trigger = $form_state->getTriggeringElement();
    $callable = implode('::', $trigger['#ajax']['initial_callback']);
    $element = $callable($form, $form_state);
    // Instead of returning the element, add it to a replacement command
    $initial_replace = new ReplaceCommand(null, $element);
    $response->addCommand($initial_replace);
    // Find all entity_layout_fields in the form and iterate over it
    /** @var array $entity_layout_field */
    $entity_layout_field = $form['#entity_layout_fields'];
    foreach ($entity_layout_field as $field_name) {
      $children = Element::children($form[$field_name]['addable_items']);
      // Foreach existing addable item list, perform a replacement
      foreach ($children as $id) {
        $addable = $form[$field_name]['addable_items'][$id];
        $replace = new ReplaceCommand('#' . $id, $addable);
        $response->addCommand($replace);
      }
    }
    
    return $response;
  }
  
  /**
   * @param FieldableEntityInterface $entity
   * @param array $used
   * @param string $addable_item_id
   * @param FormStateInterface $form_state
   *
   * @throws \LogicException
   *
   * @return array
   */
  public function getAddableItemsElement(FieldableEntityInterface $entity, array $used, $addable_item_id, FormStateInterface $form_state) {
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
        // @todo: In case of widget that use subform (like paragraphs), the
        // very last item is not in the form at this time. May be we should
        // perform this process at after build time ? :(
        $this->buildFieldItemsAddableItemsElement(
          $entity,
          $component,
          $options,
          $options_details,
          $form_state
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
   * @param FormStateInterface $form_state
   *
   * @throws \InvalidArgumentException
   */
  protected function buildFieldItemsAddableItemsElement(FieldableEntityInterface $entity, array $component, array &$options, array &$options_details, FormStateInterface $form_state) {
    $field_definition = $entity->getFieldDefinition($component['id']);
    if (null === $field_definition) {
      
      return;
    }
    $cardinality = $field_definition
      ->getFieldStorageDefinition()
      ->getCardinality();
    /** @noinspection ReferenceMismatchInspection */
    $values = $form_state->getValue($component['id']);
    /** @noinspection ReferenceMismatchInspection */
    if (null !== $values) {
      // Do not count values but actual number of widgets in the form
      // Because some widgets load a default item
      $widget_children = Element::children($form_state->getCompleteForm()[$component['id']]['widget']);
      $item_count = 0;
      foreach ($widget_children as $child) {
        if (is_numeric($child)) {
          $item_count++;
        }
      }
    } else {
      $item_count = $entity->get($component['id'])->count();
    }
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
   * @throws \LogicException
   *
   * @return array
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
