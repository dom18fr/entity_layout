<?php

namespace Drupal\entity_layout\Plugin\Field\FieldWidget;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Utility\Html;
use Drupal\Console\Core\Utils\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Field\Annotation\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

use /** @noinspection PhpInternalEntityUsedInspection */
  Drupal\Core\Layout\LayoutDefinition;
use /** @noinspection PhpInternalEntityUsedInspection */
  Drupal\Core\Layout\LayoutPluginManagerInterface;

use Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

/**
 * Plugin implementation of the 'entity_layout_basic_field_widget' widget.
 *
 * @FieldWidget(
 *   id = "entity_layout_basic_field_widget",
 *   label = @Translation("Entity layout basic field widget"),
 *   field_types = {
 *     "entity_layout_field_type"
 *   }
 * )
 */
class EntityLayoutBasicFieldWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  /** @var array $layoutPlugins  */
  protected $layoutPlugins;

  /** @var EntityTypeManagerInterface $entityTypeManager */
  protected $entityTypeManager;

  /** @var  EntityFieldManagerInterface $entityFieldManager */
  protected $entityFieldManager;

  /**
   * @param ContainerInterface $container
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   *
   * @throws ServiceNotFoundException
   * @throws ServiceCircularReferenceException
   *
   * @return static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var LayoutPluginManagerInterface $layout_plugin_manager */
    $layout_plugin_manager = $container->get('plugin.manager.core.layout');
    /** @var EntityTypeManagerInterface $entity_type_manager */
    $entity_type_manager = $container->get('entity_type.manager');
    /** @var EntityFieldManagerInterface $entity_field_manager */
    $entity_field_manager = $container->get('entity_field.manager');

    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $layout_plugin_manager,
      $entity_type_manager,
      $entity_field_manager
    );
  }

  /**
   * EntityLayoutBasicFieldWidget constructor.
   *
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param FieldDefinitionInterface $field_definition
   * @param array $settings
   * @param array $third_party_settings
   * @param LayoutPluginManagerInterface $layout_plugins_manager
   * @param EntityTypeManagerInterface $entity_type_manager
   * @param EntityFieldManagerInterface $entity_field_manager
   * @internal param \Drupal\Core\Layout\LayoutPluginManagerInterface $layout_plugins_manager
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, /** @noinspection PhpInternalEntityUsedInspection */ LayoutPluginManagerInterface $layout_plugins_manager, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $third_party_settings
    );
    $this->layoutPlugins = $layout_plugins_manager->getDefinitions();
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \LogicException
   * @throws \InvalidArgumentException
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    // Get full field definition and field name
    /** @var FieldDefinitionInterface $field_definition */
    $field_definition = $items->getFieldDefinition();
    $field_name = $field_definition->getName();
    // Get current layout id and regions state
    list($layout_id, $regions) = $this->getElementValues(
      $field_name,
      $items,
      $delta,
      $form_state
    );
    // Create an id to link layout select with region wrapper.
    // We use Html::getId() instead of Html::getUniqueId() to get it match
    // between a rebuilt select and a not yet rebuilt region wrapper.
    $regions_wrapper_id = Html::getId(
      implode(
        '-',
        [
          'entity-layout',
          $field_definition->getTargetEntityTypeId(),
          $field_definition->getTargetBundle(),
          $field_name,
          $delta,
          'regions-wpr',
        ]
      )
    );
    $form['#after_build'][] = [$this, 'formElementAfterBuildCallback'];
    // Create layout select. This is the first column in the field schema.
    // Regions mapping have to be rebuilt when layout changes since each layout
    // has its own regions. So we add #ajax to manage on the fly replacement and
    // a custom #regions_wrapper_key to be able to target the right region at
    // replacement time.
    $element['layout'] = [
      '#type' => 'select',
      '#options' => $this->getCategorizedLayoutList(),
      '#default_value' => $layout_id,
      '#empty_option' => '-- ' . $this->t('Choose a layout') . ' --',
      '#ajax' => [
        'event' => 'change',
        'callback' => [$this, 'regionsReplaceAjaxCallback'],
      ],
      '#regions_wrapper_id' => $regions_wrapper_id,
      '#action' => 'change_layout'
    ];
    // Create regions and items tabledrag
    if (false === array_key_exists($layout_id, $this->layoutPlugins)) {
      // If no layout is selected, just render a placeholder where regions
      // will be rendered at ajax replacement time
      $element['regions'] = [
        '#markup' => '<div id="' . $regions_wrapper_id . '"></div>'
      ];
      // @todo: create a same placeholder for addable items list and manage ajax replacement of it
      return $element;
    }
    $element['regions'] = $this->getRegionsTableElement(
      $this->layoutPlugins[$layout_id],
      $regions,
      $regions_wrapper_id
    );
    $element['addable_items'] = $this->getAddableItemsElement(
      $items,
      $delta,
      $form_state,
      $regions_wrapper_id
    );
    // @todo: alter addable_items based on form_state in case of ajax rebuilding
    return $element;
  }

  /**
   * After build callback for entity form. Used to add a validate handler at
   * the very end of the list
   *
   * @param $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @return mixed
   */
  public function formElementAfterBuildCallback($form, FormStateInterface $form_state) {
    $form['#validate']['entity_layout_basic_widget_validator'] = [
      get_class($this),
      'addItemValidateCallback',
    ];

    return $form;
  }

  /**
   * Validate handler for entity form, called after any other it ensure
   * no data validation problem could prevent added item ajax processing.
   *
   * @param $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public static function addItemValidateCallback(&$form, FormStateInterface $form_state) {
    /** @noinspection ReferenceMismatchInspection */
    $trigger = $form_state->getTriggeringElement();
    if (
      false === array_key_exists('#action', $trigger)
      || 'add_item' !== $trigger['#action']
    ) {
      return;
    }
    $form_state->clearErrors();
  }

  /**
   * @param FieldItemListInterface $items
   * @param $delta
   * @param FormStateInterface $form_state
   * @param $regions_wrapper_id
   *
   * @throws \LogicException
   *
   * @return array
   */
  protected function getAddableItemsElement(FieldItemListInterface $items, $delta, FormStateInterface $form_state, $regions_wrapper_id) {
    /** @var FieldableEntityInterface $entity */
    $entity = $items->getEntity();
    $components = $this->getRootComponents($entity);
    $component_list = [
      '#type' => 'radios',
      '#options' => [],
      '#options_details' => [],
      '#name' => 'addable-items-' . $regions_wrapper_id,
      '#id' => 'addable-items-' . $regions_wrapper_id,
    ];
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
        ); // @todo: rethink naming, dual signification of "item" is confusing
      }
    }
    ksm($component_list);
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
      $options[$item_id] = $item_id;
      $options_details[$item_id] = [
        'id' => $item_id,
        'delta' => $delta,
      ];
      $delta++;
    }
  }

  /**
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *
   * @return array
   * @throws \LogicException
   */
  protected function getRootComponents(FieldableEntityInterface $entity) {
    // @todo: fieldgroup compatibility ?
    // @todo: field_layout compatibility ? (is it really usefull ?)
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
   * Get current layout id and regions values, directly from the model or based
   * on current ajax $form_state if needed.
   *
   * @param $field_name
   * @param FieldItemListInterface $items
   * @param $delta
   * @param FormStateInterface $form_state
   *
   * @return array
   */
  protected function getElementValues($field_name, FieldItemListInterface $items, $delta, FormStateInterface $form_state) {
    // Initialize layout id
    $layout_id = null;
    // Try to get a layout_id & regions from $form_state, if this is not null
    // it means the field values have just been changed, so we are in case of an ajax rebuild.
    // Else layout_id and regions  is retrieved from the model, using $items.
    if (null !== $form_state->getValue($field_name)) {
      $layout_id = $form_state->getValue($field_name)[$delta]['layout'];
      $regions = $form_state->getValue($field_name)[$delta]['regions'];
      if (null === $regions || '' === $regions) {
        $regions = [];
      }
    } else {
      $item = $items[$delta];
      $layout_id = isset($item->layout) ? $item->layout : null;
      $regions = isset($item->regions) && '' !== $item->regions ? $item->regions : [];
    }
    // Add item from ajax request in region if needed
    $this->buildAjaxAddedItem($regions, $items, $delta, $form_state);

    return [$layout_id, $regions];
  }

  /**
   * @param $regions
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   * @param $delta
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return null
   */
  protected function buildAjaxAddedItem(&$regions, FieldItemListInterface $items, $delta, FormStateInterface $form_state) {
    /** @noinspection ReferenceMismatchInspection */
    $trigger = $form_state->getTriggeringElement();
    if (
      null === $trigger
      || false === array_key_exists('#action', $trigger)
      || 'add_item' !== $trigger['#action']
    ) {

      return null;
    }
    $item_details = $this->getAddedItemDetails(
      $trigger,
      $items,
      $delta,
      $form_state
    );
    $region_id = $trigger['#region_id'];
    /** @noinspection ReferenceMismatchInspection */
    $region_index = array_search($region_id, array_keys($regions), true) + 1;
    $added_item = [
      'id' => $item_details['id'],
      'delta' => $item_details['delta'],
      'weight' => 0,
      'region' => $region_id,
    ];
    /** @noinspection ReferenceMismatchInspection */
    $regions = array_slice($regions, 0, $region_index, true) +
    array($item_details['id'] => $added_item) +
    array_slice($regions, $region_index, count($regions) - 1, true) ;
  }

  /**
   * @param array $trigger
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   * @param $delta
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return array
   */
  protected function getAddedItemDetails(array $trigger, FieldItemListInterface $items, $delta, FormStateInterface $form_state) {
    $regions_wrapper_state_index = array_search(
      'regions',
      $trigger['#parents'],
      true
    );
    $regions_wrapper_state_path = array_splice(
      $trigger['#parents'],
      0,
      $regions_wrapper_state_index
    );
    $addable_items_state_path = $regions_wrapper_state_path;
    $addable_items_state_path[] = 'addable_items';
    /** @noinspection ReferenceMismatchInspection */
    $addable_item_id = NestedArray::getValue(
      $form_state->getValues(),
      $addable_items_state_path
    );
    $regions_wrapper_form_index = array_search(
      'regions',
      $trigger['#array_parents'],
      true
    );
    $regions_wrapper_form_path = array_splice(
      $trigger['#array_parents'],
      0,
      $regions_wrapper_form_index
    );
    $added_item_form_path = $regions_wrapper_form_path;
    $added_item_form_path[] = 'addable_items';
    $added_item_form_path[] = '#options_details';
    $added_item_form_path[] = $addable_item_id;
    /** @noinspection ReferenceMismatchInspection */
    $added_item_details = NestedArray::getValue(
      $form_state->getCompleteForm(),
      $added_item_form_path
    );

    return $added_item_details;
  }

  /**
   * @return array
   */
  protected function getCategorizedLayoutList() {
    // Build Layouts list to be render as grouped select options
    $layout_list = [];
    if (null !== $this->layoutPlugins) {
      // Initialize option group
      $current_category = null;
      // Iterate over each layout plugin definition and create relevants options
      foreach ($this->layoutPlugins as $plugin_id => $plugin_info) {
        // Create a combined option group using provider and category
        $combined_category = implode(
          ' | ', [$plugin_info->getProvider(), $plugin_info->getCategory()]
        );
        // Manage proper grouping logic based on $current_category
        if ($combined_category !== $current_category) {
          $current_category = $combined_category;
          $layout_list[$current_category] = [];
        }
        // Finally set option key and value in the relevant group
        $label = $plugin_info->getLabel();
        $layout_list[$current_category][$plugin_id] = $label;
      }
    }

    return $layout_list;
  }

  /**
   * @param \Drupal\Core\Layout\LayoutDefinition $layout
   * @param array $item_values_regions
   * @param $regions_wrapper_id
   *
   * @return array
   */
  protected function getRegionsTableElement(/** @noinspection PhpInternalEntityUsedInspection */ LayoutDefinition $layout, array $item_values_regions, $regions_wrapper_id) {
    // Initialize regions wrapper, basically a container with the relevant id
    $regions_table_id = str_replace('-wpr', '-tabledrag', $regions_wrapper_id);
    $regions_table = [
      '#type' => 'table',
      '#empty' => t('No regions available'),
      '#attributes' => [
        'id' => $regions_table_id,
      ],
      '#prefix' => '<div id="' . $regions_wrapper_id . '">',
      '#suffix' => '</div>',
      '#regions_wrapper_id' => $regions_wrapper_id,
      '#header' => [
        [
          'data' => t('Label'),
          'colspan' => 4,
        ],
        t('Weight'),
      ]
    ];

    // Map items with regions
    $content_assignment = [];
    /** @var array $item_values_regions */
    foreach ($item_values_regions as $id => $values) {
      if (
        array_key_exists('id', $values)
        && array_key_exists('region', $values)
        && array_key_exists('weight', $values)
      ) {
        $content_assignment[$values['region']][$values['id']] = $values;
      }
    }
    // Iterate over regions and build each table row accordingly
    foreach($layout->getRegions() as $region_id => $region) {
      if (false === array_key_exists($region_id, $content_assignment)) {
        $content_assignment[$region_id] = [];
      }
      $regions_table['#tabledrag'][] = [
        'action' => 'order',
        'relationship' => 'sibling',
        'group' => 'item-weight',
        'subgroup' => 'item-weight-' . $region_id,
      ];

      $region_row['#attributes']['data-region-id'] = $region_id;

      $region_row['label'] = [
        '#plain_text' => $region['label'],
        '#wrapper_attributes' => [
          'colspan' => 3
        ]
      ];

      $region_row['add_item'] = [
        '#type' => 'button',
        '#value' => 'Add',
        '#name' => 'add-item-' . $regions_wrapper_id . '-' . $region_id,
        '#action' => 'add_item',
        '#regions_wrapper_id' => $regions_wrapper_id,
        '#region_id' => $region_id,
        '#ajax' => [
          'event' => 'click',
          'callback' => [$this, 'regionsReplaceAjaxCallback']
        ],
      ];

      $region_row['id'] = [
        '#type' => 'hidden',
        '#value' => $region_id,
        '#attributes' => [
          'class' => [
            'region-id-' . $region_id
          ],
          'disabled' => 'disabled',
        ],
      ];

      $regions_table[$region_id] = $region_row;
      // build items rows for a given region row
      $this->buildItemRows(
        $regions_table,
        $region_id,
        $region,
        $content_assignment[$region_id]
      );
    }

    $regions_table['#attached']['library'][] = 'entity_layout/tabledrag_override';

    return $regions_table;
  }

  /**
   * @param array $regions_table
   * @param $region_id
   * @param array $region
   * @param array $region_content
   */
  protected function buildItemRows (array &$regions_table, $region_id, array $region, array $region_content) {
    // @todo: Add remove button on items
    foreach ($region_content as $item_id => $item) {
      $label = $this->getItemLabel($item);
      $item_row = [
        '#attributes' => [
          'class' => [
            'draggable',
            'tabledrag-leaf'
          ],
        ],
      ];
      $item_row['label'] = $label;
      $item_row['id'] = [
        '#type' => 'hidden',
        '#value' => $item_id,
      ];
      $item_row['delta'] = [
        '#type' => 'hidden',
        '#value' => $item['delta'],
      ];
      $item_row['region'] = [
        '#type' => 'hidden',
        '#value' => $region_id,
        '#attributes' => [
          'data-region-id-input' => true,
        ],
      ];

      $item_row['weight'] = [
        '#type' => 'weight',
        '#title' => t('Weight for @title', array('@title' => $item['id'])),
        '#title_display' => 'invisible',
        '#default_value' => $item['weight'],
        '#delta' => 20,
        '#attributes' => [
          'class' => [
            'item-weight',
            'item-weight-' . $region_id,
          ]
        ],
      ];

      $regions_table[$item_id] = $item_row;
    }
  }

  /**
   * @param $item
   * @return array
   */
  protected function getItemLabel($item) {
    // @todo: think about using a view mode to customize label
    return [
      '#markup' => $item['id'],
    ];
  }

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function regionsReplaceAjaxCallback(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    /** @noinspection ReferenceMismatchInspection */
    $trigger = $form_state->getTriggeringElement();
    $regions = $this->getAjaxRegionsElement($trigger, $form);
    if (null === $regions) {
      return $response;
    }
    $replace = new ReplaceCommand(
      '#' . $trigger['#regions_wrapper_id'],
      $regions
    );
    $response->addCommand($replace);

    return $response;
  }

  /**
   * Get the regions render array portion based on current trigger and updated
   * $form in ajax context
   *
   * @param array $trigger
   * @param array $form
   * @return array|null
   */
  public function getAjaxRegionsElement(array $trigger, array &$form) {
    if (
      false === array_key_exists('#regions_wrapper_id', $trigger)
      || false === array_key_exists('#action', $trigger)
    ) {

      return null;
    }
    $regions_wrapper_form_index = null;
    if ('add_item' === $trigger['#action']) {
      $regions_wrapper_form_index = array_search(
        'regions',
        $trigger['#array_parents'],
        true
      );
    } elseif ('change_layout' === $trigger['#action']) {
      $regions_wrapper_form_index = count($trigger['#array_parents']) - 1;
    }
    if (null === $regions_wrapper_form_index) {

      return null;
    }
    $regions_wrapper_form_path = array_splice(
      $trigger['#array_parents'],
      0,
      $regions_wrapper_form_index
    );
    $regions_wrapper_form_path[] = 'regions';
    /** @noinspection ReferenceMismatchInspection */
    $regions = NestedArray::getValue(
      $form,
      $regions_wrapper_form_path
    );

    return $regions;
  }
}
