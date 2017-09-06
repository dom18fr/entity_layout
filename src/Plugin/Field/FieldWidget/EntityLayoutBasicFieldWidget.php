<?php

namespace Drupal\entity_layout\Plugin\Field\FieldWidget;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\entity_layout\Service\AddableItemsHandlerInterface;
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

  /** @var  AddableItemsHandlerInterface $addableItemsHandler */
  protected $addableItemsHandler;

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
    /** @var AddableItemsHandlerInterface $addable_items_handler */
    $addable_items_handler = $container->get('entity_layout.addable_items_handler');

    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $layout_plugin_manager,
      $entity_type_manager,
      $entity_field_manager,
      $addable_items_handler
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
   * @param AddableItemsHandlerInterface $addable_items_handler
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, /** @noinspection PhpInternalEntityUsedInspection */ LayoutPluginManagerInterface $layout_plugins_manager, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, AddableItemsHandlerInterface $addable_items_handler) {
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
    $this->addableItemsHandler = $addable_items_handler;
  }

  /**
   * Build the widget container wrapping the whole items of an multivalued field.
   * The addable items input is built at widget container level.
   *
   * @param FieldItemListInterface $items
   * @param array $form
   * @param FormStateInterface $form_state
   * @param null $get_delta
   *
   * @throws \LogicException
   * @throws \InvalidArgumentException
   *
   * @return array
   */
  public function form(FieldItemListInterface $items, array &$form, FormStateInterface $form_state, $get_delta = NULL) {
    $field_form = parent::form($items, $form, $form_state, $get_delta);
    $used_addable_items = $this->getUsedAddableItems($items, $form_state);
    /** @var FieldableEntityInterface $entity */
    $entity = $items->getEntity();
    $addable_id = $this->getUniqueId('addable-items');
    $field_form['addable_items'] = [];
    $addable = &$field_form['addable_items'];
    $addable[$addable_id] = $this->addableItemsHandler->getAddableItemsElement(
      $entity,
      $used_addable_items,
      $addable_id
    );
    $addable[$addable_id]['#prefix'] = '<div id="' . $addable_id . '">';
    $addable[$addable_id]['#suffix'] = '</div>';

    $addable['#tree'] = true;

    return $field_form;
  }

  /**
   * Generate a unique id based on the current field instance to prevent ajax
   * replacement collisions when several layout fields exists in the current
   * entity form.
   *
   * @param $key
   *
   * @return string
   */
  protected function getUniqueId($key) {
    // We use Html::getId() instead of Html::getUniqueId() to get it match
    // over ajax rebuilding process.
    return Html::getId(
      implode(
        '-',
        [
          'entity-layout',
          $this->fieldDefinition->getTargetEntityTypeId(),
          $this->fieldDefinition->getTargetBundle(),
          $this->fieldDefinition->getName(),
          $key,
        ]
      )
    );
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
  protected function getUsedAddableItems(FieldItemListInterface $items, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
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
      if ('add_item' === $trigger['#action']) {
        // Add current added_item to used
        $added_item_details = $this->getAddedItemDetails(
          $form_state
        );
        $used[$added_item_details['id']] = $added_item_details['id'];
      }
      if ('remove_item' === $trigger['#action']) {
        // Remove current item from used
        unset($used[$trigger['#item_id']]);
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
   * Build a single widget element, this is called for each occurence of a
   * multivalued field widget.
   *
   * @param FieldItemListInterface $items
   * @param int $delta
   * @param array $element
   * @param array $form
   * @param FormStateInterface $form_state
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \LogicException
   * @throws \InvalidArgumentException
   *
   * @return array
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    // Get full field definition and field name
    $field_name = $this->fieldDefinition->getName();
    // Get current layout id and regions state
    list($layout_id, $regions) = $this->getElementValues(
      $field_name,
      $items,
      $delta,
      $form_state
    );
    $regions_wrapper_id = $this->getUniqueId($delta . '-regions-wpr');
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
        'callback' => [$this, 'replaceAjaxCallback'],
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

      return $element;
    }
    $element['regions'] = $this->getRegionsTableElement(
      $this->layoutPlugins[$layout_id],
      $regions,
      $regions_wrapper_id
    );

    return $element;
  }

  /**
   * After build callback for entity form. Used to add a validate handler at
   * the very end of the list
   *
   * @param $form
   * @param FormStateInterface $form_state
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
   * Validate handler for entity form, called after any other, it ensure
   * no data validation problem could prevent added item ajax processing.
   *
   * @param $form
   * @param FormStateInterface $form_state
   */
  public static function addItemValidateCallback(&$form, FormStateInterface $form_state) {
    /** @noinspection ReferenceMismatchInspection */
    $trigger = $form_state->getTriggeringElement();
    if (false === array_key_exists('#action', $trigger)) {

      return;
    }
    $form_state->clearErrors();
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
    $this->buildAjaxUpdatedItem($regions, $items, $delta, $form_state);

    return [$layout_id, $regions];
  }

  /**
   * Based on the current ajax request, build a new row or remove an old one
   * and update $regions render array accordingly.
   *
   * @param $regions
   * @param FieldItemListInterface $items
   * @param $delta
   * @param FormStateInterface $form_state
   *
   * @return null
   */
  protected function buildAjaxUpdatedItem(&$regions, FieldItemListInterface $items, $delta, FormStateInterface $form_state) {
    /** @noinspection ReferenceMismatchInspection */
    $trigger = $form_state->getTriggeringElement();
    if (
      null === $trigger
      || false === array_key_exists('#action', $trigger)
    ) {

      return null;
    }
    $item_details = $this->getAddedItemDetails(
      $form_state
    );
    if ('add_item' === $trigger['#action']) {
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
    } elseif ('remove_item' === $trigger['#action']) {
      unset($regions[$trigger['#item_id']]);
    }
  }

  /**
   * Fetch just added item details, based on current ajax request.
   * Those details actually ships with the addable_items render array, in a
   * custom #options_details property, so its not really submitted,
   * but available throught $form anyway.
   *
   * @param FormStateInterface $form_state
   *
   * @return array
   */
  protected function getAddedItemDetails(FormStateInterface $form_state) {
    $addable_items_id = $this->getUniqueId('addable-items');
    $addable_items_element = $this->grabAddableItemsElement(
      $form_state->getTriggeringElement(),
      $form_state->getCompleteForm(),
      $addable_items_id
    );
    $added_item_id = $form_state->getValue('addable_items')[$addable_items_id];

    return $addable_items_element['#options_details'][$added_item_id];
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
  protected function grabAddableItemsElement(array $trigger, array $form, $id) {
    if (false === array_key_exists('#action', $trigger)) {

      return null;
    }
    $addable_items_form_path = null;
    switch($trigger['#action']) {
      case 'add_item':
      case 'remove_item':
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

  /**
   * Build a categorized layout list to be displayed in a select input.
   * Provider and category are concatenated in a single "group".
   *
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
   * Build the $regions render array, suitable for tabledrag.
   *
   * @param LayoutDefinition $layout
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
          'colspan' => 5,
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
          'colspan' => 4
        ]
      ];

      $region_row['add_item'] = [
        '#type' => 'button',
        '#value' => 'Place here',
        '#name' => 'add-item-' . $regions_wrapper_id . '-' . $region_id,
        '#action' => 'add_item',
        '#regions_wrapper_id' => $regions_wrapper_id,
        '#region_id' => $region_id,
        '#ajax' => [
          'event' => 'click',
          'callback' => [$this, 'replaceAjaxCallback']
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
   * Build tabledrag item rows in a given region.
   *
   * @param array $regions_table
   * @param $region_id
   * @param array $region
   * @param array $region_content
   */
  protected function buildItemRows (array &$regions_table, $region_id, array $region, array $region_content) {
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

      $item_row['remove'] = [
        '#type' => 'button',
        '#value' => 'Remove',
        '#action' => 'remove_item',
        '#name' => $this->getUniqueId('remove-item-' . $item_id),
        '#item_id' => $item_id,
        '#ajax' => [
          'event' => 'click',
          'callback' => [$this, 'replaceAjaxCallback']
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
   * Return a render array for item label, to be used in the regions table and
   * the addable_items list.
   *
   * @param $item
   *
   * @return array
   */
  protected function getItemLabel($item) {
    // @todo: think about using a view mode to customize label
    return [
      '#markup' => $item['id'],
    ];
  }

  /**
   * Build ajax response including relevant replacement commands based on
   * current ajax request.
   *
   * @param array $form
   * @param FormStateInterface $form_state
   *
   * @return AjaxResponse
   */
  public function replaceAjaxCallback(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    /** @noinspection ReferenceMismatchInspection */
    $trigger = $form_state->getTriggeringElement();
    $regions = $this->grabRegionsElement($trigger, $form);
    $regions_wrapper_id = $regions['#regions_wrapper_id'];
    if (null === $regions) {

      return $response;
    }
    $replace_regions = new ReplaceCommand(
      '#' . $regions_wrapper_id,
      $regions
    );
    $response->addCommand($replace_regions);
    $addable_items_id = $this->getUniqueId('addable-items');
    /** @noinspection ReferenceMismatchInspection */
    $addable_items = $this->grabAddableItemsElement(
      $trigger,
      $form,
      $addable_items_id
    );
    if (null === $addable_items) {

      return $response;
    }
    $replace_addable_items = new ReplaceCommand(
      '#' . $addable_items_id,
      $addable_items
    );
    $response->addCommand($replace_addable_items);

    return $response;
  }

  /**
   * Return a reference to an up-to-date regions table
   *
   * @param array $trigger
   * @param array $form
   * @return array|null
   */
  protected function grabRegionsElement(array $trigger, array &$form) {
    if (false === array_key_exists('#action', $trigger)) {

      return null;
    }
    $regions_form_index = null;
    $regions_form_path = null;
    switch ($trigger['#action']) {
      case 'add_item':
      case 'remove_item':
        $regions_form_index = array_search(
          'regions',
          $trigger['#array_parents'],
          true
        ) + 1;
        $regions_form_path = array_splice(
          $trigger['#array_parents'],
          0,
          $regions_form_index
        );
        break;

      case 'change_layout':
        $widget_form_index = count($trigger['#array_parents']) - 1;
        $widget_form_path = array_splice(
          $trigger['#array_parents'],
          0,
          $widget_form_index
        );
        $regions_form_path = $widget_form_path;
        $regions_form_path[] = 'regions';
        break;
    }
    if (null === $regions_form_path) {

      return null;
    }

    return NestedArray::getValue(
      $form,
      $regions_form_path
    );
  }
  
}
