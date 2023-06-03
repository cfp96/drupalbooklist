<?php

namespace Drupal\openlibrary_search\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\taxonomy\TermStorageInterface;
use Drupal\taxonomy\VocabularyInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service class for Open Library API integration.
 */
class OpenLibraryService {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The HTTP client factory.
   *
   * @var \Drupal\Core\Http\ClientFactory
   */
  protected $httpClientFactory;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs an OpenLibraryService object.
   *
   * @param \Drupal\Core\Http\ClientFactory $http_client_factory
   *   The HTTP client factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   */
  public function __construct(
    ClientFactory $http_client_factory,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    MessengerInterface $messenger
    ) {
    $this->httpClientFactory = $http_client_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->messenger = $messenger;
  }

  /**
   * Get the data from Open Library API.
   *
   * @param string $title
   *   The book title.
   * @param string $author
   *   The book author.
   *
   * @return array
   *   The book data as an array.
   */
  public function getDataFromApi($title, $author) {
    // Prepare the parameters for the API request.
    $params = [];
    if (!empty($title)) {
      $params['title'] = $title;
    }
    if (!empty($author)) {
      $params['author'] = $author;
    }

    // Build the URL for the API request following the rules.
    $url = 'https://openlibrary.org/search.json';
    if (!empty($params)) {
      $queryString = http_build_query($params);
      $url .= '?' . str_replace('%2B', '+', $queryString);
    }

    try {
      $client = $this->httpClientFactory->fromOptions();

      // Send a GET request to the Open Library API.
      $response = $client->get($url);

      // Get the response body.
      $body = $response->getBody()->getContents();
      $data = json_decode($body, TRUE);

      // Extract the relevant book information.
      $books = [];
      if (isset($data['docs'])) {
        foreach ($data['docs'] as $doc) {
          $book = [
            'title' => isset($doc['title']) ? $doc['title'] : '',
            'author' => isset($doc['author_name']) ? $doc['author_name'] : '',
            'description' => isset($doc['description']) ? $doc['description'] : '',
            'first_publish_year' => isset($doc['first_publish_year']) ? $doc['first_publish_year'] : '',
          ];
          $books[] = $book;
        }
      }

      return $books;
    } catch (RequestException $e) {
      $error = $e->getMessage();
      return $error;
    }
  }

  /**
   * Method to create a node of type book with the data from the API.
   *
   * @param string $title
   *   The book title.
   * @param string $author
   *   The book author.
   */
  public function createBookInDrupal($title, $author) {
    $books = $this->getDataFromApi($title, $author);

    if (empty($books) || !is_array($books)) {
      if (is_string($books)) {
        $this->messenger->addMessage($this->t($books), MessengerInterface::TYPE_WARNING);
      }
      else {
        $this->messenger->addMessage($this->t('No books found for the given search criteria.'), MessengerInterface::TYPE_WARNING);
      }
    }
    else {
      if ($this->checkIfBookAlreadyExist($title, $author)) {
        return FALSE;
      }
      else {
        foreach ($books as $book) {
          // this is for prevent titles with more characters.
          $maxTitleLength = 255;
          $truncatedTitle = mb_strimwidth($book['title'], 0, $maxTitleLength, '...');

          //this is to store the author in a taxonomy term
          $authorTermStorage = $this->entityTypeManager->getStorage('taxonomy_term');
          $authorsVocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load('authors');

          $bookNode = \Drupal\node\Entity\Node::create([
            'type' => 'book',
            'title' => $truncatedTitle,
            'field_author_new' => [
              'target_id' => $this->getOrCreateAuthorTerm($book['author'], $authorTermStorage, $authorsVocabulary),
            ],
            'field_first_publish_year' => $book['first_publish_year'],
            'body' => [
              'value' => $book['description'],
              'format' => 'plain_text',
            ],
          ]);

          $bookNode->save();
        }
      }
    }
  }

  /**
   * Verify if a node of type book already exist.
   *
   * @param string $title
   *   The book title.
   * @param string $author
   *   The book author.
   *
   * @return bool
   *   TRUE if the book already exists, FALSE otherwise.
   */
  public function checkIfBookAlreadyExist($title, $author) {
    $query = $this->entityTypeManager->getStorage('node')
    ->getQuery()
    ->condition('type', 'book')
    ->condition('title', $title);

    // Get the taxonomy term ID for the author.
    $authorTermId = $this->getAuthorTermId($author);
    if ($authorTermId) {
      $query->condition('field_author_new.target_id', $authorTermId);
    }

    $result = $query->execute();
    return !empty($result);
  }

  /**
   * Method to create a new taxonomy term or return an already created.
   *
   * @param string $authorName
   *   The author name.
   * @param \Drupal\taxonomy\TermStorageInterface $termStorage
   *   The taxonomy term storage.
   * @param \Drupal\taxonomy\VocabularyInterface $vocabulary
   *   The taxonomy vocabulary.
   *
   * @return int
   *   The taxonomy term ID.
   */
  protected function getOrCreateAuthorTerm(
    $authorName,
    TermStorageInterface $termStorage,
    VocabularyInterface $vocabulary
  ) {
    $term = $termStorage->loadByProperties([
      'name' => $authorName,
      'vid' => $vocabulary->id(),
    ]);

    if (empty($term)) {
      $term = $termStorage->create([
        'name' => $authorName,
        'vid' => $vocabulary->id(),
      ]);
      $term->save();
    } else {
      $term = reset($term);
    }

    return $term->id();
  }

  /**
   * Get the taxonomy term ID for the author.
   *
   * @param string $author
   *   The author name.
   *
   * @return int|bool
   *   The taxonomy term ID if found, or FALSE otherwise.
   */
  public function getAuthorTermId($author) {
    $authorTerm = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties([
        'name' => $author,
        'vid' => 'authors',
      ]);

    if (!empty($authorTerm)) {
      $authorTerm = reset($authorTerm);
      return $authorTerm->id();
    }

    return FALSE;
  }

}
