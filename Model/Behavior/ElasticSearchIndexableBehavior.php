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
 *   Model->esSearchGetKeys($term) - returns a list of $Model->primaryKey
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
		// optional optimizing configuration, register_shutdown_function()
		//   if true, we don't actually save on ElasticSearch until
		//   this script is completed... via register_shutdown_function()
		//   NOTE: this will "stack" multiple saves if they happen, in order
		'register_shutdown_function' => false,
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
		if (empty($this->settings[$Model->alias]['rebuildOnUpdate'])) {
			return true;
		}
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
		$resetData = $data;
		if (!empty($this->settings[$Model->alias]['queryAfterSave'])) {
			// query after save, to get more complete records
			$data = $this->_getDataForIndex($Model, $association_key);
		}
		if (!$this->saveToIndex($Model, $association_key, $data)) {
			throw new ElasticSearchIndexException('afterSave triggered saveToIndex failed');
		}
		$Model->data = $resetData;
		return parent::afterSave($Model, $created, $options);
	}

	/**
	 * After a record is deleted, also remove its ElasticSearchIndex row
	 *  -- note: be sure that the Model->id is set
	 *
	 * @param Model $Model
	 * @return boolean
	 */
	public function afterDelete(Model $Model) {
		return $this->deleteIndexByModelId($Model, $Model->id);
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

		if (!empty($this->settings[$Model->alias]['register_shutdown_function'])) {
			return $Model->saveIndexDataToIndexInBackground($association_key, $data);
		}
		// TODO integrate with a queing system
		return $Model->saveIndexDataToIndex($association_key, $data);
	}

	/**
	 * saves the already created index data to the ElasticSearchIndex as a record
	 * ON SCRIPT SHUTDOWN - via register_shutdown_function()
	 *
	 * @param Model $Model
	 * @param mixed $association_key
	 * @param string $data (Search Index Data)
	 * @return boolean $success or false
	 */
	public function saveIndexDataToIndexInBackground(Model $Model, $association_key = null, $data = '') {
		register_shutdown_function(function() use ($Model, $association_key, $data) {
			$Model->saveIndexDataToIndex($association_key, $data);
		});
		return true;
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
		try {
			return $this->setupIndex($Model)->deleteRecord($id);
		} catch (Exception $e) {
			return true;
		}
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
		$utf8 = @iconv('UTF-8', 'UTF-8//TRANSLIT', $index);
		if (!empty($utf8)) {
			// iconv failed... @http://webcollab.sourceforge.net/unicode.html
			$utf8 = preg_replace('/[\x00-\x08\x10\x0B\x0C\x0E-\x19\x7F]'.
				'|(?<=^|[\x00-\x7F])[\x80-\xBF]+'.
				'|([\xC0\xC1]|[\xF0-\xFF])[\x80-\xBF]*'.
				'|[\xC2-\xDF]((?![\x80-\xBF])|[\x80-\xBF]{2,})'.
				'|[\xE0-\xEF](([\x80-\xBF](?![\x80-\xBF]))|(?![\x80-\xBF]{2})|[\x80-\xBF]{3,})/',
				'�', $index);
			$utf8 = preg_replace('/\xE0[\x80-\x9F][\x80-\xBF]'.
				'|\xED[\xA0-\xBF][\x80-\xBF]/S', '?', $utf8);
		}
		$index = preg_replace('/[\s\t\n\r]+/', ' ', $utf8);
		if (method_exists($Model, 'cleanForIndex')) {
			$index = $Model->cleanForIndex($index);
		}
		$index = trim($index);
		return $index;
	}

	/**
	 * Quick and "simple" search...
	 *   find ids on ElasticSearch
	 *   find 'all' on Model, add in the condition of id IN($foundIds)
	 *
	 * @param Model $Model
	 * @param string $q query, term
	 *   Can be a simple string - if so, it's wrapped as a "query_string" query against the _all field.
	 *   http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/query-dsl-query-string-query.html
	 *
	 *   Can also be an array of a custom built query.  If it does have a 'query' key, it will be wrapped in ['query' => $orig].
	 *   (Is auto wrapping needed/useful?)
	 *
	 * @param array $findOptions
	 *   ???????????
	 *
	 * @param array $findIndexOptions
	 *   Additional options to pass to elasticsearch, like 'from', 'size', and 'min_score'.
	 *
	 * @return array $findAll from Model
	 */
	public function search(Model $Model, $q = '', $findOptions = array(), $findIndexOptions = array()) {
		$findIndexDefaults = array_intersect_key($findOptions, array('limit', 'page'));
		$findIndexOptions = Hash::merge($findIndexDefaults, $findIndexOptions);
		$foundIds = $this->esSearchGetKeys($Model, $q = '', $findIndexOptions);
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
	 * @param string $q query
	 *   Can be a simple string - if so, it's wrapped as a "query_string" query against the _all field.
	 *   http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/query-dsl-query-string-query.html
	 *
	 *   Can also be an array of a custom built query.  If it does have a 'query' key, it will be wrapped in ['query' => $orig].
	 *   (Is auto wrapping needed/useful?)
	 *
	 * @param array $findIndexOption
	 *   Additional options to pass to elasticsearch, like 'from', 'size', and 'min_score'.
	 *
	 * @return array $association_keys (results from ElasticSearchIndex records)
	 */
	public function esSearchGetKeys(Model $Model, $q = '', $findIndexOption = array()) {
		$results = $this->_esSearchRawResults($Model, $q, $findIndexOption);
		return Hash::extract($results, '{n}.association_key.{n}');
	}
	public function searchAndReturnAssociationKeys(Model $Model, $q = '', $findIndexOption = array()) {
		return $this->esSearchGetKeys($Model, $q, $findIndexOption);
	}

	/**
	 * Perform a search on the ElasticSearchIndex table for a Model + term
	 * Returns keys AND scores.
	 *
	 * @param Model $Model
	 * @param string $q query, term
	 *   Can be a simple string - if so, it's wrapped as a "query_string" query against the _all field.
	 *   http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/query-dsl-query-string-query.html
	 *
	 *   Can also be an array of a custom built query.  If it does have a 'query' key, it will be wrapped in ['query' => $orig].
	 *   (Is auto wrapping needed/useful?)
	 *
	 * @param array $findIndexOption
	 * @return array $scoresByAssociatonKey (results from ElasticSearchIndex records)
	 */
	public function esSearchGetKeysByScore(Model $Model, $q = '', $findIndexOption = []) {
		$results = $this->_esSearchRawResults($Model, $q, $findIndexOption);
		// transform $results -> $return, an array with KEYS of association_key and VALUES of score.
		// example: $return = [ 192460 => 0.64, 192453 => 0.48, 188010 => 0.37 ]
		// ID "192460" is our best matching result with score of "0.64".
		// ASSUMPTION:  Each association_key is unique per result returned from ES.
		$return = [];
		foreach (array_keys($results) as $i) {
			if (!empty($results[$i]['association_key']) && is_array($results[$i]['association_key'])) {
				$association_key = $results[$i]['association_key'][0];
			} else if (!empty($results[$i]['association_key'])) {
				$association_key = $results[$i]['association_key'];
			} else {
				// Association key not found.
				continue;
			}
			$score = !empty($results[$i]['_score']) ? $results[$i]['_score'] : null;

			$return[$association_key] = $score;
			// Unset results array to free memory
			unset($results[$i]);
		}
		return $return;
	}

	/**
	 * Perform a search on the ElasticSearchIndex table for a Model + term and return "raw" results.
	 *
	 * Note:  "Raw" results are not really raw from elasticsearch, they've already be bastardized by
	 * Lib/ElasticSearchRequest.php, but at least they're not changed further!
	 *
	 * @param Model $Model
	 * @param string $q query, term
	 * @param array $findIndexOption
	 * @return array $association_keys (results from ElasticSearchIndex records)
	 */
	public function _esSearchRawResults(Model $Model, $q = '', $findIndexOption = []) {
		$start = microtime(true);
		// TODO: get limit, order, etc. from $findIndexOption
		$defaults = array(
			'fields' => 'association_key',
			'size' => $this->settings[$Model->alias]['limit'],
			'page' => 1,
		);
		$findIndexOption = array_merge($defaults, $findIndexOption);

		// get ElasticSearchRequest
		$ES = $this->setupIndex($Model);

		// perform search on ElasticSearchRequest
		$results = $ES->search($q, $findIndexOption);
		$stop = microtime(true);

		// log into the SQL log
		$DS = $Model->getDataSource();
		$DS->numRows = count($results);
		$DS->took = round($stop - $start, 2) * 1000;

		$log = 'ElasticSearchRequest: ' . is_array($q) ? print_r($q, true) : $q;
		if (!empty($ES->last['request'])) {
			$log = $ES->asCurlRequest($ES->last['request']);
		}
		#if (!empty($ES->last['response'])) {
		#	$log .= "\n;#response:  " . json_encode($ES->last['response']);
		#}
		if (!empty($ES->last['error'])) {
			$log .= "\n;#ERROR:  " . json_encode($ES->last['error']);
		}
		$Model->getDataSource()->logQuery($log);

		/* -- * /
		debug(compact('q', 'results', 'findIndexOption'));die();
		/* -- */

		return empty($results) ? [] : $results;
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
	public function reIndexAll(Model $Model, $conditions = null, $doSleep = false) {
		$limit = 500;
		$page = $indexed = $failed = 0;
		$order = array("{$Model->alias}.{$Model->primaryKey}" => 'asc');
		$fields = array("{$Model->alias}.{$Model->primaryKey}");
		do {
			$page++;
			$ids = $Model->find('list', compact('conditions', 'fields', 'limit', 'page', 'order'));
			foreach ($ids as $id) {
				if ($this->saveToIndex($Model, $id)) {
					$indexed++;
				} else {
					$failed++;
				}
				if ($doSleep) {
					sleep(rand(0,3));
				}
			}
		} while (count($ids) == $limit);
		return sprintf("re-indexed %d records (%d failed)", $indexed, $failed);
	}

	/**
	 * If you have never set up this index in ElasticSearch before,
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
	 * If you have never set up this table in ElasticSearch before,
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

	/**
	 *  This takes a simple text input and turns it a default "match" query with proximity based scoring.
	 *  Great for a simple search where a user types in whatever without expecting any operators to work.
	 *
	 *  @param $terms string
	 *  Example: $terms = "hand therapy" will return any documents that have either or both words in the document,
	 *  but any documents that have the words nearby each other will receive a score boost.
	 *
	 *  @param $options array
	 *    keys available:
	 *    field       - which field to search. defaults to '_all'
	 *    window_size - the proximity algorithm is expensive.  instead of running it on all results, it will only
	 *                  run it on the top window_size results.  defaults to 50.
	 *    slop - basically, how many words apart the words may be to be considered proximate.  defaults to 50.
	 *           note that even with a value like 50, documents with the words much closer than 50 will score higher
     *           than documents that are just around the limit of 50.
	 *
	 *           See ES documentation for more on window_size and slop.
	 *
	 *  @return $query array
	 *  The return value is an array which you may pass to esSearchGetKeys(), or esSearchGetKeysByScore(), or if
	 *  you prefer, in Icing's ElasticSearchRequest.. search() or request().
	 **/
	public function esStringToProximityQuery(Model $model, $terms = '', $options = []) {
		$defaults = [
			'field' => '_all',
			'window_size' => 50,
			'slop' => 50,
		];
		$options = array_merge($defaults, $options);

		$query = [
			'query' => [
				'match' => [
					$options['field'] => [
						'query' => $terms,
					],
				],
			],
			'rescore' => [
				'window_size' => $options['window_size'],
				'query' => [
					'rescore_query' => [
						'match_phrase' => [
							$options['field'] => [
								'query' => $terms,
								'slop' => $options['slop'],
							],
						],
					],
				],
			],
		];
		return $query;
	}

}
