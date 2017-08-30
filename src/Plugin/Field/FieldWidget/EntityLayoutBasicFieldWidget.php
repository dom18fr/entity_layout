<?php

namespace Drupal\entity_layout\Plugin\Field\FieldWidget;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
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

    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $layout_plugin_manager,
      $entity_type_manager
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
   * @param LayoutPluginManagerInterface $layoutPluginsManager
   * @param EntityTypeManagerInterface $entity_type_manager
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, /** @noinspection PhpInternalEntityUsedInspection */ LayoutPluginManagerInterface $layoutPluginsManager, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $third_party_settings
    );
    $this->layoutPlugins = $layoutPluginsManager->getDefinitions();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    // Get full field definition and field name
    /** @var FieldDefinitionInterface $field_definition */
    $field_definition = $items->getFieldDefinition();
    $field_name = $field_definition->getName();
    // Get current layout id and regions state
    list($layout_id, $regions) = $this->getElementValues($field_name, $items, $delta, $form_state);
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
        'callback' => [$this, 'layoutSelectChangeAjaxCallback'],
      ],
      '#regions_wrapper_id' => $regions_wrapper_id,
      '#change_layout' => true,
    ];
    // Create regions and items tabledrag
    if (false === array_key_exists($layout_id, $this->layoutPlugins)) {

      return $element;
    }
    $element['regions'] = $this->regionsTableElement(
      $this->layoutPlugins[$layout_id],
      $regions,
      $regions_wrapper_id
    );
    /** @var FieldableEntityInterface $entity */
    $entity = $items->getEntity();
    $element['addable_items'] = $this->getAddableItemsElement($entity);
    // @todo: temporary
//    $element['addable_items'] = [
//      '#type' => 'radios',
//      '#options' => [
//        'test' => 'test',
//        'test2' => 'test2',
//      ],
//      '#name' => 'addable-items-' . $regions_wrapper_id,
//      '#id' => 'addable-items-' . $regions_wrapper_id,
//      '#options_details' => [
//        'test' => [
//          'id' => 'field_test',
//          'delta' => -1
//        ],
//        'test2' => [
//          'id' => 'field_test2',
//          'delta' => 2,
//        ],
//      ],
//    ];
//    // @todo: end temporary

    return $element;
  }

  /**
   * @param FieldableEntityInterface $entity
   *
   * @throws InvalidPluginDefinitionException
   *
   * @return array
   */
  protected function getAddableItemsElement(FieldableEntityInterface $entity) {
    // @todo: does the default view_mode actually return all components ?
    $view_mode = implode(
      '.',
      [
        $entity->getEntityTypeId(),
        $entity->bundle(),
        'default'
      ]
    );
    /** @var EntityViewDisplay $display */
    $display = \Drupal::entityTypeManager()
      ->getStorage('entity_view_display')
      ->load($view_mode);
    $components = $display->getComponents();
    $component_list = [
      '#theme' => 'item_list',
      '#list_type' => 'ul',
      '#items' => [],
    ];
    $list = &$component_list['#items'];
    foreach ($components as $name => $component) {
      // @todo: exclude entity_layout field items ...
      // @todo: build proper checkboxes
      if (null !== $entity->getFieldDefinition($name)) {
        $list[] = [
          '#markup' => $entity->getFieldDefinition($name)->getLabel(),
          '#attributes' => [],
        ];
      }
    }

    return $component_list;
  }

  /**
   * Get current layout id and regions values, directly from the model or based
   * on current ajax command if needed.
   *
   * @param $field_name
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   * @param $delta
   * @param \Drupal\Core\Form\FormStateInterface $form_state
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
      || false === array_key_exists('#add_item', $trigger)
      || true !== $trigger['#add_item']
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
  protected function regionsTableElement(/** @noinspection PhpInternalEntityUsedInspection */ LayoutDefinition $layout, array $item_values_regions, $regions_wrapper_id) {
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
        $delta = array_key_exists('delta', $values) ? $values['delta'] : -1;
        $label = array_key_exists('label', $values) ?
          $values['label'] :
          $values['id'];
        $content_assignment[$values['region']][$values['id']] = [
          'id' => $values['id'],
          'delta' => $delta,
          'label' => $label,
          'parent' => $values['region'],
          'weight' => $values['weight'],
        ];
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
        '#value' => 'go',
        '#name' => 'add-item-' . $regions_wrapper_id . '-' . $region_id,
        '#add_item' => true,
        '#regions_wrapper_id' => $regions_wrapper_id,
        '#region_id' => $region_id,
        '#ajax' => [
          'event' => 'click',
          'callback' => [$this, 'itemAddAjaxCallback']
        ],
      ];

      $region_row['id'] = [
        '#type' => 'hidden',
        '#value' => $region_id,
        '#attributes' => [
          'class' => [
            'region-id-' . $region_id
          ],
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
    foreach ($region_content as $item_id => $item) {
      $item_row = [
        '#attributes' => [
          'class' => [
            'draggable',
            'tabledrag-leaf'
          ],
        ],
      ];
      $item_row['label'] = [
        '#markup' => $item['label'], // Label should be calculated id + delta, or title / preview
      ];
      $item_row['id'] = [
        '#type' => 'hidden',
        '#value' => $item,
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
        '#title' => t('Weight for @title', array('@title' => $item['label'])),
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
   * Ajax callback updating regions based on selected layout.
   *
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function layoutSelectChangeAjaxCallback(array &$form, FormStateInterface $form_state) {
    // Get path to updated regions wrapper in the $form.
    list($field_name, $widget, $delta) = $form_state
      ->getTriggeringElement()['#array_parents'];
    // Get $regions_wrapper_id stored in layout select
    $regions_wrapper_id = $form_state
      ->getTriggeringElement()['#regions_wrapper_id'];
    // Set up replacement command for old region wrapper with the updated one
    $regions = $form[$field_name][$widget][$delta]['regions'];
    $replace = new ReplaceCommand('#' . $regions_wrapper_id, $regions);
    // Finally build $response and return it
    $response = new AjaxResponse();
    $response->addCommand($replace);

    return $response;
  }

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function itemAddAjaxCallback(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    /** @noinspection ReferenceMismatchInspection */
    $trigger = $form_state->getTriggeringElement();
    if (false === array_key_exists('#regions_wrapper_id', $trigger)) {

      return $response;
    }
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
    $regions_wrapper_form_path[] = 'regions';
    /** @noinspection ReferenceMismatchInspection */
    $regions = NestedArray::getValue(
      $form_state->getCompleteForm(),
      $regions_wrapper_form_path
    );
    $replace = new ReplaceCommand(
      '#' . $trigger['#regions_wrapper_id'],
      $regions
    );
    $response->addCommand($replace);

    return $response;
  }

}
