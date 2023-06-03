<?php

namespace Drupal\openlibrary_search\Command;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\openlibrary_search\Service\OpenLibraryService;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A drush command file.
 *
 * @package Drupal\openlibrary_search\Command
 */
class OpenLibraryCommand extends DrushCommands {

  use StringTranslationTrait;

  /**
   * Var that store the h2p service.
   *
   * @var \Drupal\openlibrary_search\Service\OpenLibraryService
   */
  protected $openLibrarySearchService;

  /**
   * {@inheritdoc}
   */
  public function __construct(OpenLibraryService $openLibrarySearchService) {
    $this->openLibrarySearchService = $openLibrarySearchService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Instantiates this class.
    return new static(
      $container->get('openlibrary_search.search_service')
    );
  }

  /**
   * Drush command to test the get requesto to the open library API.
   *
   * @command openlibrary:test
   * @aliases opt
   * @usage openlibrary:test --title --author
   */
  public function test($options = [
    'title' => '',
    'author' => '',
  ]) {
    $response = $this->openLibrarySearchService->getDataFromApi($options['title'], $options['author']);
    echo print_r($response, TRUE);
  }

}
