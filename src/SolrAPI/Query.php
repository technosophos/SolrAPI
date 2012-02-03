<?php
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
 * @file
 *
 * @see http://code.google.com/p/solr-php-client/ The Solr PHP Client library.
 */

/**
 * The main Solr API class.
 *
 * Use this to create a new Solr search query.
 */
class Query {
  
  /**
   * The Lucene query parser.
   * Very robust and useful for sophisticated coded queries.
   * @see http://wiki.apache.org/solr/SolrQuerySyntax
   */
  const QUERY_PARSER_LUCENE = 'lucene';
  /**
   * The DisMax query parser.
   * Stripped down Lucene parser with amenities for user-entered searches.
   * @see http://wiki.apache.org/solr/SolrQuerySyntax
   */
  const QUERY_PARSER_DISMAX = 'dismax';
  
  /**
   * The input string is unanalyzed.
   *
   * This can be used (carefully) for exact matching.
   */
  const QUERY_PARSER_RAW = 'raw';
  
  /**
   * A boosted function.
   *
   * This is probably not a useful default.
   */
  const QUERY_PARSER_BOOST = 'boost';
  const QUERY_PARSER_FIELD = 'field';
  const QUERY_PARSER_PREFIX = 'prefix';
  
  
  /**
   * The Query.
   */
  protected $query;
  /** Filters. */
  protected $filters = array();
  /** Offset */
  protected $offset = 0;
  
  /**
   * Number to return per batch. Default matches Solr's default.
   */
  protected $limit = 10;
  /** Sort ops. */
  protected $sort = NULL;
  /** Base path. */
  protected $basePath = '';
  /** Params array. */
  protected $params = array();
  /** Apache solr service instance. */
  protected $solr = NULL;
  /** Query fields. (unused?) */
  protected $queryFields = '';
  
  /**
   * Create a new Solr API object.
   *
   * Typically, this is invoked transparently by <code>solrq()</code>.
   * 
   * By default, this sets the following:
   * - highlighting is turned off
   * - faceting is turned off
   * - spellcheck is turned off
   * - the query parser is set to 'lucene'
   * - the list of returned fields is set to 'id,nid,title,comment_count,type,created,changed,score,path,url,uid,name'
   *
   * @param $query
   *  A query string or a Drupal_Solr_Query_Interface object.
   * @param $solr
   *  A solr server object. If not provided, one will be fetched.
   * @see query()
   * @see solrq()
   * @see solr()
   * @see apachesolr_get_solr()
   */
  public function __construct($query = '', Apache_Solr_Service $solr = NULL) {
    $this->solr = is_object($solr) ? $solr : apachesolr_get_solr();
    
    if ($query instanceof Drupal_Solr_Query_Interface) {
      $this->extractDrupalSolrInfo($query);
    }
    else {
      $this->query = $query;
    }
    
    // Set default params.
    $this->params = array(
      // Default fields to return.
      'fl' => 'id,nid,title,comment_count,type,created,changed,score,path,url,uid,name',
      
      // By default, we turn off facet, highlight, MLT, and spellcheck.
      // This is done for speed, primarily. All three can easily be turned on
      // using this API.
      'hl' => 'false',
      'spellcheck' => 'false',
      'facet' => 'false',
      'mlt' => 'false',
      
      // Really, it makes more sense to use Lucene rather than Dismax as the
      // default in this sort of query. There are two reasons for this:
      // 1. lucene is supposed to be the default.
      // 2. lucene provides a more robust query language.
      'defType' => 'lucene',
    );
    
    // We don't do this anymore, since the default type is now Lucene, and 
    // Lucene does not use default query fields.
    // Set default query fields:
    //$this->defaultQueryFields();
  }
  


  /**
   * Get or set the query string.
   *
   * If nothing is passed in, the current query string will be returned. Otherwise, the given string
   * will be set as the query string.
   *
   * Note that the query returned represents the query that would be executed, not necessarily the
   * query as it was initially input.
   *
   * @param $query
   *  - If this is a string, then the current query string will be replaced with the given query.
   *  - If this is a Drupal_Solr_Query_Interface, then the current query is reconfigured using that
   *  object. This has significant ramifications. Query, sorts, and filters may all be rewritten.
   * @return
   *  - setter: This object (code: $solrq->query('some query'))
   *  - getter: The query ($query = $solrq->query())
   * @see http://wiki.apache.org/solr/SolrQuerySyntax Query syntax.
   */
  public function query($query = NULL) {
    if (is_null($query)) {
      return $this->query;
    }
    if (is_string($query)) {
      $this->query = $string;
      return $this;
    }
    if ($query instanceof Drupal_Solr_Query_Interface) {
      $this->extractDrupalSolrInfo($query);
    }
    throw new Exception('Given $query is neither a string nor a Drupal_Solr_Query_Interface');
  }

  
  /**
   * Explicitly set the query parser.
   *
   * Typically, the lucene parser is the default, and the dismax (disjunctive max)
   * parser is the other popular one. Be very careful if you are using anything
   * other than those two.
   *
   * @param $parser
   *  The string name of the parser to use. See the SolrAPI::Query::QUERY_PARSER_*
   *  params.
   * @return 
   *  - setter: This object
   *  - getter: The name of the default parser. If NULL, then the 
   *    parser will be determined by the solr configuration. Typically
   *    it defaults to lucene or dismax.
   * @see http://wiki.apache.org/solr/SolrQuerySyntax
   */
  public function useQueryParser($parser = NULL) {
    if (is_null($parser)) {
      return $this->params['defType'];
    }
    $this->params['defType'] = $parser;
    return $this;
  }
  
  /**
   * Indicates whether or not the current queryparser is explicitly set to Lucene.
   * 
   * Lucene is the default Solr engine, and supports a powerful query syntax.
   *
   * @see http://lucene.apache.org/java/2_9_1/queryparsersyntax.html
   * @return 
   *  Boolean TRUE if this is using the Lucene query parser; FALSE otherwise. Note that
   *  if no parser is explicitly set, this will return FALSE.
   */
  public function isUsingLucene() {
    return isset($this->params['defType']) 
      && $this->params['defType'] == self::QUERY_PARSER_LUCENE;
  }
  
  /**
   * Indicates whether or not the current queryparser is explicitly set to Dismax.
   *
   * The Dismax plugin allows the following extra params (See {@link mergeParams()}), most
   * of which modify the disjunctive max scoring algorithm parameters:
   *  - q.alt: Alternate query
   *  - qf: Query Fields
   *  - mm: Minimum Match
   *  - pf: Phrase fields
   *  - ps: Phrase slop
   *  - qs: Query slop
   *  - tie: Tie breaker
   *  - bq: Boost query
   *  - bf: Boost function (same as using __val__: in query string)
   *
   * @see http://wiki.apache.org/solr/DisMaxQParserPlugin
   * @return 
   *  Boolean TRUE if this is using the Dismax query parser; FALSE otherwise. Note that
   *  if no parser is explicitly set, this will return FALSE.
   */
  public function isUsingDismax() {
    return isset($this->params['defType']) 
      && $this->params['defType'] == self::QUERY_PARSER_DISMAX;
  }
  
  
  
  /**
   * Get or set filters.
   *
   * Filters are executed after a search results score is calculated. It can be used to
   * filter results, but will not change the score. Server-side results caching is also
   * handled differently for filtered results.
   *
   *  See {@link mergeFilters()} for merging new filters into the existing list.
   *
   * Example:
   * <code>
   * // The base query
   * $q = 'title:"digital cameras"';
   * // Now filter out all nodes that don't have type exactly matching 'product'.
   * $filter = '{!raw f=type}product';
   * $results = solrq($q)->filters($filter)->search();
   * </code>
   *
   * Here's an example using two filters:
   * <code>
   * $q = 'title:"digital cameras"';
   * $filter = array('{!raw f=type}product', 'title:canon');
   * $results = solrq($q)->filters($filter)->search();
   * </code>
   *
   * (Note that filters are not treated as a variety of subquery by this library.)
   *
   * @param $filter_array
   *   - If an array is passed in, the filters will be set to that array. (An empty array 
   *     is allowed.)
   *   - If a string is passed in, it will be set as the only filter
   *   - If no parameter is given, the list of filters will be returned.
   * @return 
   *   - setter: This object
   *   - getter: The array of filters.
   * @see http://wiki.apache.org/solr/CommonQueryParameters#fq
   */
  public function filters($filter_array = NULL) {
    if (is_null($filter_array)) {
      return $this->params['fq'];
    }

    if (is_array($filter_array)) {
      $this->params['fq'] = $filter_array;
    }
    elseif (is_string($filter_array)) {
      $this->params['fq'] = array($filter_array);
    }
    return $this;
  }
  
  /**
   * Merge the given filter or filters into the existing list of filters.
   *
   * Note that there is no duplicate control -- the same filter could be 
   * specified twice.
   *
   * @return
   *   This object.
   */
  public function mergeFilters($filters) {
    if (is_string($filters)) {
      $filters = array($filters);
    }
    if (!is_array($this->params['fq'])) {
      $this->params['fq'] = $filters;
    }
    else {
      $this->params['fq'] = array_merge($this->params['fq'], $filters);
    }
    return $this;
  }
  
  /**
   * Get or set the sorting settings.
   *
   * Sorting examples:
   * <code>
   * // Sort by title, desc.
   * solrq('test')->sort('title desc')->search(); 
   *
   * // Sort by title, desc, then subtitle.
   * solrq('test')->sort('title desc, subtitle desc')->search();
   *
   * // Add price plus shipping and order by total
   * solrq('test')->sort('sum(price, shipping) desc')->search();
   * </code>
   *
   * @param $sort_filter
   *  - A string that will be used for sorting. Example: 'score desc' 
   *  (sort descending on 'score' field).
   *  - If an array is passed, this will assume it is a Drupal-style sort
   *  array, and will extract #name and #direction. You are encourage not to
   *  use this syntax.
   * @return
   *  - setter: this object
   *  - getter: The filter string.
   * @see http://wiki.apache.org/solr/CommonQueryParameters#sort
   */
  public function sort($sort_filter = NULL) {
    if (is_null($sort_filter)) {
      return $this->params['sort'];
    }
    
    // Set
    if (is_array($sort_filter)) {
      $this->params['sort'] = $sort_filter['#name'] . ' ' . $sort_filter['#direction'];
    }
    else {
      $this->params['sort'] = $sort_filter;
    }
    
    return $this;
  }
  
  
  /**
   * Remove all sorting from this object.
   *
   * @return This object.
   */
  public function clearSort() {
    $this->sort = NULL;
    return $this;
  }
  
  /**
   * Set common highlight options.
   *
   * Called with no parameters, this simply enables highlighting with the defaults.
   *
   * Other options can be set directly using mergeParams().
   *
   * When any of the given properties are absent from the function call, the Drupal-wide
   * Apache Solr options are used.
   *
   * @see http://wiki.apache.org/solr/HighlightingParameters
   *
   * @param $fragsize
   *  The maximum number of characters to analyze in a fragment.
   * @param $markup_pre
   *  Markup to insert before a highlighted word. (e.g. <div class="highlight">)
   * @param $markup_post
   *  Markup to insert after a highlighted word. (e.g. </div>)
   * @param $snippets
   *  Maximum number of snippets to generate per field.
   * @param $fields
   *  The fields to examine for highlighting. If none are specified, all retrieved fields will
   *  be highlighted (see retrieveFields()).
   * @return This object.
   * @see isHighlighting()
   */
  public function highlight($fragsize = NULL, $markup_pre = NULL, $markup_post = NULL, $snippets = 1, $fields = NULL) {
    $this->params += array(
      'hl' => 'true',
      'hl.fragsize' => $fragsize,
      'hl.snippets' => $snippets,
      'hl.fl' => $fields,
      'hl.simple.pre' => $markup_pre,
      'hl.simple.post' => $markup_post,
    );
    return $this;
  }
  
  /**
   * Turn highlighting off.
   * @return
   *  This object.
   */
  public function clearHighlight() {
    $this->params['hl'] = 'false';
    return $this;
  }
  
  /**
   * Enable facets and set defaults.
   *
   * This provides just the bare minimum for enabling faceting. Other params should be
   * included with mergeParams().
   *
   * @see http://wiki.apache.org/solr/SimpleFacetParameters
   * @see apachesolr_search_add_facet_params()
   * @see facetFields()
   * @see facetQueries()
   * @see isFaceting()
   * @todo This could use some refinement.
   * @param $mincount
   *  The minimum counts for facet fields should be included in the response. The default is
   *  0, but in many cases you will want at least 1.
   * @param $sort
   *  Whether facets should be sorted by count (true) or lexicographically by term (false). 
   *  Note that in Solr 1.4, true becomes 'count' and false becomes 'index'. If you are
   *  working with Solr 1.4, you can pass those names in.
   * @param $limit
   *  Maximum number of constraint counts to be used. -1 (or less) is unlimited.
   * @param $offset
   *  Number to offset the list. Combine with $limit to implement paging.
   * @return
   *  This object.
   * @todo See if facet.method should be configureable here.
   */
  public function facet($mincount = 0, $sort = TRUE, $limit=100, $offset = 0) {
    $this->params['facet'] = 'true';
    $this->params['facet.limit'] = $limit;
    $this->params['facet.offset'] = $offset;
    $this->params['facet.mincount'] = $mincount;
    //, $method = 'fc'
    //$this->params['facet.method'] = $method;
    
    // 1.4 will allow 'count' and 'index' as sort values.
    if (is_string($sort)) {
      $this->params['facet.sort'] = $sort;
    }
    else {
      $this->params['facet.sort'] = $sort ? 'true' : 'false';
    }
    
    return $this;
  }
  
  /**
   * Get or set facet date information
   *
   * @param $fields
   *  An array of fields.
   * @param $start
   *  The start date
   * @param $end
   *  The end date
   * @param $interval
   *  The gap between dates (e.g. '%2B1DAY' for 1 day)
   * @param $other
   *  If information about data outside of the specified range should be included, specify it. Use
   *  one of the following string values:
   *   - before
   *   - after
   *   - between
   *   - none
   *   - all
   * @param $hardend
   *  Boolean indicating how date ranges should be handled when the interval does not divide
   *  evenly. See {@link http://wiki.apache.org/solr/SimpleFacetParameters}.
   * @return 
   *  - Getter: The date fields
   *  - Setter: This object
   */
  public function facetDateFields($fields = NULL, $start = NULL, $end = NULL, $interval = NULL, $other = NULL, $hardend = NULL) {
    $args = func_get_args();
    
    $setter = FALSE;
    foreach ($args as $a) {
      if(!is_null($a)) {
        $setter = TRUE;
      };
    }
    
    if (!$setter) {
      return $this->params['facet.date'];
    }
    
    $hardened = (empty($hardened) || $hardened == FALSE) ? 'false' : 'true';
    //$other = (empty($hardened) || $other == FALSE) 'false' : 'true';
    
    $this->params['facet.date'] = $fields;
    $this->params['facet.date.start'] = $start;
    $this->params['facet.date.end'] = $end;
    $this->params['facet.date.gap'] = $interval;
    $this->params['facet.date.hardend'] = $hardend;
    $this->params['facet.date.other'] = $other;
    
    return $this;
  }
  
  /**
   * Get or set facet fields.
   * 
   * Faceting must be enabled using {@link facet()} or by manually toggling on the 
   * facet field in params.
   *
   * @param $fields
   *  An indexed array of facet fields, e.g. <code>array('category','tags')</code>.
   * @return
   *  - getter: array of facet fields (NULL of no filters are set.)
   *  - setter: this object.
   * @see http://wiki.apache.org/solr/SimpleFacetParameters
   */
  public function facetFields($fields = NULL) {
    if (is_null($fields)) {
      return $this->params['facet.field'];
    }
    
    $this->params['facet.field'] = $fields;
    return $this;
  }
  
  /**
   * Get or set facet queries.
   *
   * In addition to faceting on fields, Solr supports faceting by 
   * ad-hoc queries.
   *
   * @param $queries
   *  A string query or array of queries to run, where each query acts as a facet.
   * @return
   *  - Setter: this object
   *  - Getter: An array of facet queries.
   */
  public function facetQueries($queries = NULL) {
    if (is_null($queries)) {
      return $this->params['facet.query'];
    }
    
    if (is_string($queries)) {
      $queries = array($queries);
    }
    
    $this->params['facet.query'] = $queries;
    return $this;
  }
  
  /**
   * Return "More like this" items for every returned document.
   *
   * This will return up to $count references for each document returned from
   * the search.
   *
   * @param $count
   *  The maximum number of items per document to return.
   * @param $fields
   *  A comma-separated list of fields that should be used for comparison.
   *  Example: 'title,body' would use just the title and body fields for comparisons.
   * @param $maxWords
   *  The maximum number of words to use for comparing a document to other documents.
   * @return
   *  This object.
   * @see http://wiki.apache.org/solr/MoreLikeThis
   */
  public function moreLikeThis($count = 5, $fields = NULL, $maxWords = 5) {
    $mlt = array(
      'mlt' => 'true',
      'mlt.count' => $count,
      'mlt.maxqt' => $maxWords,
      'mlt.fl' => $fields,
    );
    $this->mergeParams($mlt);
    return $this;
  }
  
  /**
   * Enable or disable spellchecking.
   *
   * @param $value
   *  To disable, $value should be FALSE.
   *  - If it is TRUE, spellchecking will be done on the final version of the query.
   *  - If it is set to a string, then checking will be done ON THAT STRING. This can be
   *  used to do some more sophisticated spellchecking.
   * @return
   *  This object.
   */
  public function spellcheck($value = TRUE) {
    if ($value == FALSE) {
      $this->params['spellcheck'] = 'false';
    }
    else {
      $this->params['spellcheck'] = 'true';
      if (is_string($value)) {
        $this->params['spellcheck.q'] = $value;
      }
    }
    return $this;
  }
  
  /**
   * Get/set the fields that should be retrieved and returned.
   *
   * @param $string
   *  A comma-separated string of fields to return, or an array of field names.
   * @return
   *  - setter: This object
   *  - getter: The fields that will be retrieved from the server.
   */
  public function retrieveFields($string = NULL) {
    if (is_null($string)) {
      return $this->params['fl'];
    }
    
    if (is_array($string)) {
      $string = implode(',', $string);
    }
    $this->params['fl'] = $string;
    return $this;
  }
  
  /**
   * Set the default search field (LUCENE and others).
   *
   * On queries that do not have any fields specified, the default field is used. (Dismax
   * queries always have fields and thus never use the defaultField).
   * 
   * Examples:
   * <code>
   * // Search for any occurance of 'camera' in the title field.
   * solrq('camera')->defaultField('title')->useQueryParser('lucene')->search();
   * <code>
   * 
   *
   * For Dismax queries, use {@see queryFields()} instead. Practically speaking, dismax
   * ignores the default field.
   *
   * @param $field
   *  String with the name of the field to be used as the default (e.g. 'title').
   * @return
   *  - getter: The default field
   *  - setter: This object.
   */
  public function defaultField($field = NULL) {
    if (is_null($field)) {
      return $this->params['df'];
    }
    
    $this->params['df'] = (string)$field;
    return $this;
  }
  
  /**
   * Gets or sets the fields that should be searched (DISMAX).
   *
   * This only impacts Dismax queries. If you are using the Lucene parser, only the 
   * default field (see defaultField()) is used in cases where no fields are specified.
   *
   * @param $fields
   *   Can be one of:
   *   - String of this format: <code>body^40.0 title^5.0 name^3.0 taxonomy_names^2.0</code>
   *   - Array of this format <code>array('body^4.0', 'title^5.0')</code>
   * @return
   *  - setter: This object.
   *  - getter: The query fields as an array: <code>array('body^4.0', 'title^5.0')</code>.
   */
  public function queryFields($fields = NULL) {
    if (is_null($fields)) {
      return $this->params['qf'];
    }
    
    if (is_array($fields)) {
      $this->params['qf'] = $fields;
    }
    else {
      $this->params['qf'] = explode(' ', $fields);
    }
    return $this;
  }
  
  /**
   * Set query fields to Drupal's default query fields (DISMAX).
   *
   * This only affects Dismax queries. You should use it only if you have
   * already set the query handler to DISMAX.
   *
   * @return
   *  This object.
   * @deprecated
   *   This may be removed.
   */
  public function drupalDefaultQueryFields() {
    
    if (!function_exists('variable_get')) {
      throw new Exception('No such function as variable_get. This does not look like Drupal.');
    }
    
    $qf = variable_get('apachesolr_search_query_fields', array());
    $fields = $this->solr->getFields();
    if ($qf && $fields) {
      foreach ($fields as $field_name => $field) {
        if (!empty($qf[$field_name])) {
          if ($field_name == 'body') {
            // Body is the only normed field.
            $qf[$field_name] *= 40.0;
          }
          $this->params['qf'][] = $field_name . '^'. $qf[$field_name];
        }
      }
    }
    return $this;
  }
  /**
   * Reset to the default query fields (with their default boost settings).
   */
   /*
  public function clearQueryFields() {
    
  }
  */
  
  /**
   * Get or set boost functions.
   *
   * This only has an impact when the parser is set to Dismax. Lucene-based queries
   * can use boost functions within the query, and don't need a separate mechanism
   * for specifying boost functions.
   *
   * @see http://wiki.apache.org/solr/DisMaxQParserPlugin#bf_.28Boost_Functions.29
   * @see apachesolr_search_add_boost_params() for a more detailed boost setting that can 
   *  be added with mergeParams().
   * @param $array
   *  An indexed array of boost functions.
   *  <code>array('func1()', 'func2()')</code>
   *
   * @return
   *  - setter: This object
   *  - getter: the array of boost functions. 
   */
  public function boostFunctions($array = NULL) {
    if (is_null($array)) {
      return $this->params['bf'];
    }
    
    $this->params['bf'] = $array;
    return $this;
  }
  /**
   * Get or set boost queries.
   *
   * This ONLY has an impact when the parser is set to Dismax. This makes sense, as
   * Lucene-based queries can contain boosts in the query string, and needn't have
   * an extra field to accomplish this.
   * 
   * @see http://wiki.apache.org/solr/DisMaxQParserPlugin#bq_.28Boost_Query.29
   * @see apachesolr_search_add_boost_params() for a more detailed boost setting that can 
   *  be added with mergeParams().
   * @param $array
   *  A string or an indexed array of boost queries.
   *  <code>array('sticky:true^2.0', 'type:page^4.0')</code>
   *
   * @return
   *  - settter: This object
   *  - getter: The array of boost queries.
   */
  public function boostQueries($array = NULL) {
    if (is_null($array)) {
      return $this->params['bq'];
    }
    
    $this->params['bq'] = $array;
    return $this;
  }

  
  /**
   * Get or set the base path.
   *
   * @param $path 
   *  A path to prepend as the base path.
   * @return 
   *  - setter: this object
   *  - getter: the current path
   */
  public function basePath($path = NULL) {
    if (is_null($path)) {
      return $this->basePath;
    }
    $this->basePath = $path;
    return $this;
  }
  
  /**
   * Get or set the offset.
   * This is used for paging query results.
   *
   * @param $int
   *  The number of items to offset (skip). Used for paging.
   * @return
   *  - getter: this object
   *  - setter: The current offset.
   */
  public function offset($int = NULL) {
    if (is_null($int)) {
      return $this->offset;
    }
    $this->offset = $int;
    return $this;
  }
  /**
   * Alias for offset().
   */
  public function start($int = NULL) {
    return $this->offset($int);
  }

  /**
   * Get or set the limit on the number of rows that will be returned per request.
   * 
   * @param $int
   *  The number of items to return from the search.
   * @return
   *  - setter: this object
   *  - getter: the current number of objects that will be returned.
   */
  public function limit($int = NULL) {
    if(is_null($int)) {
      return $this->limit;
    }
    $this->limit = $int;
    return $this;
  }
  /**
   * Alias for limit().
   */
  public function rows($int = NULL) {
    return $this->limit($int);
  }

  /**
   * Get or set Solr parameters.
   *
   * The underlying API provides a "parameters" system that allows large
   * arrays of configuration data to be passed in. Use this to explicitly set
   * or get those params.
   *
   * Note that some of the functions on this class are convenience functions that
   * merely populate data in this array.
   *
   * Setting this will obliterate any other parameters, as it completely overwrites
   * the existing parameters. If you are uncertain, it is advised that you 
   * use mergeParams to set params without overwriting all params.
   *
   * @param $array
   *  An associative array of parameters.
   * @return
   *  - setter: This object
   *  - getter: The current array of parameters, including modifications by helper methods.
   */
  public function params($array = NULL) {
    if (is_null($array)) {
      return $this->params;
    }
    $this->params = $array;
    return $this;
  }

  /**
   * Merge the params into the existing parameters.
   *
   * Entries in $array will overwrite any entries with the same key in
   * the existing parameters, but non-conflicting values will be merged.
   *
   * @param $array
   *  Parameters to merge into the current params.
   * @return This object.
   */
  public function mergeParams($array) {
    $this->params = array_merge($this->params, $array);
    return $this;
  }

  /**
   * Turn on or off debugging.
   *
   * Debugging data is included in the response object:
   *
   * <code>
   * $response = solrq('Search me')->debug()->search();
   * print_r($result->responseHeader);
   * print_r($result->debug);
   * </code>
   * @param $bool
   *  If TRUE, debugging is enabled. If FALSE, debugging is disabled.
   * @param $showImplicitParams
   *  Some parameters are set on the remote end (based on defaults). If this
   *  flag is TRUE (the default), then those parameters are included in the debugging
   *  data along with the parameters set by you and by the local library. If this is
   *  set to FALSE, then only variables sent from the client are shown (note that that
   *  may include params set by the Apache Solr Service handler).
   * @return
   *  This object.
   */
  public function debug($bool = TRUE, $showImplicitParams = TRUE) {
    $debugFields = array('debugQuery', 'echoHandler');
    $val = $bool ? 'true' : 'false';

    foreach ($debugFields as $field) {
      $this->params[$field] = $val;
    }

    if ($bool) {
      $this->params['echoParams'] = $showImplicitParams ? 'all' : 'explicit';
    }
    else {
      unset($this->params['echoParams']);
    }

    return $this;
  }

  /**
   * Indicates whether spellchecking is enabled.
   *
   * @return
   *  TRUE if spellchecking is enabled. FALSE otherwise.
   */
  public function isSpellchecking() {
    return $this->checkBooleanParamIsSet('spellcheck');
  }

  /**
   * Indicates whether faceting is enabled.
   * @return
   *  TRUE if faceting is enabled. FALSE otherwise. note that this does not 
   *  indicate whether any facets will be searched -- only that faceting is enabled.
   */
  public function isFaceting() {
    return $this->checkBooleanParamIsSet('facet');
  }

  /**
   * Indicates whether spellchecking is enabled.
   * @return
   *  TRUE if highlighting is enabled. FALSE otherwise.
   */
  public function isHighlighting() {
    return $this->checkBooleanParamIsSet('hl');
  }

  /**
   * Indicates whether debugging is enabled.
   * @return
   *  TRUE if debugging is enabled. FALSE otherwise.
   */
  public function isDebugging() {
    return $this->checkBooleanParamIsSet('debugQuery');
  }

  /**
   * Internal check for boolean value of a field..
   */
  protected function checkBooleanParamIsSet($param) {
    return isset($this->params[$param]) && filter_var($this->params[$param], FILTER_VALIDATE_BOOLEAN);
  }

  /**
   * Execute a search and get the results.
   *
   * Note that this does only the minimal amount of preparation on a search. It does
   * not attempt (as apachesolr_search_execute() does) to do sophisticated parameter
   * setting. It assumes you have done that already.
   * 
   * @return
   *  The search results as an Apache_Solr_Response object.
   * @see Apache_Solr_Service
   */
  public function search() {

    // Do necessary quote encoding for XML payload.
    $query = htmlentities($this->query(), ENT_NOQUOTES, 'UTF-8');

    // Need to modify a few fields immediately before run.
    if ($this->isSpellchecking() && !isset($this->params['spellcheck.q'])) {
      $this->params['spellcheck.q'] = $query;
    }

    $response = $this->solr->search($query, $this->offset(), $this->limit(), $this->params);

    return $response;
  }

  /**
   * Get or set the Apache Solr Service instance.
   *
   * @param $instance
   *  An Apache Solr Service instance.
   * @return
   *  - setter: This object
   *  - getter: The Apache Solr Service instance.
   */
  public function solr(Apache_Solr_Service $instance = NULL) {
    if (is_null($instance)) {
      return $this->solr;
    }
    $this->solr = $instance;
    return $this;
  }

  /**
   * Get an instance of a Drupal_Solr_Query_Interface.
   *
   * This class does not implement Drupal_Solr_Query_Interface, but it provides
   * this method for retrieving and instance of the class.
   *
   * @see apachesolr_Drupal_query().
   * @return 
   *  Instance of Drupal_Solr_Query_Interface.
   */
  public function drupalSolrQuery() {

    if (!function_exists('apachesolr_drupal_query')) {
      throw new Exception('Function apachesolr_drupal_query was not found. This does not look like Drupal (Or maybe the apachesolr module is not installed.).');
    }

    return apachesolr_drupal_query(
      $this->query(),
      $this->filters(),
      $this->sort(),
      $this->path(),
      $this->solr
    );
  }

  /**
   * Take a Drupal Solr object and import it into the present object.
   *
   * This may be called within a constructor.
   */
  protected function extractDrupalSolrInfo(Drupal_Solr_Query_Interface $object) {
    $params = array();

    // The apachesolr module seems to assume dismax. So we set this again, 
    // but allow the extracted params to override.
    $this->useQueryParser(self::QUERY_PARSER_DISMAX);

    // We can't use apachesolr_modify_query() because it executes a hook that 
    // should only be executed once. Really, all of this should have been 
    // present as part of Drupal_Solr_Query_Interface.
    //apachesolr_modify_query($object, $newParams, __FUNCTION__);

    // Extract the sort.
    if ($query && !$params['sort']) {
      $sort = $query->get_solrsort();
      $sortstring = $sort['#name'] .' '. $sort['#direction'];
    }

    $this->extractFilterQueries($object, $params);

    // Now we pack data into the existing params.
    $this->mergeParams($params);

  }

  /**
   * Yet another function that does something Drupal_Solr_Query_Interface 
   * implementations should do already, but don't.
   */
  protected function extractFilterQueries(Drupal_Solr_Query_Interface $query, &$params) {

    if ($query && ($fq = $query->get_fq())) {
      foreach ($fq as $delta => $values) {
        if (is_array($values) || is_object($values)) {
          foreach ($values as $value) {
            $params['fq'][$delta][] = $value;
          }
        }
      }
    }

    $ors = array();
    $facet_info = apachesolr_get_facet_definitions();
    foreach ($facet_info as $infos) {
      foreach($infos as $delta => $facet) {
        if ($facet['operator'] == 'OR') {
          $ors[] = $delta;
        }
      }
    }

    if ($filter_queries = $params['fq']) {
      foreach ($filter_queries as $delta => $value) {
        $fq = $tag = '';
        $op = 'AND';
        // CCK facet field block deltas are not the same as their Solr index field names.
        $cck_delta = '';
        if (strpos($delta, '_cck_')) {
          $cck_delta = trim(drupal_substr($delta, 7, drupal_strlen($delta)));
        }
        if (in_array($delta, $ors) || in_array($cck_delta, $ors)) {
          $tag = "{!tag=$delta}";
          $op = 'OR';
        }
        $fq = implode(" $op ", $params['fq'][$delta]);
        $params['fq'][] = $tag . $fq;
        unset($params['fq'][$delta]);
      }
    }
  }
}
