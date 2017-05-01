<?php

namespace Drupal\unsplash_browser\Plugin\EntityBrowser\Widget;

use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Utility\Token;
use Drupal\dropzonejs\DropzoneJsUploadSaveInterface;
use Drupal\entity_browser\WidgetBase;
use Drupal\entity_browser\WidgetValidationManager;
use Drupal\entity_browser\Element\EntityBrowserPagerElement;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use GuzzleHttp\Client;
use Drupal\Core\Link;

/**
 * Provides an Entity Browser widget that uploads new files.
 *
 * @EntityBrowserWidget(
 *   id = "unsplash",
 *   label = @Translation("unsplash"),
 *   description = @Translation("Adds DropzoneJS upload integration."),
 *   auto_select = TRUE
 * )
 */
class UnsplashWidget extends WidgetBase {

  /**
   * DropzoneJS module upload save service.
   *
   * @var \Drupal\dropzonejs\DropzoneJsUploadSaveInterface
   */
  protected $dropzoneJsUploadSave;

  /**
   * Current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Constructs widget plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   Event dispatcher service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\entity_browser\WidgetValidationManager $validation_manager
   *   The Widget Validation Manager service.
   * @param \Drupal\dropzonejs\DropzoneJsUploadSaveInterface $dropzonejs_upload_save
   *   The upload saving dropzonejs service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user service.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EventDispatcherInterface $event_dispatcher, EntityTypeManagerInterface $entity_type_manager, WidgetValidationManager $validation_manager, DropzoneJsUploadSaveInterface $dropzonejs_upload_save, AccountProxyInterface $current_user, Token $token) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $event_dispatcher, $entity_type_manager, $validation_manager);
    $this->dropzoneJsUploadSave = $dropzonejs_upload_save;
    $this->currentUser = $current_user;
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('event_dispatcher'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.entity_browser.widget_validation'),
      $container->get('dropzonejs.upload_save'),
      $container->get('current_user'),
      $container->get('token')
      );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
    'media_bundle' => NULL,
    'upload_location' => 'public://unsplash',
    'max_filesize' => file_upload_max_size() / pow(Bytes::KILOBYTE, 2) . 'M',
    'extensions' => 'jpg jpeg gif png',
    ] + parent::defaultConfiguration();
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['items_per_page'] = [
    '#type' => 'select',
    '#title' => $this->t('Items per page'),
    '#default_value' => $this->configuration['items_per_page'],
    '#options' => ['10' => 10, '15' => 15, '25' => 25, '50' => 50],
    ];

    $form['media_bundle'] = [
    '#type' => 'select',
    '#title' => $this->t('Media bundle'),
    '#default_value' => $this->configuration['media_bundle'],
    '#required' => TRUE,
    '#options' => [],
    ];

    foreach ($this->entityTypeManager->getStorage('media_bundle')->loadMultiple() as $bundle) {
      /** @var \Drupal\media_entity\MediaBundleInterface $bundle */
      $form['media_bundle']['#options'][$bundle->id()] = $bundle->label();
    }

    if (empty($form['media_bundle']['#options'])) {
      $form['media_bundle']['#disabled'] = TRUE;
      $form['items_per_page']['#disabled'] = TRUE;
      $form['media_bundle']['#description'] = $this->t('You must @create_bundle before using this widget.', [
        '@create_bundle' => Link::createFromRoute($this->t('create an Unsplash media bundle'), 'entity.media_bundle.add_form')->toString(),
        ]);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareEntities(array $form, FormStateInterface $form_state) {
    // if (!$this->checkBundle()) {
    //   return [];
    // }
    $media = [];
    $selected_ids = array_keys(array_filter($form_state->getValue('selection', [])));
    /** @var \Drupal\media_entity\MediaBundleInterface $bundle */
    $bundle = $this->entityTypeManager->getStorage('media_bundle')
    ->load($this->configuration['media_bundle']);
    $plugin = $bundle->getType();
    $source_field = $plugin->getConfiguration()['source_field'];
    ddl($source_field);
    foreach ($selected_ids as $bynder_id) {
      $media[] = Media::create([
        'bundle' => $bundle->id(),
        $source_field => $bynder_id,
        ]);
    }
    return $media;
  }

  /**
   * {@inheritdoc}
   */
  public function getForm(array &$original_form, FormStateInterface $form_state, array $additional_widget_parameters) {
    $form = parent::getForm($original_form, $form_state, $additional_widget_parameters);

    if ($form_state->getValue('errors')) {
      $form['actions']['submit']['#access'] = FALSE;
      return $form;
    }

    $form['filters'] = [
    '#type' => 'container',
    '#tree' => TRUE,
    '#attributes' => ['class' => 'unsplash-filters'],
    ];

    $form['filters']['search_unsplash'] = [
    '#type' => 'textfield',
    '#weight' => -1,
    '#title' => $this->t('Search keyword'),
    '#attributes' => [
    'size' => 30,
    ],
    ];

    $form['search_button'] = [
    '#type' => 'button',
    '#weight' =>  5,
    '#value' => $this->t('Search'),
    '#name' => 'search_submit',
    ];

    $form['thumbnails'] = [
    '#type' => 'container',
    '#weight' =>  10,
    '#attributes' => ['id' => 'thumbnails', 'class' => 'grid'],
    ];

    if ($form_state->getTriggeringElement()['#name'] == 'search_submit') {
      EntityBrowserPagerElement::setCurrentPage($form_state);
    }
    $page = EntityBrowserPagerElement::getCurrentPage($form_state);

    ddl($form);

    $query = [
    'keyword' => $form_state->getValue(['filters', 'search_unsplash']),
    'page' => $page,
    'client_id' => '25039e7b5b8cc989c2a48f439bed59104f8fb11807b6a297755ffe93f330aa4a',
    ];

    $client = \Drupal::httpClient();
    $request = $client->get('https://api.unsplash.com/search/photos?query=' . $query['keyword'] . '&page=' . $query['page'] . '&client_id=' . $query['client_id']);
    $response = json_decode($request->getBody());

    if (!empty($response->results)) {
      foreach ($response->results as $media) {

        $form['thumbnails']['thumbnail-' . $media->id] = [
        '#type' => 'container',
        '#attributes' => ['id' => $media->id, 'class' => ['grid-item']],
        ];
        $form['thumbnails']['thumbnail-' . $media->id]['check_' . $media->id] = [
        '#type' => 'checkbox',
        '#parents' => ['selection', $media->id],
        '#attributes' => ['class' => ['item-selector']],
        ];
        $form['thumbnails']['thumbnail-' . $media->id['image']] = [
        '#theme' => 'bynder_search_item',
        '#thumbnail_uri' => $media->urls->thumb,
        '#name' => $query['keyword'],
        ]  ;
      }

      $form['pager_eb'] = [
      '#type' => 'entity_browser_pager',
      '#total_pages' => $response->total_pages,
      '#weight' => 20,
      ];

      // Set validation errors limit to prevent validation of filters on select.
      // We also need to set #submit to the default submit callback otherwise
      // limit won't take effect. Thank you Form API, you are very kind...
      // @see \Drupal\Core\Form\FormValidator::determineLimitValidationErrors()
      $form['actions']['submit']['#limit_validation_errors'] = [['selection']];
      $form['actions']['submit']['#submit'] = ['::submitForm'];
    }
    else {
      $form['empty_message'] = [
      '#prefix' => '<div class="empty-message">',
      '#markup' => $this->t('Not assets found for current search criteria.'),
      '#suffix' => '</div>',
      '#weight' => $max_option_weight + 20,
      ];
      $form['actions']['submit']['#access'] = FALSE;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submit(array &$element, array &$form, FormStateInterface $form_state) {
    if (!empty($form_state->getTriggeringElement()['#eb_widget_main_submit'])) {
      try {
        $media = $this->prepareEntities($form, $form_state);
        array_walk($media, function (MediaInterface $media_item) {
          $media_item->save();
        });
        $this->selectEntities($media, $form_state);
      }
      catch (\UnexpectedValueException $e) {
        drupal_set_message($this->t('Bynder integration is not configured correctly. Please contact the site administrator.'), 'error');
      }
    }
  }

}