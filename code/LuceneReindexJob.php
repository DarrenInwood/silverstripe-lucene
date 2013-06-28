<?php

/**
 * The job description class for reindexing the search index via the Queued Jobs
 * SilverStripe module.
 *
 * @package lucene-silverstripe-module
 * @author Darren Inwood <darren.inwood@chrometoaster.com>
 */
class LuceneReindexJob extends AbstractQueuedJob implements QueuedJob {

    public function getTitle() {
        return _t('Lucene.ReindexJobTitle', 'Rebuild the Lucene search engine index');
    }

    public function getSignature() {
        return 'LuceneReindexJob';
    }

    public function setup() {

        $lucene = Lucene::singleton();

        // Wipe current index
        $lucene->wipeIndex();

        $data = $lucene->getAllIndexableObjects();
        $this->addMessage('Got '.count($data).' items to index');

        $this->totalSteps = count($data);
        $this->currentStep = 0;

        // Store a list of classes to index
        $possibleClasses = ClassInfo::subclassesFor('DataObject');
        $extendedClasses = array();
        foreach( $possibleClasses as $possibleClass ) {
            if ( Object::has_extension($possibleClass, 'LuceneSearchable') ) {
                $extendedClasses[] = $possibleClass;
            }
        }
        $this->jobData['extendedClasses'] = $extendedClasses;

        $this->addMessage('Classes to index: '.implode(', ', $extendedClasses));

        // Mark which class to start on
        $this->jobData['currentClass'] = null;
        $this->jobData['currentID'] = 0;

    }

    public function process() {

        $lucene = Lucene::singleton();

        // Using an odd limit makes the 'Completed' stat
        // on the Jobs page go up in non-even increments, which feels more
        // accurate than even lots of 100 or 1000
        $objects = null;
        if ( $this->jobData['currentClass'] !== null ) {
            $objects = DataObject::get(
                $this->jobData['currentClass'], // class
                '"'.$this->jobData['currentClass'].'"."ID" > '.$this->jobData['currentID'], // filter
                '"ID" ASC', // orderby
                '', // join
                '127' // limit
            );
        }
        if ( $objects !== null && $objects->count() > 0 ) {
            foreach ( $objects as $obj ) {
                $lucene->backend->doIndex($obj);
                $this->currentStep++;
                $this->jobData['currentID'] = $obj->ID;
                $obj->destroy();
                $obj = null;
                unset($obj);
            }
        } else {
            if ( count($this->jobData['extendedClasses']) === 0 ) {
                $this->isComplete = true;
                $this->addMessage('Completed.');
                $lucene->commit();
                $lucene->close();
                return;
            }
            $this->jobData['currentClass'] = array_pop($this->jobData['extendedClasses']);
            $this->addMessage('Indexing '.$this->jobData['currentClass'].' objects');
            $this->jobData['currentID'] = 0;
        }
        $lucene->commit();
        $lucene->close();
    }

}


