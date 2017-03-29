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
   * The array of nodes to process.
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

  private $isTranslationCreated = 1;
  private $isTranslationExists = 2;
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
    $lngOptions = array();
    foreach($languages as $language) {
      $lngOptions[$language->getId()] = $language->getName();
    }

    $items = [];
    foreach ($this->entityInfo as $id => $node) {
      $items[$id] = $node->label();
    }

    $form['nodescount'] = array('#markup' => 'Selected '.count($this->entityInfo).' (without translation duplicates)');
    $form['nodes'] = array(
      '#theme' => 'item_list',
      '#items' => $items,
    );

    $form['fromLanguage'] = array(
      '#title' => $this->t('Source Language'),
      '#type' => 'select',
      '#options' => $lngOptions,
      '#description' => $this->t('Select the language you want the translation to be created from. If the language does not exist on the entity, it will be skipped.'),
    );

    $form['toLanguage'] = array(
      '#title' => $this->t('Target Language'),
      '#type' => 'select',
      '#options' => $lngOptions,
      '#description' => $this->t('Select the language you want the translation to be create for. If a translation for this language already exists, it will be skipped.'),
    );

    $form['forceTranslation'] = array(
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
      $statusCount = array($this->isTranslationCreated=>0, $this->isTranslationExists=>0, $this->isTranslationSourceNotExists=>0);
      $fromLanguage = $form_state->getValue('fromLanguage');
      $toLanguage = $form_state->getValue('toLanguage');
      $force = $form_state->getValue('forceTranslation');

      foreach ($this->entityInfo as $id => $entity) {
        $statusCount[$this->createTranslation($entity, $fromLanguage, $toLanguage, $force)]++;
      }

      if($statusCount[$this->isTranslationCreated]) {
        $this->logger('content')->notice('Created translations: @count.', array('@count' => $statusCount[$this->isTranslationCreated]));
        drupal_set_message($this->t('Created @count translations.', array('@count' => $statusCount[$this->isTranslationCreated])));
      }
      if($statusCount[$this->isTranslationExists]) {
        drupal_set_message($this->t('Skipped @count, because target language @tlng already existed.', array('@count' => $statusCount[$this->isTranslationExists], '@tlng' => $toLanguage)));
      }
      if($statusCount[$this->isTranslationSourceNotExists]) {
        drupal_set_message($this->t('Skipped @count, because source language @flng didn\'t exist.', array('@count' => $statusCount[$this->isTranslationSourceNotExists], '@flng' => $fromLanguage)));
      }
    }

    $form_state->setRedirect('system.admin_content');
  }

  /**
   * Creates a translation for an content entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityBase $entity
   * @param string $fromLanguage
   *   Language code e.g. 'en'
   * @param string $toLanguage
   * @param bool $force
   *   Delete and recreate an existing translation instead of skipping
   * @return int status
  */
  private function createTranslation(ContentEntityBase $entity, $fromLanguage, $toLanguage, $force) {
    if(!$entity->hasTranslation($fromLanguage)) {
      return $this->isTranslationSourceNotExists;
    }

    if($entity->hasTranslation($toLanguage)) {
      if($force) {
        $entity->removeTranslation($toLanguage);
      }else {
        return $this->isTranslationExists;
      }
    }

    $sourceTranslation = $entity->getTranslation($fromLanguage);

    /* @var \Drupal\Core\Entity\EntityInterface $translation */
    $translation = $entity->addTranslation($toLanguage, $sourceTranslation->toArray());
    $translation->save();
    return $this->isTranslationCreated;
  }

}
