<?php

/**
 * Base class for all backends.
 * Child classes must implement the methods below.
 */
abstract class LuceneBackend extends Object {

    protected static $extractor_classes = array();

    protected $frontend;

    //////////     Runtime operations

    public function __construct(&$frontend) {
        $this->frontend =& $frontend;
    }

    /**
     * Indexes any DataObject which has the LuceneSearchable extension.
     * Should be atomic and suitable for a single indexing operation rather than
     * bulk processing.  Should close all open files, etc, so that the application
     * can exit after calling this function with no ill effects.
     * @param $item (DataObject) The object to index.
     */
    abstract public function index($item);

    /**
     * Indexes any DataObject which has the LuceneSearchable extension.
     * Should be suitable for bulk loading; may leave files open etc.
     * If using this method, it is your responsibility to call commit() and/or
     * close().
     * @param $item (DataObject) The object to index.
     */
    abstract public function doIndex($item);

    /**
     * Queries the search engine and returns results.
     * @param $query_string (String) The query to send to the search engine.
     * @return (DataObjectSet) A DataObjectSet of DataObject search results.
     */
    abstract public function find($query_string);

    /**
     * Queries the search engone and returns results sorted by a specified field.
     * @param $query_string (String) The Lucene query language string to search.
     * @param $sortField (String) The field to sort on
     * @param $reverse (Boolean) True orders results in reverse, false (the
     *                  default) orders results normally.
     * @param $start (Int) The first result to return on this page
     * @param $rows (Int) Number of results to return on this page
     * @return DataObjectSet
     */
    abstract public function findWithSort($query_string, $sortField, $reverse=false, $start=null, $rows=null, $extra=array());

    /**
     * Deletes a DataObject from the search index.
     * Should be atomic and suitable for a single indexing operation rather than
     * bulk processing.  Should close all open files, etc, so that the application
     * can exit after calling this function with no ill effects.
     * @param $item (DataObject) The item to delete.
     */
    abstract public function delete($item);

    /**
     * Deletes a DataObject from the search index.
     * Should be suitable for bulk loading; may leave files open etc.
     * If using this method, it is your responsibility to call commit() and/or
     * close().
     * @param $item (DataObject) The item to delete.
     */
    abstract public function doDelete($item);

    abstract public function commit();

    // If you know you're going to be calling write() on a bunch of objects, you can
    // turn off automatically committing after each change here.
    // You should call commit() manually to save your set of changes into the search index.
    protected $isAutoCommit = true;
    public function setAutoCommit($defer=true) {
        $this->isAutoCommit = $defer;
    }

    abstract public function optimize();

    /**
     * Checks that all prerequisites for this backend to work are running.
     *
     * @param $debug   Set to true to output some debugging text. Used by
     *                 /Lucene/diagnose. Defaults to false.
     * @return Boolean
     */
    public static function checkPrerequisites($debug=false) {
    }

    /**
     * Blank out the index if it exists.
     */
    abstract public function wipeIndex();

    //////////     Non-backend-specific helper functions

    protected function extract_text($item) {
        if ( ! $item->is_a('File') ) return '';
        if ( $item->class == 'Folder' ) return '';
        foreach( self::get_text_extractor_classes() as $extractor_class ) {
            $extensions = new ReflectionClass($extractor_class);
            $extensions = $extensions->getStaticPropertyValue('extensions');
            if ( in_array(strtolower(File::get_file_extension($item->Filename)), $extensions) ) {
                continue;
            }
            // Try any that support the given file extension
            $content = call_user_func(
                array($extractor_class, 'extract'),
                Director::baseFolder().'/'.$item->Filename
            );
            if ( ! $content ) continue;
            return $content;
        }
        return '';
    }

    /**
     * @TODO Use Solr ExtractingRequestHandler if available
     *
     * Returns the list of available subclasses of LuceneTextExtractor
     * in the order in which they should be processed.  Order is determined by
     * the $priority static on each class.  Default is 100 for all inbuilt
     * classes, lower numbers get run first.
     *
     * @access private
     * @static
     * @return  Array   An array of strings containing classnames.
     */
    protected static function get_text_extractor_classes() {
        if ( ! self::$extractor_classes ) {
            $all_classes = ClassInfo::subclassesFor('LuceneTextExtractor');
            usort(
                $all_classes,
                create_function('$a, $b', '
                    $pa = new ReflectionClass($a);
                    $pa = $pa->getStaticPropertyValue(\'priority\');
                    $pb = new ReflectionClass($b);
                    $pb = $pb->getStaticPropertyValue(\'priority\');
                    if ( $pa == $pb ) return 0;
                    return ($pa < $pb) ? -1 : 1;'
                )
            );
            self::$extractor_classes = $all_classes;
        }
        return self::$extractor_classes;
    }

    /**
     * Function to reduce a Lucene field name to a string value.
     *
     * If the fieldname can't be resolved for the given object, returns an empty
     * string rather than failing.
     *
     * @param $object (DataObject) The dataobject to get the field value for
     * @param $fieldName (String) The name of the field, as specified in the
     *        Lucene config for this object.  This is the 'data source' in the config
     *        array, ie. the name of the field, method or relation on the object, not the
     *        name of the field added to the Lucene document.
     */
    public static function getFieldValue($object, $fieldName) {
        if ( strpos($fieldName, '.') === false ) {
            if ( $object->hasMethod($fieldName) ) {
                // Method on object
                return $object->$fieldName();
            } else {
                // Bog standard field
                return $object->$fieldName;
            }
        }
        // Using dot notation
        list($baseFieldName, $relationFieldName) = explode('.', $fieldName, 2);
        // has_one
        if ( in_array($baseFieldName, array_keys($object->has_one())) ) {
            $field = $object->getComponent($baseFieldName);
            return $this->getFieldValue($field, $relationFieldName);
        }
        // has_many
        if ( in_array($baseFieldName, array_keys($object->has_many())) ) {
            // loop through and get string values for all components
            $tmp = '';
            $components = $object->getComponents($baseFieldName);
            foreach( $components as $component ) {
                $tmp .= $this->getFieldValue($component, $relationFieldName)."\n";
            }
            return $tmp;
        }
        // many_many
        if ( in_array($baseFieldName, array_keys($object->many_many())) ) {
            // loop through and get string values for all components
            $tmp = '';
            $components = $object->getManyManyComponents($baseFieldName);
            foreach( $components as $component ) {
                $tmp .= $this->getFieldValue($component, $relationFieldName)."\n";
            }
            return $tmp;
        }
        // Nope, not able to be indexed  :-(
        return '';
    }

}

