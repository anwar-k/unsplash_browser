<?php

namespace Drupal\unsplash_browser\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;


/**
 * Configure unsplash to enable OAuth based access.
 *
 * @package Drupal\unsplash_browser\Form
 */
class Unsplash_browserConfigurationForm extends ConfigFormBase {


  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'unsplash_browser_configuration_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['unsplash.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('unsplash.settings');

    $form['appid'] = [
      '#required' => TRUE,
      '#type' => 'textfield',
      '#title' => $this->t('Application ID'),
      '#parents' => ['credentials', 'appid'],
      '#default_value' => $config->get('appid'),

    ];
    $form['appsecret'] = [
      '#required' => TRUE,
      '#type' => 'textfield',
      '#title' => $this->t('Secret'),
      '#parents' => ['credentials', 'appsecret'],
      '#default_value' => $config->get('appsecret'),

    ];


    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    

    $credentials = $form_state->getValue('credentials');
    $this->config('unsplash.settings')
      ->set('appid', $credentials['appid'])
      ->set('appsecret', $credentials['appsecret'])
      ->save();
    parent::submitForm($form, $form_state);
  }
}
