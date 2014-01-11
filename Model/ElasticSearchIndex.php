<?php
/**
 * SearchIndex
 *
 * Model where data is stored for the search index
 */
App::uses('ElasticSearchIndexAppModel', 'ElasticSearchIndex.Model');
class ElasticSearchIndex extends ElasticSearchIndexAppModel {

	/**
	 * name of the table used
	 * it is overwritten via the ElasticSearchIndexableBehavior w/ the source Model's table name
	 */
	public $useTable = 'elastic_search_index';

	/**
	 * DOESNT EXIST YET :( ----------
	 *
	 * this is a special flag for the ElasticSource plugin telling it to omit
	 * the model alias from the documents stored... (no need for nesting)
	 *
	 * public $useModelAlias = false;
	 *
	 * ^ DOESNT EXIST YET :( ----------
	 */

	/**
	 * name of the dbconfig used
	 * !important - needs to be setup according to!
	 * https://github.com/dkullmann/CakePHP-Elastic-Search-DataSource
	 *
	 * This value is really set in __construct()
	 */
	public $useDbConfig = 'index'; // set in __construct()

	/**
	 * schema (static schema built into table)
	 */
	public $_mapping = array(
		'id' => array('type' => 'string', 'null' => false, 'default' => null, 'length' => 36, 'key' => 'primary', 'collate' => 'utf8_unicode_ci', 'charset' => 'utf8'),
		'association_key' => array('type' => 'string', 'null' => false, 'default' => null, 'length' => 36, 'key' => 'index', 'collate' => 'utf8_unicode_ci', 'charset' => 'utf8'),
		'model' => array('type' => 'string', 'null' => false, 'default' => null, 'length' => 128, 'collate' => 'utf8_unicode_ci', 'charset' => 'utf8'),
		'data' => array('type' => 'string', 'null' => false, 'default' => null, 'key' => 'index', 'collate' => 'utf8_unicode_ci', 'charset' => 'utf8'),
		'created' => array('type' => 'datetime', 'null' => false, 'default' => null),
		'modified' => array('type' => 'datetime', 'null' => false, 'default' => null),
	);

	/**
	 * Setup this model
	 */
	public function __construct() {
		parent::__construct();
		// force the useDbConfig = 'index' (or whatever is in Configure)
		$dbconfig = Configure::read('ElasticSearchIndex.useDbConfig');
		if (!empty($dbconfig)) {
			$this->useDbConfig = $dbconfig;
		}
	}

	/**
	 *
	 */
	public function elasticMapping() {
		return $this->_mapping;
	}

	/**
	 * placeholder for models tracked/used
	 */
	private $models = array();

	/**
	 * custom auto-bind function
	 */
	private function bindTo($model) {
		$this->bindModel(
			array(
				'belongsTo' => array(
					$model => array (
						'className' => $model,
						'conditions' => 'SearchIndex.model = \''.$model.'\'',
						'foreignKey' => 'association_key'
					)
				)
			),false
		);
	}

	/**
	 * beforeFind callback
	 * correct search with conditions
	 *
	 * @param mixed $queryData
	 * @return mixed $queryData
	 */
	public function beforeFind($queryData) {
		if (!empty($this->models)) {
			$models_condition = array();
			foreach ($this->models as $model) {
				$Model = ClassRegistry::init($model);
			}
		}
		return $queryData;
	}

	/**
	 * afterFind callback
	 * cleanup results data with the SearchIndex children
	 *
	 * @param mixed $results array or false
	 * @param boolean $primary
	 * @return mixed $results
	 */
	public function afterFind($results, $primary = false) {
		if ($primary) {
			foreach ($results as $x => $result) {
				if (!empty($result['ElasticSearchIndex']['model'])) {
					$Model = ClassRegistry::init($result['ElasticSearchIndex']['model']);
					$results[$x]['ElasticSearchIndex']['displayField'] = $Model->displayField;
				}
			}
		}
		return $results;
	}

	/**
	 * do a simple search/bindTo on multiple models
	 *
	 * @param array $models
	 * @return void;
	 */
	public function searchModels($models = array()) {
		if (is_string($models)) {
			$models = array($models);
		}
		$this->models = $models;
		foreach ($models as $model) {
			$this->bindTo($model);
		}
	}

	/**
	 * clean a query string
	 *
	 * @param string $query
	 * @return string $query
	 */
	public function fuzzyize($query) {
		$query = preg_replace('/\s+/', '\s*', $query);
		return $query;
	}

	/**
	 * sometimes, for debugging, you might want to get the log of the queries to the ElasticSearch server
	 */
	public function getLog() {
		$db = ConnectionManager::getDataSource($this->useDbConfig);
		return $db->getLog();
	}
}
