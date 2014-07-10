<?php
App::uses('AppModel', 'Model');
App::uses('ElasticSearchIndexableBehavior', 'ElasticSearchIndex.Model/Behavior');
App::uses('ElasticSearchRequest', 'Icing.Lib');



// This is required so the ElasticSearchRequest knows to use the 'test' config
Configure::write('inUnitTest', true);

// this is our mockup User model, from CakeCore
class User extends AppModel {
	public $actsAs = array(
		'ElasticSearchIndex.ElasticSearchIndexable' => array(
		)
	);
}

// this is the same one, with a bit of config put in
class User2 extends AppModel {
	public $actsAs = array(
		'ElasticSearchIndex.ElasticSearchIndexable' => array(
			'url' => 'http://localhost2:9200/customindex/customtable',
		)
	);
}

// this is the same one, with a bit of config put in
class User3 extends AppModel {
	public $actsAs = array(
		'ElasticSearchIndex.ElasticSearchIndexable' => array(
			'index' => 'customindex',
			'table' => 'customtable',
		)
	);
}
class ElasticSearchIndexableBehaviorTest extends CakeTestCase {

	/**
	 * Records
	 *
	 * @var array
	 */
	public $table = 'elastic_search_indexable';
	public $records = array(
		array(
			'association_key' => 'page-general-about-1',
			'model' => 'Page',
			'data' => '. some text here about whatever this document is aboue',
			'created' => '2013-04-09 15:06:39',
			'modified' => '2013-04-09 15:06:39'
		),
		array(
			'association_key' => '222',
			'model' => 'Person',
			'data' => 'alan blount alan@example.com 123-444-5555 somewhere something somehow',
			'created' => '2013-04-09 16:25:07',
			'modified' => '2013-04-09 16:25:07'
		),
		array(
			'association_key' => 'page-general-about-3',
			'model' => 'Page',
			'data' => 'junk and stuff',
			'created' => '2013-04-09 15:06:39',
			'modified' => '2013-04-09 15:06:39'
		),
		array(
			'association_key' => '400',
			'model' => 'Person',
			'data' => 'some user some where somehow somewhen',
			'created' => '2013-04-09 16:25:07',
			'modified' => '2013-04-09 16:25:07'
		),
		array(
			'association_key' => '1',
			'model' => 'Person',
			'data' => 'alan smith alan-smith#example.com 444-123-8888 city state bio junk',
			'created' => '2013-04-09 16:25:07',
			'modified' => '2013-04-09 16:25:07'
		),
	);

	/**
	 * Fixtures
	 *
	 * @var array
	 */
	public $fixtures = array(
		'core.user',
	);

	/**
	 * setUp method
	 *
	 * @return void
	 */
	public function setUp() {
		parent::setUp();
		// get the User model from core unit tests
		$this->User = ClassRegistry::init('User');
		$this->ESR = new ElasticSearchRequest();
		$this->setUpIndex();
		// have to sleep after setting up the records...
		sleep(1);
	}

	/**
	 * tearDown method
	 *
	 * @return void
	 */
	public function tearDown() {
		$this->tearDownIndex();
		parent::tearDown();
		unset($this->User);
		unset($this->ESR);
		ClassRegistry::flush();
	}

	/**
	 * setUp records - index, mapping, and records
	 */
	public function setUpIndex($setupRecords = true) {
		$this->ESR->createIndex($this->ESR->_config['index']);
		$request = array('table' => $this->table);
		$ElasticSearchIndexableBehavior = new ElasticSearchIndexableBehavior();
		$this->ESR->createMapping($ElasticSearchIndexableBehavior->mapping, $request);
		if (empty($setupRecords)) {
			return;
		}
		foreach ($this->records as $i => $data) {
			$id = $this->ESR->createRecord($data, $request);
			$this->records[$i]['_id'] = $id;
		}
		sleep(1);
	}

	/**
	 * tearDown records - delete the index
	 */
	public function tearDownIndex() {
		$this->ESR->deleteIndex($this->ESR->_config['index']);
	}

	# ---------------------------------------
	# -- Tests
	# ---------------------------------------

	/**
	 * This is a wonky setup -- testing it out...
	 */
	public function test_setup() {
		$this->assertTrue(is_object($this->User));
		$this->assertTrue(is_object($this->ESR));
		$this->assertFalse(empty($this->ESR->_config['index']));
		$this->assertFalse(empty($this->ESR->_config['uri']['scheme']));
		$this->assertFalse(empty($this->ESR->_config['uri']['host']));
		// no table set, across all tables/models - excludes User fixture
		$all = $this->ESR->search();
		$this->assertFalse(empty($all));
		$models = array_unique(Hash::extract($all, '{n}.model'));
		sort($models);
		$expect = array('Page', 'Person');
		$this->assertEqual($models, $expect);
		$expect = array(
			'1',
			'222',
			'400',
			'page-general-about-1',
			'page-general-about-3',
		);
		foreach ($all as $record) {
			if (in_array($record['association_key'], $expect)) {
				$expect = array_diff($expect, array($record['association_key']));
			}
		}
		$this->assertTrue(empty($expect), 'We were not able to find all the association_keys, missing: ' . implode(', ', $expect));
		// --------
		$all = $this->User->find('all');
		$this->assertFalse(empty($all));
		$this->assertFalse(empty($all[0]['User']['id']));
		$this->assertFalse(empty($all[0]['User']['user']));
		$this->assertFalse(empty($all[1]['User']['id']));
		$this->assertFalse(empty($all[1]['User']['user']));
	}

	public function test_saveToIndex_no_data() {
		$id = rand(99, 99999);
		$data = array();
		// not saved, no data to save
		//   still true, because no error
		$foundIds_a = $this->User->esSearchGetKeys();
		$this->assertTrue($this->User->saveToIndex($id, $data));
		$foundIds_b = $this->User->esSearchGetKeys();
		$this->assertEqual($foundIds_a, $foundIds_b);
	}

	public function test_saveToIndex_with_data() {
		$id = rand(99, 99999);
		$data = array(
			'id' => $id,
			'user' => "user is " . __function__,
			'email' => "extra-field-email@example.com",
		);
		$this->assertTrue($this->User->saveToIndex($id, $data));
		sleep(1);
		$found = $this->User->esSearchGetKeys(__function__);
		$this->assertFalse(empty($found));
		$this->assertTrue(is_array($found));
		$id = array_shift($found);
		$this->assertFalse(empty($id));
		$this->assertTrue(is_string($id));
		$this->assertTrue(empty($found));
	}

	public function test_save_and_search() {
		$id = rand(99, 99999);
		$data = array('User' => array(
			'id' => $id,
			'user' => "user is " . __function__,
			'email' => "extra-field-email@example.com",
		));
		$saved = $this->User->save($data);
		sleep(1);
		$this->assertFalse(empty($saved));
		$user = $this->User->read(null, $id);
		$this->assertEqual($user['User']['id'], $id);
		$foundIds = $this->User->esSearchGetKeys(__function__);
		$this->assertTrue(in_array($user['User']['id'], $foundIds));
		$this->assertEqual($foundIds, array($user['User']['id']));
		$found = $this->User->search(__function__);
		$this->assertEqual($found, array($user));
	}
}

