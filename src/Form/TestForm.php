<?php

namespace Drupal\entity_layout\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class TestForm extends FormBase {

  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'entity_layout_test_form';
  }

  /**
   * Form constructor.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $layout = [
      [
        'id' => 'region_1',
        'label' => 'Region 1',
        'type' => 'region',
        'items' => [
          [
            'id' => 'item_3',
            'label' => 'Item 3',
            'weight' => 120,
            'type' => 'item',
            'parent' => 'region_1'
          ],
        ],
      ],
      [
        'id' => 'region_2',
        'label' => 'Region 2',
        'type' => 'region',
        'items' => [
          [
            'id' => 'item_1',
            'label' => 'Item 1',
            'weight' => 11,
            'type' => 'item',
            'parent' => 'region_2'
          ],
          [
            'id' => 'item_2',
            'label' => 'Item 2',
            'weight' => 12,
            'type' => 'item',
            'parent' => 'region_2'
          ],
        ],
      ],
    ];

    $form['mytable'] = array(
      '#type' => 'table',
      '#header' => [
        [
          'data' => t('Label'),
          'colspan' => 3,
        ],
        t('Weight')
      ],
      '#empty' => t('There are no items yet. Add an item.', array(
        '@add-url' => Url::fromRoute('entity_layout.entity_layout_test_form'),
      )),
    );

    foreach ($layout as $region) {
      $id = $region['id'];
      $label = $region['label'];
      $type = $region['type'];

      $form['mytable']['#tabledrag'][] = [
        'action' => 'order',
        'relationship' => 'sibling',
        'group' => 'item-weight',
        'subgroup' => 'item-weight-' . $id,
      ];

      $form['mytable'][$id]['#attributes']['data-region-id'] = $id;

      $form['mytable'][$id]['id'] = [
        '#type' => 'hidden',
        '#value' => $id,
        '#attributes' => [
          'class' => [
            'region-id-' . $id
          ],
        ],
      ];

      $form['mytable'][$id]['label'] = array(
        '#plain_text' => $label,
        '#wrapper_attributes' => [
          'colspan' => 3
        ],
      );

      if ('region' === $type && array_key_exists('items', $region)) {
        /** @var array $items */
        $items = $region['items'];
        foreach ($items as $item) {
          $item_id = $item['id'];
          $item_weight = $item['weight'];
          $item_parent = $item['parent'];
          $item_label = $item['label'];

          $form['mytable'][$item_id]['#attributes']['class'][] = 'draggable';
          $form['mytable'][$item_id]['#attributes']['class'][] = 'tabledrag-leaf';

          $form['mytable'][$item_id]['id'] = [
            '#type' => 'hidden',
            '#value' => $item_id,
            '#attributes' => [
              'class' => [
                'item-id',
              ],
            ]
          ];

          $form['mytable'][$item_id]['label'] = [
            [
              '#plain_text' => $item_label,
            ],
          ];

          $form['mytable'][$item_id]['parent'] = [
            '#type' => 'textfield',
            '#default_value' => $item_parent,
            '#attributes' => [
              'data-region-id-input' => true,
            ],
          ];

          $form['mytable'][$item_id]['weight'] = array(
            '#type' => 'weight',
            '#title' => t('Weight for @title', array('@title' => $item_label)),
            '#title_display' => 'invisible',
            '#default_value' => $item_weight,
            '#delta' => 1000,
            '#attributes' => [
              'class' => [
                'item-weight',
                'item-weight-' . $item_parent,
              ]
            ],
          );
        }
      }

    }

    $form['mytable']['#attributes']['id'] = 'test-form-tabledrag';
    $form['mysecondtable'] = $form['mytable'];
    $form['mysecondtable']['#attributes']['id'] = 'test-form-tabledrag-clone';

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => 'Go',
    ];

//    $form['#attached']['library'][] = 'entity_layout/tabledrag_override';

    return $form;
  }

  /**
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    ksm($form_state->getValue('mytable'));
    ksm($form_state->getValue('mysecondtable'));
  }
}
