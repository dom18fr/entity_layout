<?php

namespace Drupal\entity_layout\Plugin\Field\FieldWidget;

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
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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

  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   *
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
   *
   * @return static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var LayoutPluginManagerInterface $LayoutPluginManager */
    $LayoutPluginManager = $container->get('plugin.manager.core.layout');

    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $LayoutPluginManager
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
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, LayoutPluginManagerInterface $layoutPluginsManager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->layoutPlugins = $layoutPluginsManager->getDefinitions();
  }

  /**
   * {@inheritdoc}
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
    // a custom #region_wrapper_key to be able to target the right region at
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

    // @todo: temporary
    $element['addable_items'] = [
      '#type' => 'radios',
      '#options' => [
        'test' => 'test',
        'test2' => 'test2',
      ],
      '#name' => 'addable-items-' . $regions_wrapper_id,
      '#id' => 'addable-items-' . $regions_wrapper_id,
      '#options_details' => [
        'test' => [
          'id' => 'field_test',
          'delta' => -1
        ],
        'test2' => [
          'id' => 'field_test2',
          'delta' => 2,
        ],
      ],
    ];
    // @todo: end temporary

    return $element;
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
      // @todo: here, get able to grab new item assignment from ajax
      $regions = isset($item->regions) && '' !== $item->regions ? $item->regions : [];
    }
    
    return [$layout_id, $regions];
  }

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
        $content_assignment[$values['region']][$values['id']] = [
          'id' => $values['id'],
          'delta' => array_key_exists('delta', $values) ? $values['delta'] : -1,
          'label' => array_key_exists('label', $values) ? $values['label'] : $values['id'],
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

  public function itemAddAjaxCallback(array &$form, FormStateInterface $form_state) {
    // @todo: cleanup this fucking mess (do it in ::getElementValues())
    /** @var array $trigger_parents */
    $trigger_parents = $form_state->getTriggeringElement()['#parents'];
    $item_state_parents = [];
    foreach ($trigger_parents as $parent) {
      if ('regions' === $parent) {

        $item_state_parents[] = 'addable_items';
        break;
      }
      $item_state_parents[] = $parent;
    }
    /** @noinspection ReferenceMismatchInspection */
    $item_id = NestedArray::getValue(
      $form_state->getValues(),
      $item_state_parents
    );
    if (null === $item_id) {

      return null;
    }
    /** @var array $trigger_form_parents */
    $trigger_form_parents = $form_state->getTriggeringElement()['#array_parents'];
    $details_parents = [];
    $region_id_parents = [];
    $region_found = false;
    $is_region_id = false;
    $region_parents = [];
    foreach ($trigger_form_parents as $parent) {
      if ('regions' === $parent) {
        $region_found = true;
        $region_parents = $details_parents;
        $region_parents[] = $parent;

        $details_parents[] = 'addable_items';
        $details_parents[] = '#options_details';
        $details_parents[] = $item_id;
      }
      $region_id_parents[] = $parent;
      if (true === $is_region_id) {
        $region_id_parents[] = 'id';
        $region_id_parents[] = '#value';
        break;
      }
      if (false === $region_found) {
        $details_parents[] = $parent;
      } else {
        $is_region_id = true;
      }
    }
    /** @noinspection ReferenceMismatchInspection */
    $item_details = NestedArray::getValue(
      $form,
      $details_parents
    );
    if (null === $item_details) {

      return null;
    }
    /** @noinspection ReferenceMismatchInspection */
    $region_id = NestedArray::getValue(
      $form,
      $region_id_parents
    );
    if (null === $region_id) {

      return null;
    }
    /** @noinspection ReferenceMismatchInspection */
    $regions = NestedArray::getValue(
      $form,
      $region_parents
    );

    $content_row = [
      '#attributes' => [
        'class' => [
          'draggable',
          'tabledrag-leaf'
        ],
      ],
    ];
    $content_row['label'] = [
      '#markup' => $item_details['id'] . ':' . $item_details['delta'],
    ];
    $content_row['id'] = [
      '#type' => 'hidden',
      '#value' => $item_details['id'],
    ];
    $content_row['delta'] = [
      '#type' => 'hidden',
      '#value' => $item_details['delta'],
    ];
    $content_row['region'] = [
      '#type' => 'hidden',
      '#value' => $region_id,
      '#attributes' => [
        'data-region-id-input' => true,
      ],
    ];

    $content_row['weight'] = [
      '#type' => 'weight',
      '#title' => t('Weight for @title', array('@title' => $content_row['label']['#markup'])),
      '#title_display' => 'invisible',
      '#default_value' => 0,
      '#delta' => 20,
      '#attributes' => [
        'class' => [
          'item-weight',
          'item-weight-' . $region_id,
        ]
      ],
    ];

    $regions[$item_details['id']] = $content_row;
    $replace = new ReplaceCommand('#' . $regions['#regions_wrapper_id'], $regions);
//    $res = array_slice($array, 0, 3, true) +
//      array("my_key" => "my_value") +
//      array_slice($array, 3, count($array) - 1, true) ;
    $response = new AjaxResponse();
    $response->addCommand($replace);

    return $response;
  }

}
