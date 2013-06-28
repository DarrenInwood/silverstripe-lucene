<?php

// Solr needs to be set up with fields that match the fields set up in the Lucene config.

class SolrLuceneBackend extends LuceneBackend {

    public static $default_config = array(
        'solr_server' => 'http://localhost:8983/solr',
        'default_query' => '*:*',                                // Default query to run if nothing is submitted
        'rows' => 25,                                            // Number of rows to return per page
    );

    protected $config = array();

    //////////     Solr-specific Configuration

    public function __construct(&$frontend, $config = null) {

        // Override defaults with passed in config
        if ( $config === null ) {
            $config = array();
        }

        $this->config = array_merge(
            self::$default_config,
            $config
        );

        parent::__construct($frontend);
    }

    public static function checkPrerequisites($debug=false) {
        $has_curl = extension_loaded('curl');
        if ( $debug ) {
            if ( $has_curl ) {
                echo '<p>PHP extension <kbd>cURL</kbd> is enabled.  ' .
                    'This is the correct setting.</p>';
            } else {
                echo '<p>PHP extension <kbd>cURL</kbd> is disabled.  ' .
                    'To use the Solr backend, please enable this PHP extension.</p>';
            }
        }
        return $has_curl;
    }

    //////////     Runtime methods

    // Do a POST to a specified URL, using cURL
    protected function doPost($url, $postData) {

        //open connection
        $ch = curl_init();

        //set the url, number of POST vars, POST data
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));

        // receive server response
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        //execute post
        $result = curl_exec($ch);

        //close connection
        curl_close($ch);

        return $result;
    }

    /**
     * Indexes any DataObject which has the LuceneSearchable extension.
     * @param $item (DataObject) The object to index.
     */
    public function index($item) {
        if ( ! Object::has_extension($item->ClassName, 'LuceneSearchable') ) {
            return;
        }
        $this->doIndex($item);
        if ( $this->isAutoCommit ) {
            $this->commit();
        }
    }

    public function doIndex($item) {

        $xml = '<add overwrite="true"><doc>';

        // We use the ClassName and ID as the unique key
        $xml .= '<field name="id">' . $item->ClassName . ':' .$item->ID . '</field>';

        // Add in text extracted from files, if any
        $extracted_text = $this->extract_text($item);
        if ( $extracted_text ) {
            $xml .= sprintf(
                '<field name="text"><![CDATA[%s]]></field>',
                htmlentities($extracted_text, ENT_QUOTES, $this->frontend->getConfig('encoding'))
            );
        }

        // Index the fields we've specified in the config
        $fields = $item->getSearchedVars();
        $used = array();
        foreach( $fields as $fieldName ) {
            if ( isset($used[$fieldName]) ) {
                continue;
            }
            $config = $item->getLuceneFieldConfig($fieldName);
            $name = $config['name'];
            if ( $name === 'ID' ) {
                $name = 'ObjectID';
            }
            // Account for array return values (multi-value fields)
            $values = $this->getSolrField($item, $fieldName);
            if ( !is_array($values) ) {
                if ( $values !== null && $values !== '' ) {
                    $values = array($values);
                } else {
                    $values = array();
                }
            }
            // Include array values multiple times
            foreach( $values as $value ) {
                $xml .= sprintf(
                    '<field name="%s"><![CDATA[%s]]></field>',
                    htmlentities($name, ENT_QUOTES, 'UTF-8'),
                    htmlentities($value, ENT_QUOTES, $this->frontend->getConfig('encoding'))
                );
            }
            $used[$fieldName] = true;
        }

        // Add URL if we have a function called Link().  We didn't use the
        // extraSearchFields mechanism for this because it's not a property on
        // all objects, so this is the most sensible place for it.
        if ( method_exists(get_class($item), 'Link') && ! in_array('Link', $fields) ) {
            $xml .= '<field name="Link"><![CDATA[' .
                htmlentities($item->Link(), ENT_QUOTES, $this->frontend->getConfig('encoding')) .
                ']]></field>';
        }

        $xml .= '</doc></add>';

        $this->doPost( $this->config['solr_server'].'/update', array('stream.body' => $xml) );
    }

    /**
     * Queries the search engine and returns results.
     * All dataobjects returned have two additional properties added:
     *   - LuceneRecordID: the Lucene 'Document ID' which can be used to delete 
     *     the record from the database.
     *   - LuceneScore: the 'score' assigned to the result by Lucene.
     * An additional property 'totalHits' is set on the DataObjectSet, showing 
     * how many hits there were in total.
     * @param $query_string (String) The query to send to the search engine.  
     *          This could be a string, or it could be a org.apache.lucene.search.Query
     *          object.
     * @return (DataObjectSet) A DataObjectSet of DataObject search results. 
     *          An additional property 'totalHits' is set on the DataObjectSet.
     */
    public function find($query_string) {
        return $this->findWithSort($query_string, 'score');
    }

    /**
     * Queries the search engine and returns results, with sorting and faceting.
     * All dataobjects returned have two additional properties added:
     *   - LuceneRecordID: the Lucene 'document ID' which can be used to delete 
     *     the record from the database.
     *   - LuceneScore: the 'score' assigned to the result by Lucene.
     * An additional property 'totalHits' is set on the DataObjectSet, showing 
     * how many hits there were in total.
     * @param $query_string (String) The query to send to the search engine.  
     *          This could be a string, or it could be a org.apache.lucene.search.Query
     *          object.
     * @param $sort (Mixed) This could either be the name of a field to 
     *          sort on, or a org.apache.lucene.search.Sort object.  You can 
     *          sort by the Lucene 'score' field to order things by relevance.
     * @param $reverse (Boolean) If a field name string is used for $sort, 
     *          determines whether the results will be ordered in normal or 
     *          reverse order.  If a org.apache.lucene.search.Sort object is 
     *          used for $sort, this parameter is ignored - you should include
     *          all your sorting requirements in the $sort object.
     * @param $start (Int) The (zero-indexed) first result to show in the result set.
     *          Used for pagination.
     * @param $rows (Int) The number of results to return in this result set. Used for
     *          pagination.
     * @params $extra (Array) An array of extra parameters to add to the final query
     *          sent to the Solr server.  Eg. array( 'debugQuery=on', 'indent=on' )
     * @return (DataObjectSet) A DataObjectSet of DataObject search results. 
     *          An additional property 'totalHits' is set on the DataObjectSet.
     */
    public function findWithSort($query_string, $sort, $reverse=false, $start=null, $rows=null, $extra=array()) {
        // Get results

        // Create request
        $request =
            $this->config['solr_server'].'/select' .
            '?q=' . urlencode($query_string?$query_string:'*:*') . // The query string - defaults to 'all records' if nothing
            '&version=2.2' . // version of query language to use
            ( isset($extras['start']) ? '' : '&start=' . ($start===null?0:$start)) . // First result to return (pagination)
            ( isset($extras['rows']) ? '' : '&rows=' . ($rows===null?$this->config['rows']:$rows)) . // Number of results on a page (pagination)
            ( isset($extras['sort']) ? '' : '&sort=' . urlencode($sort?($sort.' '.($reverse?'desc':'asc')):'score desc')) . // what to sort on (defaults to score, aka relevance)
    //        '&indent=on' .
    //        '&debugQuery=on' .
            '&wt=json';  // Return in JSON format

        if ( !is_array($extra) ) {
            $extra = array();
        }
        foreach( $extra as $string ) {
            $request .= '&' . $string;
        }

        //Debug::dump($request);

        $response_string = @file_get_contents( $request );
        $response = json_decode($response_string, true);

        //Debug::dump($response_string);

        $out = ArrayList::create();

        if ( !$response ||
            !is_array($response) ||
            !is_array($response['response']) ||
            !is_array($response['response']['docs']) ) {
            // We got some kind of illegal response, or no results
            return $out;
        }

        // Create result output set
        foreach( $response['response']['docs'] as $doc ) {
            $obj = DataObject::get_by_id($doc['ClassName'], $doc['ObjectID']);
            if ( ! $obj ) continue;
            $out->push($obj);
        }

        // Add total hit count
        $out->totalHits = 0;
        if ( isset($response['response']['numFound']) ) {
            $out->totalHits = (int)$response['response']['numFound'];
        }

        // Add facet counts
        $out->facetCounts = new ArrayList();
        if ( isset($response['facet_counts']) && is_array($response['facet_counts']) &&
            isset($response['facet_counts']['facet_fields']) && is_array($response['facet_counts']['facet_fields']) ) {
            foreach( $response['facet_counts']['facet_fields'] as $facetField => $facetCounts ) {
                $out->facetCounts[$facetField] = new ArrayList();
                for ( $i = 0; $i < count($facetCounts); $i+=2 ) {
                    $out->facetCounts[$facetField][ $facetCounts[$i] ] = $facetCounts[$i+1];
                }
            }
        }

        return $out;

    }

    /**
     * Deletes a DataObject from the search index.
     * @param $item (DataObject) The item to delete.
     */
    public function delete($item) {
        $this->doDelete($item);
        if ( $this->isAutoCommit ) {
            $this->commit();
        }
    }

    public function doDelete($item) {
        $xml = '<delete><query>ObjectID:'.$item->ID.'</query><query>ClassName:'.$item->ClassName . '</query></delete>';
        file_get_contents( $this->config['solr_server'] . '/update?stream.body='. urlencode($xml) );
    }

    public function wipeIndex() {
        $xml = '<delete><query>*:*</query></delete>';
        file_get_contents( $this->config['solr_server'] . '/update?stream.body='. urlencode($xml) );
        if ( $this->isAutoCommit ) {
            $this->commit();
        }
    }

    //////////     Solr-specific helper functions

    public function numDocs() {
        $raw_response = file_get_contents( $this->config['solr_server'] . '/select?q=*:*&rows=0&wt=json' );
        $response = json_decode($raw_response, true);
        if ( !$response || !is_array($response) || !isset($response['response']) || !isset($response['response']['numFound']) ) {
            return 0;
        }
        return (int)$response['response']['numFound'];
    }

    public function commit() {
        $xml = '<commit/>';
        file_get_contents( $this->config['solr_server'] . '/update?stream.body='. urlencode($xml) );
    }

    public function optimize() {
        $xml = '<optimize/>';
        file_get_contents( $this->config['solr_server'] . '/update?stream.body='. urlencode($xml) );
    }

    // Solr runs as an external server, so we don't need to open/close anything
    public function close() {
        return;
    }

    /**
     * Builder method for returning a string value based on the DataObject field.
     * Runs values through the appropriate content filters.
     *
     * @access private
     * @param   DataObject  $object     The DataObject from which to extract a
     *                                  Zend field.
     * @param   String      $fieldName  The name of the field to fetch a value for.
     * @return  String      The string value of the field in question
     */
    protected function getSolrField($object, $fieldName) {
        $config = $object->getLuceneFieldConfig($fieldName);

        // Recurses through dot-notation.
        $value = self::getFieldValue($object, $fieldName);
        if ( $config['content_filter'] ) {
            // Run through the content filter, if we have one.
            $value = call_user_func($config['content_filter'], $value);
        }

        if ( ! $value ) $value = '';

        return $value;
    }

}

