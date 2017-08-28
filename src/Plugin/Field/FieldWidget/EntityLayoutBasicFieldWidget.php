<?php

namespace Drupal\entity_layout\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Annotation\Translation;
use Drupal\Core\Field\Annotation\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Layout\LayoutDefinition;
use /** @noinspection PhpInternalEntityUsedInspection */
  Drupal\Core\Layout\LayoutPluginManager;
use Drupal\entity_layout\Plugin\Field\FieldType\EntityLayoutFieldType;

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
class EntityLayoutBasicFieldWidget extends WidgetBase {
  // @todo: Use a service
  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    // Load Layout manager and retrieve layout_plugins definitions
    /** @var  LayoutPluginManager $layoutsManager */
    $layoutsManager = \Drupal::service('plugin.manager.core.layout');
    /** @var array $layout_plugins */
    $layout_plugins = $layoutsManager->getDefinitions();
    // Build Layouts list to be render as grouped select options
    $layouts_list = [];
    if (null !== $layout_plugins) {
      // Initialize option group
      $current_category = null;
      // Iterate over each layout plugin definition and create relevants options
      foreach ($layout_plugins as $plugin_id => $plugin_info) {
        // Create a combined option group using provider and category
        $combined_category = implode(
          ' | ', [$plugin_info->getProvider(), $plugin_info->getCategory()]
        );
        // Manage proper grouping logic based on $current_category
        if ($combined_category !== $current_category) {
          $current_category = $combined_category;
          $layouts_list[$current_category] = [];
        }
        // Finally set option key and value in the relevant group
        $label = $plugin_info->getLabel();
        $layouts_list[$current_category][$plugin_id] = $label;
      }
    }

    // Get full field definition and field name
    /** @var FieldDefinitionInterface $field_definition */
    $field_definition = $items->getFieldDefinition();
    $field_name = $field_definition->getName();
    // Initialize layout id & regions
    $layout_id = null;
    // Try to get a layout_id & regions from $form_state, if this is not null
    // it means the field values have just been changed, so we are in case of an ajax rebuild.
    // Else layout_id and regions  is retrieved from the model, using $items.
    if (null !== $form_state->getValue($field_name)) {
      $layout_id = $form_state->getValue($field_name)[$delta]['layout'];
      $regions = $form_state->getValue($field_name)[$delta]['regions'];
      if ('' === $regions) {
        $regions = [];
      }
    } else {
      $item = $items[$delta];
      $layout_id = isset($item->layout) ? $item->layout : null;
      $regions = isset($item->regions) && '' !== $item->regions ? $item->regions : [];
    }
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
      '#options' => $layouts_list,
      '#default_value' => $layout_id,
      '#empty_option' => '-- ' . $this->t('Choose a layout') . ' --',
      '#ajax' => [
        'event' => 'change',
        'callback' => [$this, 'layoutSelectChangeAjaxCallback'],
      ],
      '#regions_wrapper_id' => $regions_wrapper_id,
    ];

    $this->buildRegionsTable($element, $layout_plugins, $layout_id, $regions);
    $element['#attached']['library'][] = 'entity_layout/tabledrag_override';

    return $element;
  }

  protected function buildRegionsTable(array &$element, array $layout_plugins, $layout_id, array $item_values_regions) {
    // Initialize regions wrapper, basically a container with the relevant id
    $regions_wrapper_id = $element['layout']['#regions_wrapper_id'];
    $regions_table_id = str_replace('-wpr', '-tabledrag', $regions_wrapper_id);
    $element['regions'] = [
      '#type' => 'table',
      '#empty' => t('No regions available'),
      '#attributes' => [
        'id' => $regions_table_id,
      ],
      '#prefix' => '<div id="' . $regions_wrapper_id . '">',
      '#suffix' => '</div>'
    ];
    if (false === array_key_exists($layout_id, $layout_plugins)) {
      return null;
    }

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

    /**
     * MOCK
     */
//    if ('layout_twocol' === $layout_id) {
//      $content_assignment['bottom']['field_yololo_2'] = [
//        'id' => 'field_yololo',
//        'delta' => 2,
//        'label' => 'field_yololo:2',
//        'region' => 'layout_twocol',
//        'weight' => 0,
//      ];
//    }
    /**
     * ENDMOCK
     */

    $regions_table = &$element['regions'];
    $regions_table['#header'] = [
      [
        'data' => t('Label'),
        'colspan' => 4,
      ],
      t('Weight'),
    ];
    /** @var LayoutDefinition $layout */
    $layout = $layout_plugins[$layout_id];
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
      $this->buildItemRows($regions_table, $region_id, $region, $content_assignment[$region_id]);
    }
  }

  protected function buildItemRows (array &$regions_table, $region_id, array $region, array $region_content) {

    /** @var array $contents */
    foreach ($region_content as $content_id => $content) {
      $content_row = [
        '#attributes' => [
          'class' => [
            'draggable',
            'tabledrag-leaf'
          ],
        ],
      ];
      $content_row['label'] = [
        '#markup' => $content['label'], // Label should be calculated id + delta, or title / preview
      ];
      $content_row['id'] = [
        '#type' => 'hidden',
        '#value' => $content_id,
      ];
      $content_row['delta'] = [
        '#type' => 'hidden',
        '#value' => $content['delta'],
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
        '#title' => t('Weight for @title', array('@title' => $content['label'])),
        '#title_display' => 'invisible',
        '#default_value' => $content['weight'],
        '#delta' => 20,
        '#attributes' => [
          'class' => [
            'item-weight',
            'item-weight-' . $region_id,
          ]
        ],
      ];

      $regions_table[$content_id] = $content_row;
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

}
