<?php

namespace Drupal\entity_bulk_translation\Form;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for the entity bulk translation action.
 */
class TranslationActionForm extends ConfirmFormBase {

  /**
   * The array of entites to process.
   *
   * @var []\Drupal\Core\Entity\EntityInterface
   */
  protected $entityInfo = array();

  /**
   * The tempstore factory.
   *
   * @var \Drupal\user\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * The node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $manager;

  /**
   * Status counter flag for created translations.
   *
   * @var integer
  */
  private $isTranslationCreated = 1;

  /**
   * Status counter flag for skipped entities when the translation already exists.
   *
   * @var integer
   */
  private $isTranslationExists = 2;

  /**
   * Status counter flag for skipped entities where required source translation didn't exist.
   *
   * @var integer
   */
  private $isTranslationSourceNotExists = 3;

  /**
   * Constructs a TranslationActionForm form object.
   *
   * @param \Drupal\user\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   * @param \Drupal\Core\Entity\EntityManagerInterface $manager
   *   The entity manager.
   */
  public function __construct(PrivateTempStoreFactory $temp_store_factory, EntityManagerInterface $manager) {
    $this->tempStoreFactory = $temp_store_factory;
    $this->storage = $manager->getStorage('node');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user.private_tempstore'),
      $container->get('entity.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'translation_action_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Translation Configuration');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('system.admin_content');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return t('Create Translation');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $this->entityInfo = $this->tempStoreFactory->get('entity_bulk_translation_action')->get(\Drupal::currentUser()->id());
    $languages = \Drupal::languageManager()->getLanguages();
    $lng_options = array();
    foreach($languages as $language) {
      $lng_options[$language->getId()] = $language->getName();
    }

    $items = [];
    foreach ($this->entityInfo as $id => $node) {
      $items[$id] = $node->label();
    }

    $form['nodes_count'] = array('#markup' => 'Selected '.count($this->entityInfo).' (without translation duplicates)');
    $form['nodes'] = array(
      '#theme' => 'item_list',
      '#items' => $items,
    );

    $form['from_language'] = array(
      '#title' => $this->t('Source Language'),
      '#type' => 'select',
      '#options' => $lng_options,
      '#description' => $this->t('Select the language you want the translation to be created from. If the language does not exist on the entity, it will be skipped.'),
    );

    $form['to_language'] = array(
      '#title' => $this->t('Target Language'),
      '#type' => 'select',
      '#options' => $lng_options,
      '#description' => $this->t('Select the language you want the translation to be create for. If a translation for this language already exists, it will be skipped.'),
    );

    $form['force_translation'] = array(
      '#title' => $this->t('Delete existing translation'),
      '#type' => 'checkbox',
      '#description' => $this->t('Check this option to delete and recreate a translation instead of skipping.'),
    );

    $form = parent::buildForm($form, $form_state);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if($form_state->getValue('fromLanguage') == $form_state->getValue('toLanguage')) {
      $form_state->setErrorByName('toLanguage', $this->t('The source language and target language cannot be the same.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if($form_state->getValue('confirm') && !empty($this->entityInfo)) {
      $status_count = array($this->isTranslationCreated=>0, $this->isTranslationExists=>0, $this->isTranslationSourceNotExists=>0);
      $from_language = $form_state->getValue('from_language');
      $to_language = $form_state->getValue('to_language');
      $force = $form_state->getValue('force_translation');

      foreach ($this->entityInfo as $id => $entity) {
        $status_count[$this->createTranslation($entity, $from_language, $to_language, $force)]++;
      }

      if($status_count[$this->isTranslationCreated]) {
        $this->logger('content')->notice('Created translations: @count.', array('@count' => $status_count[$this->isTranslationCreated]));
        drupal_set_message($this->t('Created @count translations.', array('@count' => $status_count[$this->isTranslationCreated])));
      }
      if($status_count[$this->isTranslationExists]) {
        drupal_set_message($this->t('Skipped @count, because target language @tlng already existed.', array('@count' => $status_count[$this->isTranslationExists], '@tlng' => $to_language)));
      }
      if($status_count[$this->isTranslationSourceNotExists]) {
        drupal_set_message($this->t('Skipped @count, because source language @flng didn\'t exist.', array('@count' => $status_count[$this->isTranslationSourceNotExists], '@flng' => $from_language)));
      }
    }

    $form_state->setRedirect('system.admin_content');
  }

  /**
   * Creates a translation for an content entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityBase $entity
   * @param string $from_language
   *   Language code e.g. 'en'
   * @param string $to_language
   * @param bool $force
   *   Delete and recreate an existing translation instead of skipping
   * @return int status
  */
  private function createTranslation(ContentEntityBase $entity, $from_language, $to_language, $force) {
    if(!$entity->hasTranslation($from_language)) {
      return $this->isTranslationSourceNotExists;
    }

    if($entity->hasTranslation($to_language)) {
      if($force) {
        $entity->removeTranslation($to_language);
      }else {
        return $this->isTranslationExists;
      }
    }

    $sourceTranslation = $entity->getTranslation($from_language);

    /* @var \Drupal\Core\Entity\EntityInterface $translation */
    $translation = $entity->addTranslation($to_language, $sourceTranslation->toArray());
    $translation->save();
    return $this->isTranslationCreated;
  }

}
