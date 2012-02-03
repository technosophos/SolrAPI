<?php
// $Id$
/**
 * @file
 * A chaining API for Apache Solr searches.
 *
 * This class makes it very easy to create Solr searches. It uses a chaining
 * API like QueryPath, jQuery, etc. Every aspect of the query, from the string to search
 * to the number of results to display (and everything in between) is controlled through
 * this class.
 *
 * While the apachesolr module provides functions for executing searches, they are oriented
 * strongly toward providing standard search engine features. Sometimes it is more desirable
 * to work with Solr as if it were merely another datasource. For this, developers need
 * a flexible and mnemonic API. That is the niche SolrAPI attempts to fill.
 *
 * This is not a a replacement for the apachesolr module. It works in conjunction with that
 * module, merely providing a developer API.
 *
 * <b>Why is there no subquery support? [THERE IS!]</b>
 * Nested queries and "subqueries" are both fully supported by this API. But they are 
 * treated as part of the query itself, not as a separate API entity.
 *
 * Why? Three reasons:
 *  - This is the way Solr treats them
 *  - To some extent, different query parsers handle "subqueries" (actually atoms) differently.
 *  - The flexibility of the API depends largely on allowing developers to choose
 *    between options like <code>_query_:"{!lucene qf=title}Foo"<code>, 
 *    <code>{!query defType=func v=$q1}</code>, or just atoms or statements of 
 *    a query (e.g. <code>baz AND (foo OR bar)</code>).
 * 
 * To that end the developer is left on her own when it comes to constructing complex queries.
 *
 * This library also fully supports chains of filters. See the {@link SolrAPI::Query::filters()} 
 * and {@link SolrAPI::Query::mergeFilters()} methods.
 *
 *
 *
 * @see http://code.google.com/p/solr-php-client/ The Solr PHP Client library.
 */

// In case autoloader is not being used.
require_once 'SolrAPI/Query.php';

/**
 * The main Solr API function.
 *
 * Use this to create a new query. It is a "factory function" that returns
 * a new SolrAPI object. This is the preferred method used for constructing a 
 * query against a Solr server. (If you really prefer strong OO syntax, you 
 * can forgo using this method, and use <code>new \SolrAPI\Query()</code>.)
 *
 * <b>API usage examples</b>
 * 
 * Simple example:
 * <code>
 * require_once 'SolrAPI.php';
 * $results = solrq('blue smurf')->search();
 * </code>
 * The above executes a simple search for the string 'blue smurf'.
 *
 * More advance example:
 * <code>
 * $results = solrq('digital camera')
 *  ->useQueryParser(SolrAPI::QUERY_PARSER_DISMAX) // Use the DisMax parser.
 *  ->limit(20)           // Return 20 items
 *  ->offset(1)           // Skip the first result (why? I don't know... this is just an example)
 *  ->boostQueries('sticky:true^5.0')     // Increase ranking on sticky nodes.
 *  ->queryFields('title^5.0 body^20.0')   // Fields to query, along with their boosts.
 *  ->retrieveFields('title, body')       // just get the title and body.
 *  ->highlight()        // Highlight matches in title and body.
 *  ->spellcheck()       // Check spelling on query string ('blue smurf') and offer alternatives
 *  ->sort('title asc')  // Sort by title, ascending
 *  ->debug(TRUE)        // Include debugging info in the output.
 *  ->search();          // Execute the search.
 * </code>
 *
 * (Because it is a dismax query, the query constructed above expands to this pseudo-code:
 * <code>+((DisjunctionMaxQuery((body:digit^20.0 | title:digit^5.0)~0.01) DisjunctionMaxQuery((body:camera^20.0 | title:camera^5.0)~0.01))~2) DisjunctionMaxQuery((body:"digit camera"~15^2.0)~0.01) sticky:true^5.0</code>)
 *
 * Sometimes you need to work with the apachesolr query object. To transform
 * this into one of those, you can do this:
 *
 * <code>solrq('blue smurf')->drupalSolrQuery();</code>
 *
 * Likewise, you can import from a Drupal Solr Query into this:
 * <code>solrq($query)</code>
 *
 * Or merge a Drupal Solr Query into this:
 * <code>solrq('blue smurf')->query($query)</code>
 *
 * One important thing to note is that this query engine does not call any hooks
 * automatically. It is left up to implementors to execute queries (just as is 
 * the case with the database API).
 *
 * <b>Example Queries</b>
 *
 * <code>solrq('blue smurf')</code>
 *
 * Search for any item containing the terms "blue" or "smurf"
 *
 * <code>solrq('"digital cameras" OR title:"digital cameras"')</code>
 * 
 * Using the default parser (Lucene), search for documents with phrase "digital camera"
 * in the default field (body, typically) or in the title. Note that "digital cameras" is
 * stemmed to "digital camera".
 *
 * <code>solrq('nid:[61280 TO 61290] AND "digital camera"')</code>
 *
 * (To change the above from an INCLUSIVE range to an EXCLUSIVE range, change the 
 * brackets to curly brackets: <code>{61280 TO 61290}</code>)
 *
 * Search a range of nids (61270-61290) for documents containing the phrase 
 * "digital camera" in the body.
 *
 * <code>solrq('{!lucene df=title}Booster chair')</code>
 * 
 * This uses the lucene parser, resetting the default field (df) to title. It is equivalent to 
 * this: <code>+title:booster +title:chair</code>
 * 
 * For details on the Local Params (curly braces) syntax, see 
 * {@link http://wiki.apache.org/solr/LocalParams}.
 *
 * <code>solrq({!lucene}title:"digital camera" _query_:"{!dismax}kodak")</code>
 * 
 * Use the Lucene parser to search for the exact phrase "digital camera" in the
 * title field, and then perform a subquery for the term 'Kodak' on any of the default 
 * query fields using the Dismax query parser. 
 * On my server, the Solr engine expands the query to pseudocode looking like this:
 * <code>+PhraseQuery(title:"digit camera") +(+DisjunctionMaxQuery((tags_h1:kodak^5.0 | body:kodak^40.0 | title:kodak^5.0 | tags_h4_h5_h6:kodak^2.0 | tags_inline:kodak | name:kodak^3.0 | taxonomy_names:kodak^2.0 | tags_h2_h3:kodak^3.0)~0.01) DisjunctionMaxQuery((body:kodak^2.0)~0.01))</code>
 *
 * <b>Clarifications and Avoiding Gotchas</b>
 *
 *  - This library makes use of the Solr PHP Client Library for its server support. This has 
 *    the unfortunate side-effect of introducing a few stylistic discrepancies into the library,
 *    such as the absence of chaining methods in results, and the awkwardly named fields returned
 *    in result sets.
 *  - The default search query parser is 'lucene' (not 'dismax'). It is set by the constructor
 *    so that developers have a consistent experience with the API. For that reason, it overrides
 *    the default as set in the Solr server's configuration file.
 *  - The precise behavior of a search against a field depends largely on how the field was 
 *    indexed. See {@link http://wiki.apache.org/solr/SchemaXml} in the Solr documentation for 
 *    more information.
 *
 * For example, tokenized fields will behave differently than untokenized fields. (And exact
 * matching on tokenized fields is harder).
 * 
 * @param $query
 *  A plain-text query string or an instance of a Drupal_Solr_Query_Interface. The query 
 *  string is parsed by one of Solr's query analyzers.
 * @param $solr
 *  A solr search service instance. If none is provided, the default server is used.
 *
 * @return SolrAPI
 *  A SolrAPI object, initialized and ready for chaining.
 *
 * @see http://wiki.apache.org/solr/SolrQuerySyntax Query syntax.
 * @see http://code.google.com/p/solr-php-client/ The Solr PHP Client library.
 */
function solrq($query = NULL,  Apache_Solr_Service $solr = NULL) {
  return new \SolrAPI\Query($query, $solr);
}
