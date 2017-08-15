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
    $form['#attached']['library'][] = 'block/drupal.block';
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
    ksm($layouts_list);
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
    // between a rebuit select and a not yet rebuilt region wrapper.
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
    // Regions mapping have to be rebuit when layout changes since each layout
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
    // Initialize regions wrapper, basically a container with the relevant id
    $element['regions'] = [
      '#type' => 'table',
      '#tree' => true,
      '#prefix' => '<div id="' . $regions_wrapper_id . '">',
      '#suffix' => '</div>'
    ];
    // @todo: remove it, just for test
    $element['add_content'] = [
      '#type' => 'button',
      '#value' => 'add',
      '#ajax' => [
        'event' => 'click',
        'callback' => [$this, 'layoutSelectChangeAjaxCallback'],
      ],
      '#regions_wrapper_id' => $regions_wrapper_id,
      '#name' => 'add_content_' . $delta,
    ];
    if (
        null !== $form_state->getTriggeringElement()
        && 'add_content' === end($form_state->getTriggeringElement()['#array_parents'])
    ) {
      $id = Html::getUniqueId('yolo');
      $regions[$id] = [
          'id' => $id,
          'delta' => -1,
          'region' => 'left',
          'weight' => 0,
      ];
    }
    $this->buildRegions($element, $layout_plugins, $layout_id, $regions);

    // @todo: remove it
    $element = [
      '#markup' => '<div>yolo</div>',
    ];

    return $element;
  }

  protected function buildRegions(&$element, $layout_plugins, $layout_id, $item_values_regions) {
    // @todo: manage content assignment replacement when switching layout
    if (null === $layout_id) {

      return;
    }
    $regions = &$element['regions'];
    $regions['#header'] = [
      $this->t('Label'),
      $this->t('Content ID'),
      $this->t('Delta'),
      $this->t('Regions'),
      $this->t('Weight'),
      $this->t('Operations'),
    ];
    // This id value is needed in order to get drupal.blocks working.
    // @todo: find a way to cleanly override it, each field item would have
    // the same id, it can't work this way.
    $regions['#attributes']['id'] = 'blocks';
    /** @var EntityLayoutFieldType $item */

    $regions = $layout_plugins[$layout_id]->getRegions();
    /** @var  array $regions */
    // @todo: replace "content" wording with "component", used in EntityViewDisplay
    $content_assignement = [];
    foreach ($regions as $id => $data) {
      $content_assignement[$id] = [];
    }
    /** @var array $item_values_regions */
    foreach ($item_values_regions as $id => $values) {
      if (
        array_key_exists('id', $values)
        && array_key_exists('region', $values)
        && array_key_exists('weight', $values)
      ) {
        $content_assignement[$values['region']][$values['id']] = [
          'label' => array_key_exists('label', $values) ? $values['label'] : $values['id'],
          'id' => $values['id'],
          'delta' => array_key_exists('delta', $values) ? $values['delta'] : -1,
          'region' => $values['region'],
          'weight' => $values['weight'],
        ];
      }
    }
    // Loop through the blocks per region.
    foreach ($content_assignement as $region => $contents) {
      // Add a section for each region and allow blocks to be dragged between
      // them.
      $region_name = $regions[$region]['label'];
      $regions['#tabledrag'][] = [
        'action' => 'match',
        'relationship' => 'sibling',
        'group' => 'block-region-select',
        'subgroup' => 'block-region-' . $region,
        'hidden' => FALSE,
      ];
      $regions['#tabledrag'][] = [
        'action' => 'order',
        'relationship' => 'sibling',
        'group' => 'block-weight',
        'subgroup' => 'block-weight-' . $region,
      ];
      $regions['region-' . $region] = [
        '#attributes' => [
          'class' => ['region-title', 'region-title-' . $region],
          'no_striping' => TRUE,
        ],
      ];
      $regions['region-' . $region]['title'] = [
        '#markup' => $region_name,
        '#wrapper_attributes' => [
          'colspan' => 6,
        ],
      ];
      $regions['region-' . $region . '-message'] = [
        '#attributes' => [
          'class' => [
            'region-message',
            'region-' . $region . '-message',
            (0 === count($contents)) ? 'region-empty' : 'region-populated',
          ],
        ],
      ];
      $regions['region-' . $region . '-message']['message'] = [
        '#markup' => '<em>' . $this->t('No contents in this region') . '</em>',
        '#wrapper_attributes' => [
          'colspan' => 6,
        ],
      ];

      /** @var array $contents */
      foreach ($contents as $content_id => $content) {
        $row = [
          '#attributes' => [
            'class' => ['draggable'],
          ],
        ];
        $row['label']['#markup'] = $content['label']; // Label should be calculated
        $row['id'] = [
          '#type' => 'hidden',
          '#value' => $content_id,
          '#suffix' => $content_id,
        ];
        $row['delta'] = [
          '#type' => 'hidden',
          '#value' => $content['delta'],
          '#suffix' => $content['delta'],
        ];
        // Allow the region to be changed for each block.
        $row['region'] = [
          '#title' => $this->t('Region'),
          '#title_display' => 'invisible',
          '#type' => 'select',
          '#options' => $region_names,
          '#default_value' => $region,
          '#attributes' => [
            'class' => ['block-region-select', 'block-region-' . $region],
          ],
        ];
        // Allow the weight to be changed for each block.
        $row['weight'] = [
          '#type' => 'weight',
          '#default_value' => $content['weight'],
          '#title' => $this->t(
            'Weight for @block block',
            ['@block' => $region_name]
          ),
          '#title_display' => 'invisible',
          '#attributes' => [
            'class' => ['block-weight', 'block-weight-' . $region],
          ],
        ];
        // Add the operation links.
        $operations = [];

        $row['operations'] = [
          '#type' => 'operations',
          '#links' => $operations,
        ];

        $regions[$content_id] = $row;
      }
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
