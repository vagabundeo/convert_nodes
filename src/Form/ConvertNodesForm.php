<?php

namespace Drupal\convert_nodes\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\convert_nodes\ConvertNodes;

/**
 *
 */
class ConvertNodesForm extends FormBase implements FormInterface {

  /**
   * Set a var to make this easier to keep track of.
   */
  protected $step = 1;
  /**
   * Set some content type vars.
   */
  protected $from_type = NULL;
  protected $to_type = NULL;
  /**
   * Set field vars.
   */
  protected $fields_from = NULL;
  protected $fields_to = NULL;
  /**
   * Create new based on to content type.
   */
  protected $create_new = NULL;
  protected $fields_new_to = NULL;
  /**
   * Keep track of user input.
   */
  protected $userInput = [];

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'convert_nodes_admin';
  }

  /**
   *
   */
  public function _convertNodes() {
    $base_table_names = ConvertNodes::getBaseTableNames();
    $userInput = ConvertNodes::sortUserInput($this->userInput, $this->fields_new_to, $this->fields_from);
    $map_fields = $userInput['map_fields'];
    $update_fields = $userInput['update_fields'];
    $field_table_names = ConvertNodes::getFieldTableNames($this->fields_from);
    $nids = ConvertNodes::getNids($this->from_type);
    $map_fields = ConvertNodes::getOldFieldValues($nids, $map_fields, $this->fields_to);
    $batch = [
      'title' => t('Converting Base Tables...'),
      'operations' => [
        ['\Drupal\convert_nodes\ConvertNodes::convertBaseTables', [$nids, $base_table_names, $this->to_type]],
        ['\Drupal\convert_nodes\ConvertNodes::convertFieldTables', [$nids, $field_table_names, $this->to_type, $update_fields]],
        ['\Drupal\convert_nodes\ConvertNodes::addNewFields', [$nids, $map_fields]],
      ],
      'finished' => '\Drupal\convert_nodes\ConvertNodes::ConvertNodesFinishedCallback',
    ];
    batch_set($batch);
    return 'All nodes of type ' . $this->from_type . ' were converted to ' . $this->to_type;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    switch ($this->step) {
      case 1:
        $form_state->setRebuild();
        $this->from_type = $form['convert_nodes_content_type_from']['#value'];
        $this->to_type = $form['convert_nodes_content_type_to']['#value'];
        break;

      case 2:
        $form_state->setRebuild();
        $this->userInput = $form_state->getValues();
        break;

      case 3:
        $this->create_new = $form['create_new'];
        if (empty($this->create_new)) {
          goto five;
        }
        $form_state->setRebuild();
        break;

      case 4:
        $this->userInput = array_merge($this->userInput, $form_state->getValues());
        $form_state->setRebuild();
        break;

      case 5:
        // Used also for goto.
        five:
        if (method_exists($this, '_convertNodes')) {
          $return_verify = $this->_convertNodes();
        }
        drupal_set_message($return_verify);
        \Drupal::service("router.builder")->rebuild();
        break;
    }
    $this->step++;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    if (isset($this->form)) {
      $form = $this->form;
    }
    switch ($this->step) {
      case 1:
        drupal_set_message('This module is experiemental. PLEASE do not use on production databases without prior testing and a complete database dump.', 'warning');
        // Get content types and put them in the form.
        $contentTypesList = ConvertNodes::getContentTypes();
        $form['convert_nodes_content_type_from'] = [
          '#type' => 'select',
          '#title' => t('From Content Type'),
          '#options' => $contentTypesList,
        ];
        $form['convert_nodes_content_type_to'] = [
          '#type' => 'select',
          '#title' => t('To Content Type'),
          '#options' => $contentTypesList,
        ];
        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Next'),
          '#button_type' => 'primary',
        ];
        break;

      case 2:
        // Get the fields.
        $entityManager = \Drupal::service('entity_field.manager');
        $this->fields_from = $entityManager->getFieldDefinitions('node', $this->from_type);
        $this->fields_to = $entityManager->getFieldDefinitions('node', $this->to_type);

        $fields_to = ConvertNodes::getToFields($this->fields_to);
        $fields_to_names = $fields_to['fields_to_names'];
        $fields_to_types = $fields_to['fields_to_types'];

        $fields_from = ConvertNodes::getFromFields($this->fields_from, $fields_to_names, $fields_to_types);
        $fields_from_names = $fields_from['fields_from_names'];
        $fields_from_form = $fields_from['fields_from_form'];

        // Find missing fields. allowing values to be input later.
        $fields_to_names = array_diff($fields_to_names, ['append_to_body', 'remove']);
        $this->fields_new_to = array_diff(array_keys($fields_to_names), $fields_from_names);

        $form = array_merge($form, $fields_from_form);
        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Next'),
          '#button_type' => 'primary',
        ];
        break;

      case 3:
        $form['create_new'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Create field values for fields in new content type'),
        ];
        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Next'),
          '#button_type' => 'primary',
        ];
        break;

      case 4:
        $entityManager = \Drupal::service('entity_field.manager');
        // Put the to fields in the form for new values.
        foreach ($this->fields_new_to as $field_name) {
          if (!in_array($field_name, $this->userInput)) {
            // TODO Need to figure out a way to get form element based on field def here
            // for now just a textfield
            /*
            $field = $entityManager->getFieldDefinitions('node', $this->to_type)[$field_name];
            $field_type = $field->getFieldStorageDefinition()->getType();
            $field_options = $field->getFieldStorageDefinition()->getSettings();
            $element = array (
            '#title' => 'test',
            '#description' => 'some desc',
            '#required' => true,
            '#delta' => 0,
            );
            $test = WidgetBase('test', 'test', $field, array(), array());
            $test->formElement($field, 0, $element, $form, $form_state);
             */
            $form[$field_name] = [
              '#type' => 'textfield',
              '#title' => t('Set Field [' . $field_name . ']'),
            ];
          }
        }
        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Next'),
          '#button_type' => 'primary',
        ];
        break;

      case 5:
        drupal_set_message('This module is experiemental. PLEASE do not use on production databases without prior testing and a complete database dump.', 'warning');
        $form['actions']['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Convert'),
          '#button_type' => 'primary',
        ];
        break;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $this->from_type = $form['convert_nodes_content_type_from']['#value'];
    $query = \Drupal::entityQuery('node')->condition('type', $this->from_type);
    $count_type = $query->count()->execute();
    if ($count_type == 0) {
      $form_state->setErrorByName('convert_nodes_content_type_from', $this->t('No content found to convert.'));
    }

  }

}
