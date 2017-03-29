<?php

namespace Drupal\entity_bulk_translation\Plugin\Action;

use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unpublishes a comment containing certain keywords.
 *
 * @Action(
 *   id = "entity_bulk_translation_action",
 *   label = @Translation("Creates multiple translations of entities"),
 *   type = "entity",
 *   confirm_form_route_name = "entity_bulk_translation.translation_action_form"
 * )
 */
class EntityBulkTranslation extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  /**
   * The tempstore object.
   *
   * @var \Drupal\user\SharedTempStore
   */
  protected $tempStore;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new EntityBulkTranslation object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\user\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   * @param AccountInterface $current_user
   *   Current user.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, PrivateTempStoreFactory $temp_store_factory, AccountInterface $current_user) {
    $this->currentUser = $current_user;
    $this->tempStore = $temp_store_factory->get('entity_bulk_translation_action');

    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('user.private_tempstore'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $entities) {
    $info = [];
    /** @var \Drupal\Core\Entity\EntityInterface $entity */
    foreach ($entities as $entity) {
      $info[$entity->id()] = $entity;
    }
    $this->tempStore->set($this->currentUser->id(), $info);
  }

  /**
   * {@inheritdoc}
   */
  public function execute($object = NULL) {
    $this->executeMultiple(array($object));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return array(
      'fromLanguage' => NULL,
      'toLanguage' => NULL,
      'forceTranslation' => FALSE,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $languages = \Drupal::languageManager()->getLanguages();
    $lngOptions = array();
    foreach($languages as $language) {
      $lngOptions[$language->getId()] = $language->getName();
    }

    $form['fromLanguage'] = array(
      '#title' => $this->t('Source Language'),
      '#type' => 'select',
      '#options' => $lngOptions,
      '#description' => $this->t('Select the language you want the translation to be created from. If the language does not exist on the entity, it will be skipped.'),
      '#default_value' => $this->configuration['fromLanguage'],
    );

    $form['toLanguage'] = array(
      '#title' => $this->t('Target Language'),
      '#type' => 'select',
      '#options' => $lngOptions,
      '#description' => $this->t('Select the language you want the translation to be create for. If a translation for this language already exists, it will be skipped.'),
      '#default_value' => $this->configuration['fromLanguage'],
    );

    $form['forceTranslation'] = array(
      '#title' => $this->t('Delete existing translation'),
      '#type' => 'checkbox',
      '#description' => $this->t('Check this option to delete and recreate a translation instead of skipping.'),
      '#default_value' => $this->configuration['forceTranslation'],
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['fromLanguage'] = $form_state->getValue('fromLanguage');
    $this->configuration['toLanguage'] = $form_state->getValue('toLanguage');
    $this->configuration['forceTranslation'] = $form_state->getValue('forceTranslation');
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    /** @var $object = \Drupal\Core\Entity */
    $result = $object->access('update', $account, TRUE)
      ->andIf($object->status->access('edit', $account, TRUE));

    return $return_as_object ? $result : $result->isAllowed();
  }
}
