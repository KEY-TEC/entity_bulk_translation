<?php
/**
 * @file
 * Drush commands for entity bulk translations.
 */

/**
 * Implements hook_drush_command().
 */
function entity_bulk_translation_drush_command() {
  $items = array();
  $items['entity-bulk-translation-translate'] = [
    'description' => 'Translate entties from a source language to target language',
    'arguments' => [
      'entity_type' => 'The entity type.',
      'bundles' => 'A comma seperated list of bundles or use "all" for all bundles.',
      'source language' => 'The source language',
      'target language' => 'The target language',
    ],
    'required-arguments' => 4,
    'aliases' => ['ebt']
  ];

  return $items;
}

/**
 * Validation helper function for the translation drush command
 * @param \Drupal\Core\Language\LanguageInterface[] $languages List of all valid languages.
 * @param string $langcode Language to check against e.g. en
*/
function _drush_entity_bulk_translation_validate_language($languages, $langcode) {
  if (!isset($languages[$langcode])) {
    return drush_set_error('DRUSH_EBT', dt('Language "@language" does not exist. Available languages are: @languages', array(
      '@language' => $langcode,
      '@languages' => join (', ',array_keys($languages)))));
  }
  return TRUE;
}

/**
 * Translate entities from a source language to target language
 * @param string $entity_type The entity_type
 * @param string $bundles A comma separated list of bundles or all for all language
 * @param string $from_language The source language
 * @param string $to_language The target language
 */
function drush_entity_bulk_translation_translate($entity_type, $bundles, $from_language, $to_language) {
  $languages = Drupal::languageManager()->getLanguages();
  if (!_drush_entity_bulk_translation_validate_language($languages, $from_language) || !_drush_entity_bulk_translation_validate_language($languages, $to_language)) {
    exit(1);
  }
  $query = \Drupal::entityQuery($entity_type);
  if ($bundles != 'all') {
    $bundles_ary = explode(',', $bundles);
    $query->condition('type', $bundles_ary);
  }
  $query->condition('langcode', $from_language);

  $ids = $query->execute();

  $chunked_ids = array_chunk(array_values($ids), 10);

  $storage = \Drupal::entityTypeManager()
    ->getStorage($entity_type);

  $translated_entities = 0;
  $skipped_entities = 0;

  foreach ($chunked_ids as $ids) {
    $entities = $storage->loadMultiple($ids);
    /* @var \Drupal\Core\Entity\Entity $entity */
    foreach ($entities as $entity) {

      if ($entity->hasTranslation($to_language)) {
        $skipped_entities++;
        continue;
      }
      $source_translation = $entity->getTranslation($from_language);

      /* @var \Drupal\Core\Entity\EntityInterface $translation */
      $translation = $entity->addTranslation($to_language, $source_translation->toArray());
      $translation->save();
      $translated_entities++;
    }
    // Entity storage can blow up with caches so clear them out.
    $container = \Drupal::getContainer();
    /** @var \Drupal\Core\Entity\EntityManagerInterface $manager */
    $manager = $container->get('entity.manager');
    foreach ($manager->getDefinitions() as $id => $definition) {
      $manager->getStorage($id)->resetCache();
    }
    drupal_static_reset();

    drush_print(t('Created @count translations.', array('@count' => $translated_entities)));
    drush_print(t('Skipped @count translations.', array('@count' => $skipped_entities)));
  }
}
