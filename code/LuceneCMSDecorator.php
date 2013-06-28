<?php

/**
 * Adds functions to LeftAndMain to rebuild the Lucene search index.
 *
 * @package lucene-silverstripe-module
 * @author Darren Inwood <darren.inwood@chrometoaster.com>
 */
class LuceneCMSDecorator extends LeftAndMainExtension {

    /**
     * Enables the extra button added via ZendSearchLuceneSiteConfig.
     * @static
     * @access public
     */
    public static $allowed_actions = array(
        'test',
        'rebuildLuceneIndex',
        'reindex',
        'diagnose',
        'getSolrSchemaXml',
    );

    /**
     * Receives the form submission which tells the index rebuild process to 
     * begin.
     *
     * @access public
     * @return      String          The AJAX response to send to the CMS.
     */
    public function rebuildLuceneIndex() {
        singleton('QueuedJobService')->queueJob(
            new LuceneReindexJob()
        );
        $this->owner->response->addHeader('X-Status', rawurlencode('Search index rebuild job added to Jobs queue.'));
        return $this->owner->getResponseNegotiator()->respond($this->owner->request);
    }

    /**
     * Debug method to allow manual reindexing with output via the URL 
     * /Lucene/reindex
     *
     * @access public
     * Note that this should NOT be used as a reindexing
     * process in production, as it doesn't allow for out of memory or script 
     * execution time problems.
     */
    public function reindex() {
        set_time_limit(600);
        $start = microtime(true);
        echo '<h1>Reindexing</h1>'."\n"; flush();
        echo 'Note that this process may die due to time limit or memory '
            .'exhaustion, and is purely for debugging purposes.  Use the '
            .'Queued Jobs reindex process for production indexing.'
            ."<br />\n<br />\n"; flush();
        $lucene = Lucene::singleton();
        $lucene->wipeIndex();
        $indexable = $lucene->getAllIndexableObjects();
        $count = 0;
        foreach( $indexable as $item ) {
            $obj = DataObject::get_by_id($item[0], $item[1]);
            if ( $obj ) {
                $count++;
                $lucene->backend->doIndex($obj);
                if ( $count % 10 == 0 ) {
                    echo '.'; 
                    flush();
                }
                if ( $count % 100 == 0 ) {
                    echo ' '.$count.' ';
                    flush();
                }
                if ( $count % 1000 == 0 ) {
                    echo '<br/>';
                    flush();
                }
            } else {
                echo 'Object '.$item[0].' '.$item[1].' was not found.'."<br />\n"; 
                flush();
            }
        }
        $lucene->commit();
        $lucene->close();
        echo "<br />\n".'Finished ('.round(microtime(true)-$start, 3).' seconds)'."<br />\n"; flush();
    }

    /**
     * Method for testing config
     */
    public function diagnose() {
        $lucene = Lucene::singleton();
        $backend = $lucene->getBackend();

        echo '<h1>Lucene Diagnosis</h1>';
        echo '<hr /><h2>Installed programs</h2>';
        // catdoc - scan older MS documents
        $catdoc = false;
        if ( defined('CATDOC_BINARY_LOCATION') && file_exists(CATDOC_BINARY_LOCATION) ) {
            $catdoc = CATDOC_BINARY_LOCATION;
        } else if ( file_exists('/usr/bin/catdoc') ) {
            $catdoc = '/usr/bin/catdoc';
        } else if ( file_exists('/usr/local/bin/catdoc') ) {
            $catdoc = '/usr/local/bin/catdoc';
        }
        if ( $catdoc ) {
            echo '<p>Utility <strong>catdoc</strong> is installed at '.$catdoc.' - older MS Office documents will be scanned.</p>';
        } else {
            echo '<p>Utility <strong>catdoc</strong> is not installed.  Older MS Office documents will not be scanned.</p>';
        }
        // pdftotext - scan PDF documents
        $pdftotext = false;
        if ( defined('PDFTOTEXT_BINARY_LOCATION') ) {
            $pdftotext = PDFTOTEXT_BINARY_LOCATION;
        } else if ( file_exists('/usr/bin/pdftotext') ) {
            $pdftotext = '/usr/bin/pdftotext';
        } else if ( file_exists('/usr/local/bin/pdftotext') ) {
            $pdftotext = '/usr/local/bin/pdftotext';
        }
        if ( $pdftotext ) {
            echo '<p>Utility <strong>pdftotext</strong> is installed at '.$pdftotext.'.</p>';
        } else {
            echo '<p>Utility <strong>pdftotext</strong> is not installed. The PDF2Text class will be used to scan PDF documents.</p>';
        }

        echo '<hr /><h2>Backend</h2>';
        echo '<p>The selected backend for your config is <strong>'.get_class($backend).'</strong>.</p>';

        switch ( get_class($backend) ) {
            case 'SolrLuceneBackend':
                echo '<p>If you haven\'t set your Solr config up yet, you can:</p>' .
                    '<ul><li>Generate a basic <a href="/Lucene/getSolrSchemaXml">schema.xml</a></li></ul>';
            break;
        }

        $backend_classes = ClassInfo::subclassesFor( 'LuceneBackend' );
        foreach( $backend_classes as $backend_class ) {
            if ( $backend_class === 'LuceneBackend' ) {
                continue;
            }
            echo '<h3>'.$backend_class.'</h3>';
            $backend_class::checkPrerequisites(true);
        }

        echo '<hr /><h2>Index</h2>';
        echo '<p>Number of records in the index: '.$lucene->numDocs().'</p>';
        echo '<p>Number of records in the index (excluding deleted records): '.$lucene->numDocs().'</p>';
        echo '<hr /><h2>Database setup</h2>';
        $max_packet = DB::query('SELECT @@max_allowed_packet AS size')->value();
        echo '<p>Your MySQL max_allowed_packet value is '.$max_packet.'.<br/>';
        if ( $max_packet >= 128 * 1024 * 1024 ) {
            echo 'This should be high enough to cope with large datasets.';
        } else {
            echo 'This may cause issues with large datasets.</p>';
            echo '<p>To rectify this, you can add the following lines to functions that may create large datasets, eg. search actions:</p>';
            echo '<pre>'
            .'DB::query(\'SET GLOBAL net_buffer_length=1000000\');'."\n"
            .'DB::query(\'SET GLOBAL max_allowed_packet=1000000000\');</pre>';
            echo '<p>Alternatively, you can set these config values in your MySQL server config file.';
        }
        echo '</p>';
        $log_bin = DB::query('SELECT @@log_bin AS log_bin')->value();
        if ( $log_bin == 0 ) {
            echo '<p>Your MySQL server is set to not use the binary log.<br/>'
            .'This is the correct setting.</p>';
        } else {
            echo '<p>Your MySQL server is set to use the binary log.<br/>'
            .'This will result in a large amount of disk space being used for '
            .'logging Lucene operations, which can use many GB of space with '
            .'large datasets.</p>';
            echo '<p>To rectify this, you can add the following lines to your _config.php:</p>';
            echo '<pre>'
            .'DB::query(\'SET GLOBAL log_bin=0\');'."\n"
            .'</pre>';
            echo '<p>Alternatively, you can set this config value in your MySQL server config file.';
        }

        $classes = ClassInfo::subclassesFor('DataObject');
        foreach( $classes as $class ) {
            if ( ! Object::has_extension($class, 'LuceneSearchable') ) {
                continue;
            }
            $class_config = singleton($class)->getLuceneClassConfig();
            echo '<hr/><h2>'.$class.'</h2>';
            echo '<h3>Class config</h3>';
            Debug::dump( $class_config );
            echo '<h3>Field config</h3>';
            foreach( singleton($class)->getSearchedVars() as $fieldname ) {
                echo '<h4>'.$fieldname.'</h4>';
                if ( $fieldname == 'Link' ) echo '<p>No output means that Link is not indexed for this class.</p>';
                @Debug::dump( singleton($class)->getLuceneFieldConfig($fieldname) );
            }
        }
    }

    // Generates a Solr schema.xml suitable for setting up Solr with the given config options.
    public function getSolrSchemaXml() {
        $lucene = Lucene::singleton();

        // Fields to include on the config
        $fields = array();

        $classes = ClassInfo::subclassesFor('DataObject');
        foreach( $classes as $class ) {
            if ( ! Object::has_extension($class, 'LuceneSearchable') ) {
                continue;
            }
            $obj = singleton($class);
            foreach( $obj->getSearchedVars() as $fieldname ) {
                if ( !isset($fields[$fieldname]) ) {
                    $config = $obj->getLuceneFieldConfig($fieldname);
                    // Allow for Zend Lucene style 'type' parameter (backwards compatibility)
                    $zend_type = false;
                    switch($config['type']) {
                        case 'keyword':
                            $zend_type = true;
                            $config['type'] = 'string';
                            $config['stored'] = 'false';
                            $config['indexed'] = 'true';
                        break;
                        case 'unstored':
                            $zend_type = true;
                            $config['type'] = 'text_ws';
                            $config['stored'] = 'false';
                            $config['indexed'] = 'true';
                        break;
                        case 'unindexed':
                            $zend_type = true;
                            $config['type'] = 'string';
                            $config['stored'] = 'true';
                            $config['indexed'] = 'false';
                        break;
                    }

                    // Try to pick type from database type
                    // string|long|float|date|currency|text|url|text_ws|text_general|
                    // text_en|text_en_splitting|text_en_splitting_tight|text_comma_delimited|textSpell
                    if ( !isset($config['type']) || $zend_type === true ) {
                        $field = $obj->dbObject($fieldname);
                        if ( $field !== null ) {
                            switch( $field->class ) {
                                case 'Boolean':
                                case 'StringField':
                                case 'Enum':
                                case 'MultiEnum':
                                    $config['type'] = 'string';
                                break;
                                case 'Date':
                                case 'Time':
                                case 'SS_Datetime':
                                    $config['type'] = 'date';
                                break;
                                case 'Decimal':
                                case 'Float':
                                    $config['type'] = 'float';
                                break;
                                case 'Double':
                                case 'Int':
                                case 'Year':
                                case 'Percentage':
                                    $config['type'] = 'long';
                                break;
                                case 'Money':
                                case 'Currency':
                                    $config['type'] = 'currency';
                                break;
                                case 'Text':
                                case 'Varchar':
                                case 'HTMLText':
                                case 'HTMLVarchar':
                                    $config['type'] = 'text_en_splitting';
                                break;
                            }
                        }
                        // Fall back to text_ws (text split on whitespace)
                        if ( !isset($config['type']) ) {
                            $config['type'] = 'text_ws';
                        }
                    }

                    // ID is a special case - we use ObjectID
                    if ( $config['name'] === 'ID' ) {
                        $config['name'] = 'ObjectID';
                        $config['stored'] = 'true';
                        $config['indexed'] = 'true';
                    }
                    // ClassName is always stored
                    if ( $config['name'] === 'ClassName' ) {
                        $config['stored'] = 'true';
                        $config['indexed'] = 'true';
                    }
                    // Default to indexing but not storing
                    if ( !isset($config['indexed']) ) {
                        $config['indexed'] = 'true';
                    }
                    if ( !isset($config['stored']) ) {
                        $config['stored'] = 'false';
                    }
                    // Multiple defaults to false
                    if ( !isset($config['multiple']) ) {
                        $config['multiple'] = 'false';
                    }
                    $fields[$fieldname] = array(
                        'Name' => $config['name'],
                        'Type' => $config['type'],
                        'Stored' => $config['stored'],
                        'Indexed' => $config['indexed'],
                        'Multiple' => $config['multiple'],
                    );
                }
            }
        }

        // Special cases

        $fields['ClassName']['Type'] = 'string';

        $fields['text'] = array(
            'Name' => 'body',
            'Type' => 'text_en_splitting',
            'Stored' => 'true',
            'Indexed' => 'true',
            'Multiple' => 'false',
        );

        $fields['LastEdited'] = array(
            'Name' => 'LastEdited',
            'Type' => 'date',
            'Stored' => 'true',
            'Indexed' => 'true',
            'Multiple' => 'false',
        );

        $fields['Link'] = array(
            'Name' => 'Link',
            'Type' => 'url',
            'Stored' => 'true',
            'Indexed' => 'false',
            'Multiple' => 'false',
        );

        return $this->owner->customise(array(
            'Fields' => new ArrayList($fields),
        ))->renderWith('SolrSchemaXml');

    }

}

