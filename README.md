# Elastic Search Index

This plugin allow for a very easy search index powered by
[ElasticSearch](http://www.elasticsearch.org/)
with all kinds of
[Lucene](http://lucene.apache.org/)
powered goodness. *(it powers GitHub)*

With this, you keep your models on your own normal (default) datasource.  All
saves and finds and joins and callbacks... normal.

But when you attach this behavior, you now have additional callbacks which
gather the data you want to use as a search index... it stores that data to
ElasticSearch via it's own datasource, `index` as setup via the (above) Elastic
plugin.

![ModelData: normal + SearchIndex: ElasticSearch](https://www.filepicker.io/api/file/zCgRIjKzTYG8jScsLtzE)

What you end up with is having you cake and eating it too.

* Your Model and datasource are unchanged and work as before.
 * all your data is still where it has always been
 * you can still do joins
 * non-search conditions can still work on the normal fields
* The searchy goodness of ElasticSearch / Lucene is avaialble to you
 * The indexed string for each record is a customizable second copy of the data's text
 * It's avaialble on ElasticSearch for any other usage as well

Now you can search by

* term: `foo`
* multi-term: `foo bar`
* partials: `fo*`
* partials in the front: `*oo`
* phrases: `"foo bar"`
* fuzzy term: `~bars` *(prefix with `~`)*
* ... and more ... (suggestions?)

*Note: it is working great, but we could use more ElasticSearch special sauce if
you want to help improve it.*

## Install

Get this plugin into place you need to also get the [Icing](https://github.com/AudiologyHoldings/Icing) Plugin

```
git submodule add https://github.com/zeroasterisk/CakePHP-ElasticSearchIndex app/Plugin/ElasticSearchIndex
git submodule add https://github.com/AudiologyHoldings/Icing app/Plugin/Icing
```

or if you don't like submodules:

```
git clone https://github.com/zeroasterisk/CakePHP-ElasticSearchIndex app/Plugin/ElasticSearchIndex
git clone https://github.com/AudiologyHoldings/Icing app/Plugin/Icing
```

Copy the default `ElasticSearchRequest` configuration into your app
and edit it to suit your setup (*ElasticSearch url/port*).

```
cp app/Plugin/Icing/Config/elastic_search_request.php.default app/Config/elastic_search_request.php
```

Note that there's a `default` config and a `test` config which will override
the `default` config...  But only if your tests set the following Configure variable:

```
Configure::write('inUnitTest', true);
```

In `app/Config/bootstrap.php` load the plugin

```
CakePlugin::load('Icing');
CakePlugin::load('ElasticSearchIndex');
```

### Now setup into any Models you want to search / index

In your `Model` add this behavior

```
	public $actsAs = array(
		'ElasticSearchIndex.ElasticSearchIndexable' => array(),
	);
```

And here are the behaviour config options, with default values

```
	public $actsAs = array(
		'ElasticSearchIndex.ElasticSearchIndexable' => array(
			// url to the elastic search index for this model/table
			'url' => null,
			// extra config for ElasticSearchRequest (parsed from URL)
			'index' => null,
			// extra config for ElasticSearchRequest (parsed from URL, or defaulted to $Model->useTable)
			'table' => null,
			// do we build the index automatically via the afterSave callback? (recommended)
			'rebuildOnUpdate' => true,
			// what fields to used when we build the index (ignonred if custom method on model)
			//   eg: array('title', 'name', 'email', 'city', 'state',  'country'),
			//       or for all (text/varchar) fields: '*' (default)
			'fields' => '*',
			// when we build the index, do we find data first?
			//   false: we only have the data which was saved this time
			//   true: we do a find() afterSave() to get all fields
			'queryAfterSave' => true,
			// optional config for HttpSocket (better to configure ElasticSearchRequest)
			// limit the search results to this many results
			'limit' => 200,
			'request' => array(),
			// details needed to link to Model (edge cases)
			'foreignKey' => false, // primaryKey to save against
			// optional optimizing configuration, register_shutdown_function()
			//   if true, we don't actually save on ElasticSearch until
			//   this script is completed... via register_shutdown_function()
			//   NOTE: this will "stack" multiple saves if they happen, in order
			'register_shutdown_function' => false,
		),
	);
```

## How to Save Records

It's **automatic, after every save**, the behaviour will post that record to the ElasticSearch index.

If you want to manually index any model `$data` arrays (with the fields from
this model), in your `Model` you can do so with `saveToIndex($id, $data)`...

```
// in a Model
$data = $this->read(null, '1234');
$id = $data[$this->alias][$this->primaryKey];
$success = $this->saveToIndex($id, $data);
```

If you have a simple string, you want to index for a record on your `Model`
then you can use `saveIndexDataToIndex($id, $indexString)`

```
// in a Model
$success = $this->saveIndexDataToIndex(1234, 'This is a custom string, this will be indexed');
```

*(want to index all of your records?  see below for `reIndexAll()`)*

### Customize the data to save to the Index

You can specify a few methods on your model, which override the basic functionality.

There are 3 levels of customization available:

* `getDataForIndex()` getting data, allows you to do a custom `find()` adding contains, etc (output = array)
* `indexData()` parsing/transforming the data from array --> index string
* `cleanForIndex()` clean/replace the index string (final pass)

NOTE: if you use `getDataForIndex()` to *get extra data*
you will probably also want to use `indexData()` to *convert into a string*

#### Customize Getting Data for the Index: getDataForIndex()

Make this method on your model to control, extend the data gathered for
indexing...

This is useful if you need to contain other data.  A blog Post might need to be
searchable via the content of the Post as well as all of the comments.

```
/**
 * This method will customize the 'find' ElasticSearchIndex uses to get data for it's index
 *
 * @param mixed $id
 * @return array $record find('first')
 */
public function getDataForIndex($id) {
	return $this->find('first', array(
		'fields' => array('id', 'title', 'body', 'date'),
		'contain' => array('Comment' => array('subject', 'body')),
		'conditions' => array("{$this->alias}.{$this->primaryKey}" => $id),
	));
}
```

#### Customize Parsing Data for the Index: indexData()

Make this method on your Model to process a data array into a string for indexing.

```
/**
 * This method will customize the parsing of
 * (array)data->(string)index for ElasticSearchIndex
 *
 * NOTE: the data must be set on the Model->data already
 *
 * @return string $index
 */
public function indexData() {
	if (empty($this->data)) {
		return '';
	}
	// you'd want to customize this a bit more
	$data = Hash::filter(Hash::flatten($this->data));
	return implode(' ', $data);
}
```

It should return a string (the text which will be stored in the index)

#### Customize Cleaning Data for the Index: cleanForIndex()

Make this method on your Model to clean or post-process the index text.
You can replace terms, characters or whatever you like.

```
/**
 * This method will Customize the cleaning of (string)index data
 *
 * @param string $index
 * @return string $index
 */
public function cleanForIndex($index) {
	if (!empty($this->cursewords)) {
		str_replace($this->cursewords, '#&$@!', $index);
	}
	preg_replace('#[^0-9a-zA-Z\s]#', ' ', $index);
	preg_replace('#[\s]+#', ' ', $index);
	return $index;
}
```

## How to re-index all Records

In any Model you can run `reIndexAll($conditions)` and it will walk through
your data and re-index all of them... it can be really slow...

```
// in Controller
// this is really slow, but it will re-index everything (create/update indexes)
$statusString = $this->MyModel->reIndexAll();
// or you can pass in any conditions you like to limit the scope of the reIndex
$statusString = $this->MyModel->reIndexAll(array(
    'modified >' => date('Y-m-d 00:00:00', strtotime('-2 months')),
));
```

**Pro Tip:** the Icing Plugin has a `DoShell` allowing you to easily run any
method on any Model... so from a command line you can do:

```
./cake Icing.do MyModel reIndexAll
```

## How to Search

The core search method for this behavior is `esSearchGetKeys`
which returns just the `id`s of the `Model`.

```
$primaryKeys = $this->esSearchGetKeys($term);
```

And with `$optionsForElasticSearchRequest` (`limit`, `page`).

```
$primaryKeys = $this->esSearchGetKeys($term, $optionsForElasticSearchRequest);
```

This is a really useful method, it can easily be added to any `conditions` array.

```
$conditions = array(
	"{$this->alias}.{$this->primaryKey}" => $this->esSearchGetKeys('Search Term'),
	// the rest of my conditions which also have to match
);
```

If you are using the [CakeDC/search](https://github.com/CakeDC/search) plugin,
you can use this to make subquery or query filters... **(which is sweet!)**

## How to Search with results Sorted by best match

Search results are usually sorted by which results are the best match for the
search term.

```
$sortedIds = $this->esSearchGetKeys('Search Term');
$results = $this->find('all', array(
	'conditions' => array(
		"{$this->alias}.{$this->primaryKey}" => $sortedIds
	)
));
$results = $this->searchResultsResort($results, $sortedIds);
```


## Convenience Search, Resort, and Return Data

If you want to just get search results, without any other conditions, it's
really simple:

```
$findAllResults = $this->search($term)
```

And here are all the possible paramters...

```
$findAllResults = $this->search($term, $optionsForFindAll, $optionsForElasticSearchRequest);
```




## Background

This project is based in large part on the
[Searchable/SearchIndex](https://github.com/zeroasterisk/Searchable-Behaviour-for-CakePHP)
Plugin/Behavior and my former fork of it.  The original version stored all of
the index data into a MySQL table with a full-text-index.  That worked pretty
well, but it only worked with the MyISAM table engine and it doesn't offer all
the sweet search syntax/features.

Initially, this was using the
[Elasitc](https://github.com/dkullmann/CakePHP-Elastic-Search-DataSource)
Plugin/Datasource and it worked ok... but there were un-necissary complications
due to the data storage patter (as CakePHP nested models) and because all of
the data for all of the models was stored in the same "table" on ElasticSearch.
Also the Elastic model required curl, not bad but not needed.

Now ElasticSearchIndex is using
[Icing.Lib/ElasticSearch](https://github.com/AudiologyHoldings/Icing/#elasticsearchrequest).
for interactions with ElasticSearch.

It's a little odd to interact with a "database" not through a "datasource" but
the Lib is really an extension of the HttpSocket utility, and it's indended to
facilitate both a raw interactions (where you manually create whatever data you
want to send) and it has tools to help automate simple data to pass.

## Attribution

This project is an extension of Searchable/SearchIndex and informed by the Elastic DataSource...
The base of the work is theirs.  Big thanks!

* https://github.com/dkullmann/CakePHP-Elastic-Search-DataSource
* https://github.com/connrs/Searchable-Behaviour-for-CakePHP
  [my fork](https://github.com/zeroasterisk/Searchable-Behaviour-for-CakePHP)
* https://github.com/AudiologyHoldings/Icing

and of course, you... pull requests welcome!

## License

This code is licensed under the MIT License


Copyright (C) 2013--2014 Alan Blount <alan@zeroasterisk.com> https://github.com/zeroasterisk/

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
of the Software, and to permit persons to whom the Software is furnished to do
so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.


