<?php
/**
 * ElasticSearchIndex
 *
 * Captures data on save into a string index (in own table)
 * Setups searchability on that string index
 *
 *
 * Common Usage
 *   afterSave() - automatically saves an index record to match your saved data
 *   Model->search($term) - returns a findAll()
 *   Model->searchAndReturnAssociationKeys($term) - returns a list of $Model->primaryKey
 *   Model->reIndexAll($conditions) - walks through all records and re-indexes them (SLOW!)
 *
 * Advanced Usage
 *   getDataForIndex() - make this method on your model to get customized data for the indexing
 *   indexData() - make this method on your model to parse saved data in a different way
 *   cleanForIndex() - make this method on your model to clean or post-process the indexed data before save
 *
 *
 *
 *
 */

App::uses('ElasticSearchRequest', 'Icing.Lib');

class ElasticSearchIndexException extends CakeBaseException { }

class ElasticSearchIndexableBehavior extends ModelBehavior {

	/**
	 * default settings
	 *
	 * @var array
	 */
	public $__defaultSettings = array(
		// url to the elastic search index for this model/table
		'url' => null,
		// extra config for ElasticSearchRequest (parsed from URL)
		'index' => null,
		// extra config for ElasticSearchRequest (parsed from URL, or defaulted to $Model->useTable)
		'table' => null,
		// limit the search results to this many results
		'limit' => 200,
		// details needed to link to Model
		'foreignKey' => null, // primaryKey to save against
		// do we build the index after save? (yes...)
		'rebuildOnUpdate' => true,
		// when we build the index, consider these fields (ignonred if custom method on model)
		//   eg: array('title', 'name', 'email', 'city', 'state',  'country'),
		//   or for all (text/varchar) fields: '*'
		'fields' => '*',
		// when we build the index, do we find data first? (if false, we only have the data which was saved)
		'queryAfterSave' => true,
		// optional config for HttpSocket (better to configure ElasticSearchRequest)
		'request' => array(),
	);

	/**
	 * placeholder for settings (per Model->alias)
	 *
	 * @var array
	 */
	public $settings = array();

	/**
	 * placeholder for the current ElasticSearchRequest
	 *
	 * @var object
	 */
	public $ElasticSearchRequest = null;

	/**
	 * placeholder for as stash of all the setup ElasticSearchRequests (per Model->alias)
	 *
	 * @var object
	 */
	public $ElasticSearchRequests = array();

	/**
	 * basic mapping/schema for the ElasticSearch "index" (if it doesn't exist)
	 * http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/mapping-core-types.html
	 *
	 * @var array
	 */
	public $mapping = array(
		'association_key' => array('type' => 'string', 'store' => true),
		'model' => array('type' => 'string', 'store' => true, 'boost' => 0.2),
		'data' => array('type' => 'string', 'store' => true),
		'created' => array('type' => 'date', 'store' => false, 'format' => 'yyyy-MM-dd HH:mm:ss||yyyy/MM/dd'),
		'modified' => array('type' => 'date', 'store' => false, 'format' => 'yyyy-MM-dd HH:mm:ss||yyyy/MM/dd'),
	);

	/**
	 * Setup the model
	 *
	 * @param object Model $Model
	 * @param array $settings
	 * @return boolean
	 */
	public function setup(Model $Model, $config = array()) {
		$config = Hash::merge(Hash::filter($this->__defaultSettings), $config);
		if (!empty($config['url'])) {
			$config = Hash::merge($config, $this->setupParseUrl($config['url']));
		} elseif (empty($config['table'])) {
			$config['table'] = $Model->useTable;
		}
		$this->settings[$Model->alias] = $config;
		return true;
	}

	/**
	 * We don't need to initialize the ElasticSearchRequest when this Behavior
	 * is setup, because we wont need until used, and it can be slightly expensive
	 *
	 * Also, we want to switch $this->ElasticSearchRequest to the table for this model
	 *
	 * @param object Model $Model
	 * @return object $this->ElasticSearchRequest
	 */
	public function setupIndex(Model $Model) {
		if (array_key_exists($Model->alias, $this->ElasticSearchRequests)) {
			// already setup - reuse
			$this->ElasticSearchRequest = $this->ElasticSearchRequests[$Model->alias];
			return $this->ElasticSearchRequest;
		}
		// setup ElasticSearchRequest
		$this->ElasticSearchRequest = new ElasticSearchRequest($this->settings[$Model->alias]);
		if (!is_object($this->ElasticSearchRequest)) {
			die('The ElasticSearchIndexableBehavior requires the Icing plugin with the ElasticSearchRequest Lib');
		}
		$config = $this->ElasticSearchRequest->_config;
		if (empty($config['index'])) {
			throw new ElasticSearchIndexException('Missing the "index" configuration - set on ElasticSearchIndexable or in app/Config/elastic_search_request.php');
		}
		if (empty($config['table'])) {
			throw new ElasticSearchIndexException('Missing the "table" configuration - this should be automatic based on Model->useTable');
		}
		// autosetup index & table mapping
		if (!Cache::read("elasticsearchindexablebehavior_setup_{$config['table']}", 'default')) {
			$this->autoSetupElasticSearchIndex($config);
			$this->autoSetupElasticSearchMapping($config);
			Cache::write("elasticsearchindexablebehavior_setup_{$config['table']}", true, 'default');
		}
		$this->ElasticSearchRequests[$Model->alias] = $this->ElasticSearchRequest;
		return $this->ElasticSearchRequest;
	}

	/**
	 * Parse the configuration from a $url
	 *   - gets the host, port, etc...
	 *   - parses the path, and gets the 'index' and the 'table' (if set)
	 */
	public function setupParseUrl($url) {
		$url = parse_url($url);
		if (empty($url)) {
			return array();
		}
		$config = array('uri' => Hash::filter($url));
		if (!empty($url['path'])) {
			$pathParts = explode('/', trim($url['path'], '/'));
			$config['index'] = array_shift($pathParts);
			if (!empty($pathParts)) {
				$config['table'] = array_shift($pathParts);
			}
		}
		unset($config['uri']['path']);
		return $config;
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
		// $data will be gathered from $Model->data if empty
		$data = $Model->data;
		if (!empty($this->settings[$Model->alias]['queryAfterSave'])) {
			// query after save, to get more complete records
			$data = $this->_getDataForIndex($Model, $association_key);
		}
		if (!$this->saveToIndex($Model, $association_key, $data)) {
			throw new ElasticSearchIndexException('afterSave triggered saveToIndex failed');
		}
		return true;
	}

	/**
	 * After a record is deleted, also remove it's ElasticSearchIndex row
	 *
	 * @param Model $Model
	 * @return boolean
	 */
	public function afterDelete(Model $Model) {
		$this->setupIndex($Model);
		$this->deleteIndexByModelId($Model, $Model->id);
		return true;
	}

	/**
	 * Get a Model's record's data by $id / $association_key
	 *
	 * (if the Model->getDataForIndex() method exists, we use that)
	 *
	 * @param Model $Model
	 * @param mixed $association_key = $id of Model record
	 * @return array $data a single record from the Model
	 */
	public function _getDataForIndex(Model $Model, $association_key) {
		if (method_exists($Model, 'getDataForIndex')) {
			return $Model->getDataForIndex($association_key);
		}
		return $Model->read(null, $association_key);
	}

	/**
	 * Sets up and saves index to ElasticSearchIndex
	 *
	 * @param Model $Model
	 * @param mixed $association_key = $id of Model record
	 * @param array $data optionally pass record data in here (if excluded, gets from DB)
	 * @return boolean $success or false
	 */
	public function saveToIndex(Model $Model, $association_key, $data = array()) {
		if (empty($data)) {
			$data = $this->_getDataForIndex($Model, $association_key);
		}
		// switch... $data now is index data (kept the variable name for compact)
		$data = $this->_getIndexData($Model, $data);
		if (empty($data)) {
			// no data, nothing to index
			//   still returning true, because not an error case
			return true;
		}
		return $this->saveIndexDataToIndex($Model, $association_key, $data);
	}

	/**
	 * saves the already created index data to the ElasticSearchIndex as a record
	 *
	 * if ElasticSearchIndex record already exists, we update it
	 *
	 * @param Model $Model
	 * @param mixed $association_key
	 * @param string $data (Search Index Data)
	 * @return boolean $success or false
	 */
	public function saveIndexDataToIndex(Model $Model, $association_key = null, $data = '') {
		if (!empty($data[$Model->alias][$Model->primaryKey])) {
			$association_key = $data[$Model->alias][$Model->primaryKey];
		}
		if (empty($association_key)) {
			throw new ElasticSearchIndexException('saveIndexDataToIndex() unable to determine $association_key');
		}
		// setup ElasticSearchIndex Model
		$id = $this->getIndexId($Model, $association_key);
		if (empty($id)) {
			unset($id);
			$created = date('Y-m-d H:i:s');
		}
		if (empty($data)) {
			if (empty($id)) {
				// not saved, but nothing to save
				return true;
			}
			// index exists... delete it.
			return $this->deleteIndexId($Model, $id);
		}
		// setup data to save
		$model = $Model->alias;
		$modified = date('Y-m-d H:i:s');
		$save = compact('id', 'model', 'association_key', 'data', 'created', 'modified');
		// save
		if (!empty($id) && $this->ElasticSearchRequest->exists($id)) {
			$id = $this->ElasticSearchRequest->updateRecord($id, $save);
		} else {
			$id = $this->ElasticSearchRequest->createRecord($save);
		}
		return (!empty($id));
	}

	/**
	 * gets the $id of the ElasticSearchIndex record for any $Model + $association_key
	 *
	 * @param Model $Model
	 * @param mixed $association_key $Model.$primaryKey
	 * @return string $id or false
	 */
	public function getIndexId(Model $Model, $association_key) {
		// setup ElasticSearchIndex Model
		$records = $this->setupIndex($Model)->search("association_key:{$association_key}");
		if (empty($records)) {
			return false;
		}
		$first = array_shift($records);
		if (empty($first['_id'])) {
			return false;
		}
		return $first['_id'];
	}

	/**
	 * Delete an ElasticSearchIndex record by the $Model's $primaryKey
	 *
	 * @param Model $Model
	 * @param mixed $association_key $Model.$primaryKey
	 * @return boolean
	 */
	public function deleteIndexByModelId($Model, $association_key) {
		$id = $this->getIndexId($Model, $association_key);
		if (empty($id)) {
			// no record exists, no need to delete
			return true;
		}
		return $this->deleteIndexId($Model, $id);
	}

	/**
	 * Delete an ElasticSearchIndex record by it's $id
	 *
	 * @param Model $Model
	 * @param mixed $id ElasticSearchIndex.id
	 * @return boolean
	 */
	public function deleteIndexId($Model, $id) {
		// setup ElasticSearchIndex Model
		$this->setupIndex($Model);
		return $this->ElasticSearchRequest->deleteRecord($id);
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
			$index = $Model->indexData();
		} else {
			$index = $this->__getIndexData($Model);
		}
		if (!empty($backupData)) {
			$Model->data = $backupData;
		}
		return $index;
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
		$data = $Model->data;
		if (array_key_exists($Model->alias, $data)) {
			$data = $data[$Model->alias];
		}
		$data = Hash::flatten($data);
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
			if (isset($columns[$key])) {
				if (in_array($columns[$key], array('text', 'varchar', 'char', 'string'))) {
					$index[] = strip_tags(html_entity_decode($value, ENT_COMPAT, 'UTF-8'));
				}
				continue;
			}
			if (is_string($value)) {
				$index[] = strip_tags(html_entity_decode($value, ENT_COMPAT, 'UTF-8'));
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
		if (method_exists($Model, 'cleanForIndex')) {
			$index = $Model->cleanForIndex($index);
		}
		$index = trim($index);
		return $index;
	}

	/**
	 * Quick and simple search...
	 *   find ids on ElasticSearch
	 *   find 'all' on Model, add in the condition of id IN($foundIds)
	 *
	 * @param Model $Model
	 * @param string $q query, term
	 * @param array $findOptions
	 * @param array $findIndexOptions
	 * @return array $findAll from Model
	 */
	public function search(Model $Model, $q = '', $findOptions = array(), $findIndexOptions = array()) {
		$findIndexDefaults = array_intersect_key($findOptions, array('limit', 'page'));
		$findIndexOptions = Hash::merge($findIndexDefaults, $findIndexOptions);
		$foundIds = $this->searchAndReturnAssociationKeys($Model, $q = '', $findIndexOptions);
		if (empty($foundIds)) {
			return array();
		}
		$findOptions['conditions'][] = sprintf("%s.%s IN('%s')",
			$Model->alias,
			$Model->primaryKey,
			implode("','", $foundIds)
		);
		$results = $Model->find('all', $findOptions);
		return $this->searchResultsResort($Model, $results, $foundIds);
	}

	/**
	 * Perform a search on the ElasticSearchIndex table for a Model + term
	 *
	 * @param Model $Model
	 * @param string $q query, term
	 * @param array $findIndexOption,Ys
	 * @return array $association_keys (results from ElasticSearchIndex records)
	 */
	public function searchAndReturnAssociationKeys(Model $Model, $q = '', $findIndexOption = array()) {
		// TODO: get limit, order, etc. from $findIndexOption
		$defaults = array(
			'fields' => 'association_key',
			'limit' => $this->settings[$Model->alias]['limit'],
			'page' => 1,
		);
		$findIndexOption = array_merge($defaults, $findIndexOption);
		$results = $this->setupIndex($Model)->search($q, $findIndexOption);
		/* -- * /
		debug(compact('q', 'results', 'findIndexOption'));die();
		/* -- */
		if (empty($results)) {
			return array();
		}
		return Hash::extract($results, '{n}.association_key');
	}

	/**
	 * Takes a set of $results and a list of $ids,
	 *   it $resorts the results to the order of the $ids.
	 *
	 * TODO: there has got to be a more elegant way than a double-foreach,
	 *   worth a refactor sometime.
	 *
	 * @param Model $Model
	 * @param array $results
	 * @param array $ids simple array of the primaryKeys
	 * @return array $sorted
	 */
	public function searchResultsResort(Model $Model, $results, $ids) {
		$sorted = array();
		foreach ($ids as $i => $id) {
			foreach (array_keys($results) as $j) {
				if ($results[$j][$Model->alias][$Model->primaryKey] == $id) {
					$sorted[$i] = $results[$j];
					unset($results[$j]);
				}
			}
		}
		// if there are any $results left, append
		foreach (array_keys($results) as $j) {
			$sorted[] = $results[$j];
			unset($results[$j]);
		}
		return $sorted;
	}

	/**
	 * re-index all records matching the $conditions (or all records)
	 * run in batches so we don't blow up RAM
	 *
	 * @param array $conditions
	 * @return string $status
	 */
	public function reIndexAll(Model $Model, $conditions = null) {
		$limit = 100;
		$page = $indexed = $failed = 0;
		do {
			$page++;
			$records = $Model->find('all', compact('conditions', 'limit', 'page'));
			foreach ($records as $record) {
				if ($this->saveToIndex($Model, $record[$Model->alias][$Model->primaryKey], $record)) {
					$indexed++;
				} else {
					$failed++;
				}
				sleep(rand(0,3));
			}
		} while (count($records) == $limit);
		return sprintf("re-indexed %d records (%d failed)", $indexed, $failed);
	}

	/**
	 * If you have never setup this index in ElasticSearch before,
	 * we need to do so...
	 *
	 */
	public function autoSetupElasticSearchIndex($request) {
		if (empty($request['index'])) {
			throw new CakeException("The 'index' is not configured");
		}
		return $this->ElasticSearchRequest->createIndex($request['index'], $request);
	}

	/**
	 * If you have never setup this table in ElasticSearch before,
	 * we need to do so...
	 *
	 */
	public function autoSetupElasticSearchMapping($request) {
		if (empty($request['index'])) {
			throw new CakeException("The 'index' is not configured");
		}
		if (empty($request['table'])) {
			throw new CakeException("The 'table' is not configured");
		}
		return $this->ElasticSearchRequest->createMapping($this->mapping, $request);
	}

}
