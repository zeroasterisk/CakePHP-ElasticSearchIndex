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
	 */
	public $useTable = 'esindex';

	/**
	 * name of the dbconfig used
	 * !important - needs to be setup according to!
	 * https://github.com/dkullmann/CakePHP-Elastic-Search-DataSource
	 */
	public $useDbConfig = 'index';

	/**
	 * schema (static schema built into table)
	 */
	public $_mapping = array(
		'id' => array('type' => 'integer', 'null' => false, 'default' => null, 'key' => 'primary'),
		'association_key' => array('type' => 'string', 'null' => false, 'default' => null, 'length' => 36, 'key' => 'index', 'collate' => 'utf8_unicode_ci', 'charset' => 'utf8'),
		'model' => array('type' => 'string', 'null' => false, 'default' => null, 'length' => 128, 'collate' => 'utf8_unicode_ci', 'charset' => 'utf8'),
		'data' => array('type' => 'string', 'null' => false, 'default' => null, 'key' => 'index', 'collate' => 'utf8_unicode_ci', 'charset' => 'utf8'),
		'created' => array('type' => 'datetime', 'null' => false, 'default' => null),
		'modified' => array('type' => 'datetime', 'null' => false, 'default' => null),
	);

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
		$models_condition = false;
		if (isset($queryData['conditions'])) {
			if ($models_condition) {
				if (is_string($queryData['conditions'])) {
					$queryData['conditions'] .= ' AND (' . join(' OR ',$models_condition) . ')';
				} else {
					$queryData['conditions'][] = array('OR' => $models_condition);
				}
			}
		} else {
			if ($models_condition) {
				$queryData['conditions'][] = array('OR' => $models_condition);
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
				if (!empty($result['SearchIndex']['model'])) {
					$Model = ClassRegistry::init($result['SearchIndex']['model']);
					$results[$x]['SearchIndex']['displayField'] = $Model->displayField;
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
}
