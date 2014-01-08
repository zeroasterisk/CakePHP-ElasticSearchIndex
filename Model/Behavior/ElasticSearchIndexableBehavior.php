<?php
/**
 * ElasticSearchIndex
 *
 * Captures data on save into a string index (in own table)
 * Setups searchability on that string index
 */
class ElasticSearchIndexableBehavior extends ModelBehavior {

	/**
	 * default settings
	 *
	 * @var array
	 */
	public $__defaultSettings = array(
		'foreignKey' => false, // primaryKey to save against
		'_index' => false, // string to store as data
		'rebuildOnUpdate' => true, // do we want to update the record? (yeah!)
		'queryAfterSave' => true, // slower, but less likely to corrupt search records
		'fields' => '*', // only consider these fields
	);

	/**
	 * placeholder for settings
	 *
	 * @var array
	 */
	public $settings = array();

	/**
	 * placeholder for the ElasticSearchIndex Model (object)
	 *
	 * @var object
	 */
	public $ElasticSearchIndex = null;

	/**
	 * Setup the model
	 *
	 * @param object Model $Model
	 * @param array $settings
	 * @return boolean
	 */
	public function setup(Model $Model, $config = array()) {
		$this->settings[$Model->alias] = array_merge($this->__defaultSettings, $config);
	}

	/**
	 * Standard afterSave() callback
	 * Collected data to save for a record
	 * - association_key = Model->id
	 * - data = _getIndexData() Model->data
	 * Saves index data for a record
	 *
	 * @param Model $Model
	 * @param boolean $created
	 * @param array $options
	 * @return boolean (always returns true)
	 */
	public function afterSave(Model $Model, $created, $options = array()) {
		// get data to save
		$association_key = $Model->id;
		if (empty($association_key)) {
			$association_key = $Model->getLastInsertID();
		}
		if (empty($association_key)) {
			return true;
		}
		if (!empty($this->settings[$Model->alias]['queryAfterSave'])) {
			$data = $this->_getIndexData($Model, $Model->read(null, $association_key));
		} else {
			$data = $this->_getIndexData($Model);
		}
		if (empty($data)) {
			return true;
		}
		$model = $Model->alias;
		// setup ElasticSearchIndex Model
		if (empty($this->ElasticSearchIndex)) {
			$this->ElasticSearchIndex = ClassRegistry::init('ElasticSearchIndex.ElasticSearchIndex', true);
		}
		// look for an existing record to update
		try {
			$id = $this->ElasticSearchIndex->field('id', compact('model', 'association_key'));
		} catch (MissingTableException $e) {
			$this->autoSetupElasticSearchIndex();
			$id = $this->ElasticSearchIndex->field('id', compact('model', 'association_key'));
		} catch (MissingIndexException $e) {
			$this->autoSetupElasticSearchIndex();
			$id = $this->ElasticSearchIndex->field('id', compact('model', 'association_key'));
		} catch (Exception $e) {
			// TODO: catch other exceptions, missing schema?
			pr($e);die('Exception :(');
		}
		if (empty($id)) {
			unset($id);
			$created = date('Y-m-d H:i:s');
		}
		$modified = date('Y-m-d H:i:s');
		// setup data to save
		$save = array('ElasticSearchIndex' => compact('id', 'model', 'association_key', 'data', 'created', 'modified'));
		// save
		$this->ElasticSearchIndex->create(false);
		$saved = $this->ElasticSearchIndex->save($save, array('validate' => false, 'callbacks' => false));
		// clear RAM
		unset($save, $id, $model, $data, $association_key);
		return true;
	}

	/**
	 * After a record is deleted, also remove it's ElasticSearchIndex row
	 *
	 * @param Model $Model
	 * @return boolean
	 */
	public function afterDelete(Model $Model) {
		if (!$this->ElasticSearchIndex) {
			$this->ElasticSearchIndex = ClassRegistry::init('ElasticSearchIndex.ElasticSearchIndex', true);
		}
		$conditions = array('model'=>$Model->alias, 'association_key'=>$Model->id);
		$this->ElasticSearchIndex->deleteAll($conditions);
	}

	/**
	 * Process the input data for this Model->data --> ElasticSearchIndex->data
	 * gets the index string to store as data for this record
	 *
	 * @param Model $Model
	 * @param string $data (optionally pass in data directly)
	 * @return string $index
	 */
	private function _getIndexData(Model $Model, $data = array()) {
		$backupData = false;
		if (!empty($data)) {
			$backupData = $Model->data;
			$Model->data = $data;
		}
		if (method_exists($Model, 'indexData')) {
			return $Model->indexData();
		} else {
			return $this->__getIndexData($Model);
		}
		if (!empty($backupData)) {
			$Model->data = $backupData;
		}
	}

	/**
	 * get the data to save for the index for this record,
	 *   for all text fields we can find on the data
	 *
	 * @param Model $Model
	 * @return string $index
	 */
	private function __getIndexData(Model $Model) {
		$index = array();
		$data = $Model->data[$Model->alias];

		if ($this->settings[$Model->name]['fields'] === '*') {
			$this->settings[$Model->name]['fields'] = array();
		}
		if (is_string($this->settings[$Model->name]['fields'])) {
			$this->settings[$Model->name]['fields'] = explode(',', $this->settings[$Model->name]['fields']);
		}

		foreach ($data as $key => $value) {
			if (!is_string($value)) {
				continue;
			}
			if (!is_array($this->settings[$Model->name]['fields'])) {
				continue;
			}
			if (!empty($this->settings[$Model->name]['fields']) && !in_array($key, $this->settings[$Model->name]['fields'])) {
				continue;
			}
			$columns = $Model->getColumnTypes();
			if ($key == $Model->primaryKey) {
				continue;
			}
			if (isset($columns[$key]) && in_array($columns[$key],array('text','varchar','char','string'))) {
				$index[] = strip_tags(html_entity_decode($value,ENT_COMPAT,'UTF-8'));
			}
		}

		$index = join(' . ', $index);
		$index = $this->__cleanForIndex($Model, $index);
		return $index;
	}

	/**
	 * Clean for saving as an index
	 *
	 * Optionally post-process and customize by defining the method cleanForIndex() on the Model.
	 *
	 * @param Model $Model
	 * @param string $index
	 * @return string $index
	 */
	private function __cleanForIndex(Model $Model, $index) {
		$index = iconv('UTF-8', 'ASCII//TRANSLIT', $index);
		$index = preg_replace('/[\ ]+/',' ',$index);
		if (method_exists('cleanForIndex', $Model)) {
			$index = $Model->cleanForIndex($index);
		}
		$index = trim($index);
		return $index;
	}

	/**
	 * Perform a search on the ElasticSearchIndex table for a Model + term
	 *
	 * @param Model $Model
	 * @param string $q query, term
	 * @param array $findOptions
	 * @return array $association_keys (results from ElasticSearchIndex records)
	 */
	public function searchAndReturnAssociationKeys(Model $Model, $q, $findOptions = array()) {
		if (!$this->ElasticSearchIndex) {
			$this->ElasticSearchIndex = ClassRegistry::init('ElasticSearchIndex.ElasticSearchIndex', true);
		}
		$this->ElasticSearchIndex->searchModels($Model->name);
		if (!isset($findOptions['conditions'])) {
			$findOptions['conditions'] = array();
		}
		App::uses('Sanitize', 'Utility');
		$q = Sanitize::escape($q);
		$conditions = array();

		/*
		# Non-Working Options at this point
		-----------------
		## Document in the index
		{"took":1,"timed_out":false,"_shards":{"total":5,"successful":5,"failed":0},"hits":{"total":1,"max_score":0.2936909,"hits":[{"_index":"ad","_type":"esindex","_id":"ITbvwWAwT9mlfV3oT3G0YA","_score":0.2936909, "_source" : {"ElasticSearchIndex":{"model":"User","association_key":"15340000-0000-0000-0000-000000000007","data":"15340000-0000-0000-0000-000000000007 alan@audiologyonline.com Alan Blount AudiologyHoldings.com 502.744.4942","created":"2014-01-07 23:40:57","modified":"2014-01-07 23:40:57"}}}]}}
		-----------------
		## Attempts
		$conditions = array(
			'ElasticSearchIndex.model' => $Model->alias,
			'ElasticSearchIndex.data' => $q,
		);
		// {"query":{"filtered":{"query":{"match_all":{}},"filter":{"and":[{"term":{"ElasticSearchIndex.model":"User"}},{"term":{"ElasticSearchIndex.data":"alan"}}]}}}}
		// {"took":1,"timed_out":false,"_shards":{"total":5,"successful":5,"failed":0},"hits":{"total":0,"max_score":null,"hits":[]}}
		$conditions = array(
			'bool' => array(
				'ElasticSearchIndex.model must' => $Model->alias,
				'ElasticSearchIndex.data must' => $q,
			),
		);
		// {"query":{"filtered":{"query":{"match_all":{}},"filter":{"bool":{"must":[{"term":{"ElasticSearchIndex.model":"User"}},{"term":{"ElasticSearchIndex.data":"alan"}}]}}}}}
		// {"took":1,"timed_out":false,"_shards":{"total":5,"successful":5,"failed":0},"hits":{"total":0,"max_score":null,"hits":[]}}
		$conditions = array(
			'bool' => array(
				'ElasticSearchIndex.model must' => $Model->alias,
			),
			'query_string' => array(
				'query' => $q,
			),
		);
		// {"query":{"filtered":{"query":{"match_all":{}},"filter":{"and":[{"bool":{"must":[{"term":{"ElasticSearchIndex.model":"User"}}]}},{"query":{"query_string":{"query":"alan"}}}]}}}}'
		// {"took":1,"timed_out":false,"_shards":{"total":5,"successful":5,"failed":0},"hits":{"total":0,"max_score":null,"hits":[]}}
		# ---------------
		*/
		if (!empty($findOptions['conditions'])) {
			$findOptions['conditions'] = array_merge($findOptions['conditions'], $conditions);
		} else {
			$findOptions['conditions'] = $conditions;
		}
		//$findOptions['type'] = 'query';
		# ---------------
		## Working Options - hardcoded query
		# ---------------
		$findOptions['query'] = array(
			"filtered" => array(
				"query" => array(
					"bool" => array(
						"must" => array(
							array(
								"match" => array(
									"ElasticSearchIndex.model" => $Model->alias,
								)
							),
							array(
								"match" => array(
									"ElasticSearchIndex.data" => $q,
								)
							),
						),
					),
				),
			),
		);
		// {"query":{"filtered":{"query":{"filtered":{"query":{"bool":{"must":[{"match":{"ElasticSearchIndex.model":"User"}},{"match":{"ElasticSearchIndex.data":"alan"}}]}}}}}}}
		$findOptions['fields'] = array('ElasticSearchIndex.association_key');
		/* -- */
		try {
			$results = $this->ElasticSearchIndex->find('all', $findOptions);
		} catch (MissingTableException $e) {
			$this->autoSetupElasticSearchIndex();
			$results = $this->ElasticSearchIndex->find('all', $findOptions);
		} catch (MissingIndexException $e) {
			$this->autoSetupElasticSearchIndex();
			$results = $this->ElasticSearchIndex->find('all', $findOptions);
		} catch (Exception $e) {
			// TODO: catch other exceptions, missing schema?
			pr($e);die('Exception :(');
		}
		/*
		// debug
		$db = ConnectionManager::getDataSource($this->ElasticSearchIndex->useDbConfig);
		$log = $db->getLog();
		debug(compact('results', 'findOptions', 'log'));
		*/
		if (empty($results)) {
			return array();
		}
		$association_keys = Hash::extract($results, '{n}.ElasticSearchIndex.association_key');
		//debug(compact('association_keys'));die('x');
		return $association_keys;
	}

	/**
	 * If you have never setup this table in ElasticSearch before,
	 * we need to do so...
	 *
	 */
	public function autoSetupElasticSearchIndex() {
		$db = ConnectionManager::getDataSource($this->ElasticSearchIndex->useDbConfig);
		// create the index in ElasticSearch
		if (empty($db->config['index'])) {
			throw new CakeException("The Elastic Plugin database.php does not have an 'index' variable set");
		}
		try {
			$db->createIndex($db->config['index']);
		} catch (ElasticIndexExistException $e) {
			// already exists
		}
		// create the mapping for this ElasticSearchIndex in ElasticSearch
		$mapped = $db->checkMapping($this->ElasticSearchIndex);
		if (!empty($mapped)) {
			//return true; // skipping
		}
		if (method_exists($this->ElasticSearchIndex, 'elasticMapping')) {
			$mapping = $this->ElasticSearchIndex->elasticMapping();
		} else {
			$mapping = $db->describe($this->Model);
		}
		if (empty($mapping)) {
			throw new CakeException("Unable to find mapping for $model");
		}
		$mapped = $db->mapModel($this->ElasticSearchIndex, $mapping);
		if (empty($mapped)) {
			throw new CakeException("Unable to save/set mapping for $model");
		}
		return true;
	}

}
