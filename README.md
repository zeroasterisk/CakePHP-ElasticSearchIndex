# Elastic Search Index

This is a layer on top of the
[Elasitc](https://github.com/dkullmann/CakePHP-Elastic-Search-DataSource)
which simplifies things a lot - thanks to
[Searchable/SearchIndex](https://github.com/zeroasterisk/Searchable-Behaviour-for-CakePHP)
and our customizations.

With this, you keep your models on your own normal (default) datasource.  All
saves and finds and joins and callbacks... normal.

But when you attach this behavior, you now have additional callbacks which
gather the data you want to use as a search index... it stores that data to
ElasticSearch via it's own datasource, `index` as setup via the (above) Elastic
plugin.

What you end up with is having you cake and eating it too.

* Your Model and datasource are unchanged and work as before.
* The searchy goodness of ElasticSearch is avaialble to you against this
  indexed, second copy.

# Install

```
git submodule add https://github.com/zeroasterisk/CakePHP-Elastic-Search-Index app/Plugin/ElasticSearchIndex
# or
git clone https://github.com/zeroasterisk/CakePHP-Elastic-Search-Index app/Plugin/ElasticSearchIndex

```

In `app/Config/bootstrap.php` load the plugin
```
CakePlugin::load('Elastic');
CakePlugin::load('ElasticSearchIndex');
```

In your `Model`
```
	public $actsAs = array(
		'ElasticSearchIndex.ElasticSearchIndexable' => array(),
	);
```

In your `Model` you can also set `fields` to limit what gets indexed
```
	public $actsAs = array(
		'ElasticSearchIndex.ElasticSearchIndexable' => array(
			'fields' => array('title', 'name', 'email', 'city', 'state',  'country'),
		),
	);
```

# Attribution

This project is really a simple re-work of Searchable/SearchIndex to work in
concert with the Elastic DataSource...  The guts of the work is theirs.  Big thanks!

* https://github.com/dkullmann/CakePHP-Elastic-Search-DataSource
* https://github.com/connrs/Searchable-Behaviour-for-CakePHP
  [my fork](https://github.com/zeroasterisk/Searchable-Behaviour-for-CakePHP)

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


