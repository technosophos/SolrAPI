# SolrAPI: A PHP library for working with Solr

This library provides a chainable (Fluent) API for building and executing Solr queries.

## Installation

This library depends on the SolrPHPClient library, which handles Solr client-server communication.

To install:

1. Install the [SolrPHPClient library](http://code.google.com/p/solr-php-client/).
2. Download this libary
3. Put this library somewhere where your PHP interpreter can see it
4. Include it in your scripts (`require 'solrapi.inc'`)

That's all there is to it.

### For Drupal Users

This library contains some additional features targeted toward the [Drupal apachesolr module](http://drupal.org/project/apachesolr).

To use this under Drupal, you merely need to install and configure the `apachesolr` module, and then include this library. (I wrote a simple module to do the including.)

## Using the library

The code is documented very well. Here's a simple example of how this library is used:

    <?php
    // Execute a search
    $results = solrq('Search me')->search();
    
    // $result now has a SolrPHPClient search result object.
    foreach ($results->response->docs as $doc)) {
      print $doc->title;
    }
    ?>

The above executes a simple query for the string 'Search me'. Far more sophisticated queries can be built, though:

    <?php
    $results = solrq('monkey wrench')
       ->useQueryParser(SolrAPI::QUERY_PARSER_DISMAX) // Use the DisMax parser.
       ->limit(20)           // Return 20 items
       ->offset(1)           // Skip the first result (why? I don't know... this is just an example)
       ->boostQueries('sticky:true^5.0')     // Increase ranking on sticky nodes.
       ->queryFields('title^5.0 body^20.0')   // Fields to query, along with their boosts.
       ->retrieveFields('title, body')       // just get the title and body.
       ->highlight()        // Highlight matches in title and body.
       ->spellcheck()       // Check spelling on query string ('blue smurf') and offer alternatives
       ->sort('title asc')  // Sort by title, ascending
       ->debug(TRUE)        // Include debugging info in the output.
       ->search();          // Execute the search.
    ?>

Solr is an advanced search server built atop the equally advanced Lucene search engine. To get the most out of this library, you will probably want to become very familiar with [the Solr documentation](http://wiki.apache.org/solr).

This module was coded against the Drupal coding standards. Documentation can be extracted with Doxygen.

* More information is in the [wiki](http://github.com/technosophos/SolrAPI/wiki)

## License

This is released under the GPL for Drupal compatibility. You may also opt to use it in accordance with the MIT license.

Copyright (c) 2010, Matt Butcher

Sponsored by [ConsumerSearch.com](http://consumersearch.com), a New York Times company.