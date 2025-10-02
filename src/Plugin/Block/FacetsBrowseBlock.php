<?php

namespace Drupal\facets_browse\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing;
use Drupal\Core\Url;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Form\FormBase;
// use Drupal\search_api\ServerInterface;
use Drupal\search_api\Entity\Index;
use Drupal\Core\Link;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;

/**
 * Provides a browse facets block with config
 *
 * @Block(
 *   id = "facets_browse",
 *   admin_label = @Translation("Facets Browse"),
 *   category = @Translation("Search"),
 * )
 */
class FacetsBrowseBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected $random_key;

  /**
   * Form builder service.
   *
   * @var Drupal\Core\Plugin\ContainerFactoryPluginInterface
   */
  protected $formBuilder;

  /**
   * Constructor.
   *
   * @param array $configuration
   *   Configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The id for the plugin.
   * @param mixed $plugin_definition
   *   The definition of the plugin implementaton.
   * @param Drupal\Core\Form\FormBuilderInterface $formBuilder
   *   The "form_builder" service instance to use.
   */
  public function __construct(
      array $configuration,
      $plugin_id,
      $plugin_definition,
      FormBuilderInterface $formBuilder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->formBuilder = $formBuilder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('form_builder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $blockConfig = $this->getConfiguration();
    $facets_path = $blockConfig['facets_path'];
    $search_index = $blockConfig['search_index'];
    if (empty($search_index)) {
      \Drupal::logger('facet_browse')->info(json_encode($blockConfig));
      return [];
    }
    $facet_field = $blockConfig['facet_field'];
    $solr_field = $blockConfig['solr_field'];
    if (empty($solr_field)) {
      \Drupal::logger('facet_browse')->info(json_encode($blockConfig));
      return [];
    }
    $collection = !empty($blockConfig['collection']) ? $blockConfig['collection'] : null;
    $block_title = !empty($blockConfig['block_title']) ? $blockConfig['block_title'] : null;
    $show_counts = !empty($blockConfig['show_counts']) ? $blockConfig['show_counts'] : null;
    $collapsed = !empty($blockConfig['collapsed']) ? $blockConfig['collapsed'] : null;

    $index = Index::load($search_index);
    $fields = $index->getFields();
    $backend = $index->getServerInstance()->getBackend();
    $connector = $backend->getSolrConnector();
    $query = $connector->getSelectQuery();

    $facetSet = $query->getFacetSet();
    if (!empty($collection)) {
      $query->createFilterQuery('presentation_set_label')->setQuery('presentation_set_label:"'. $collection . '"')->addTag("collection");
      $query->createFilterQuery('is_discoverable')->setQuery('is_discoverable:true')->addTag("discoverable");
      $facetSet->createFacetField($solr_field)->setField($solr_field)->setLimit(300);
    } else {
      $facetSet->createFacetField($solr_field)->setField($solr_field)->setLimit(300);
    }
    $facetSet->setMinCount(1);

    $results = $connector->execute($query);

    $facets = [];
    if ($results->count() > 0) {
      $facets_raw = $results->getFacetSet()->getFacet($solr_field);
      foreach($facets_raw as $value => $count) {
        // Value should be split by :: with the second value used if exists.
        $display_value = $value;
        if (str_contains($value, '::')) {
          $v_arr = explode("::", $value);
          $display_value = !empty($v_arr[1]) ? $v_arr[1] : $value;
          $value = urlencode($value);	// To prevent Solr breaking.
        }
        $value = str_replace('"', '%22', $value);
        // https://new.digital-test.lib.umd.edu/scores/search?f[ensemble_size]=quartet
        $facet_link = '<a href="' . $facets_path . $value . '" title="browse facets for ' . $value . '">' . $display_value . '</a>';
        if (!empty($show_counts)) {
          $facet_link .= ' (' . $count . ')';
        }
        $facets[] = ['#markup' => $facet_link];
      }
    }

    sort($facets);

    return [
      '#theme' => 'facets_browse',
      '#facets_path' => $facets_path,
      '#facets' => $facets,
      '#block_title' => $block_title,
      '#search_index' => $search_index,
      '#facet_name' => $facet_field,
      '#collapsed' => $collapsed,
      '#cache' => [
        'max-age' => 9999,
      ],
    ];
  }

  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $form['#tree'] = TRUE;
    $config = $this->getConfiguration();

    $this->random_key = !empty($config['random_key']) ? $config['random_key'] : 'edit-solr-field-' . rand(10, 9999);
    $form['random_key'] = [
      '#type' => 'hidden',
      '#value' => $this->random_key,
    ];

    $index_options = [];
    foreach (search_api_solr_get_servers() as $server) {
      foreach ($server->getIndexes() as $index) {
        $index_options[$index->id()] = $index->id();
      }
    }

    $form['search_index'] = [
      '#type' => 'select',
      '#title' => t('Index'),
      '#options' => $index_options,
      '#required' => TRUE,
      '#default_value' =>  !empty($config['search_index']) ? $config['search_index'] : null,
      '#ajax' => [
        'callback' => [$this, 'getIndexFields'],
        'event' => 'change',
        'wrapper' => $this->random_key,
        'method' => 'replaceWith',
        'progress' => [
          'type' => 'throbber',
          'message' => t('Getting Fields'),
        ],
      ]
    ];

    if (!empty($config['search_index'])) {
      $form['solr_field'] = $this->generateSolrField($config['search_index']);
      $form['solr_field']['#default_value'] = !empty($config['solr_field']) ? $config['solr_field'] : null;
    } else {
      $form['solr_field'] = [
        '#type' => 'select',
        '#title' => t('Solr Field'),
        '#default_value' =>  !empty($config['solr_field']) ? $config['solr_field'] : null,
        '#description' => t('Solr field for faceting. This should match the Facet Field in the Facets configuration.'),
        '#options' => [],
        '#prefix' => '<div id="' . $this->random_key . '">',
        '#suffix' => '</div>',
      ];
    }

    $form['facets_path'] = [
      '#type' => 'textfield',
      '#title' => t('Facets Path'),
      '#default_value' =>  !empty($config['facets_path']) ? $config['facets_path'] : null,
      '#description' => t('Relative path to search results with an empty facet at the end to be filled dynamically with facet. E.g., /searchnew?f[0]=collection:Diamondback Photos&f[1]='),
      '#required' => TRUE,
    ];
    $form['facet_field'] = [
      '#type' => 'textfield',
      '#title' => t('Facet Field'),
      '#default_value' =>  !empty($config['facet_field']) ? $config['facet_field'] : null,
      '#description' => t('Facet field to display. This is the field used in the URL.'),
      '#required' => TRUE,
    ];
    $form['collection'] = [
      '#type' => 'textfield',
      '#title' => t('Collection'),
      '#default_value' => !empty($config['collection']) ? $config['collection'] : null,
      '#description' => t('Presentation Set value to use for facet narrowing. Leave empty if not applicable.'),
    ];
    $form['block_title'] = [
      '#type' => 'textfield',
      '#title' => t('Block Title'),
      '#default_value' =>  !empty($config['block_title']) ? $config['block_title'] : null,
    ];
    $form['show_counts'] = [
      '#type' => 'checkbox',
      '#title' => t('Show facet counts'),
      '#default_value' =>  !empty($config['show_counts']) ? $config['show_counts'] : null,
    ];
    $form['collapsed'] = [
      '#type' => 'checkbox',
      '#title' => t('Defaults to collapsed'),
      '#default_value' =>  !empty($config['collapsed']) ? $config['collapsed'] : null,
    ];
    return $form;
  }

  public function getIndexFields(array &$form, FormStateInterface $form_state) {
    $input = $form_state->getUserInput();
    $element = $form_state->getTriggeringElement();
    $solr_field = $form['solr_field'];
    if ($selected_index = $element['#value']) {
      return $this->generateSolrField($selected_index);
    }
    return null;
  }

  protected function generateSolrField($selected_index) {
    if (empty($selected_index)) {
      return;
    }
    $index = Index::load($selected_index);
    $fields = $index->getFields();
    $keys = [];
    foreach ($fields as $field => $val) {
      $keys[$field] = $field;
    }
    $solr_field = $form['solr_field'];
    $solr_field['#description'] = t('Solr field for faceting. This should match the Facet Field in the Facets configuration.');
    $solr_field['#required'] = TRUE;
    $solr_field['#title'] = t('Solr Field');
    $solr_field['#options'] = $keys;
    $solr_field['#type'] = 'select';
    $solr_field['#prefix'] = '<div id="' . $this->random_key . '">';
    $solr_field['#suffix'] = '</div>';
    return $solr_field;

  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->setConfigurationValue('facets_path', $form_state->getValue('facets_path'));
    $this->setConfigurationValue('block_title', $form_state->getValue('block_title'));
    $this->setConfigurationValue('facet_field', $form_state->getValue('facet_field'));
    $this->setConfigurationValue('solr_field', $form_state->getValue('solr_field'));
    $this->setConfigurationValue('search_index', $form_state->getValue('search_index'));
    $this->setConfigurationValue('show_counts', $form_state->getValue('show_counts'));
    $this->setConfigurationValue('collapsed', $form_state->getValue('collapsed'));
    $this->setConfigurationValue('random_key', $form_state->getValue('random_key'));
    $this->setConfigurationValue('collection', $form_state->getValue('collection'));
  }
}
