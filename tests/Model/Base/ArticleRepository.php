<?php

namespace Model\Base;

/**
 * Base class of repository of Model\Article document.
 */
abstract class ArticleRepository extends \Mongator\Repository
{

    /**
     * {@inheritdoc}
     */
    public function __construct(\Mongator\Mongator $mongator)
    {
        $this->documentClass = 'Model\Article';
        $this->isFile = false;
        $this->collectionName = 'articles';

        parent::__construct($mongator);
    }

    /**
     * {@inheritdoc}
     */
    public function idToMongo($id)
    {
        if (!$id instanceof \MongoId) {
            $id = new \MongoId($id);
        }

        return $id;
    }

    /**
     * Save documents.
     *
     * @param mixed $documents          A document or an array of documents.
     * @param array $batchInsertOptions The options for the batch insert operation (optional).
     * @param array $updateOptions      The options for the update operation (optional).
     */
    public function save($documents, array $batchInsertOptions = array(), array $updateOptions = array())
    {
        $repository = $this;

        if (!is_array($documents)) {
            $documents = array($documents);
        }

        $identityMap =& $this->getIdentityMap()->allByReference();
        $collection = $this->getCollection();

        $inserts = array();
        $updates = array();
        foreach ($documents as $document) {
            $document->saveReferences();
            $document->updateReferenceFields();
            if ($document->isNew()) {
                $inserts[spl_object_hash($document)] = $document;
            } else {
                $updates[] = $document;
            }
        }

        // insert
        if ($inserts) {
            $a = array();
            foreach ($inserts as $oid => $document) {
                if (!$document->isModified()) {
                    continue;
                }
                $document->oncePreInsertEvent();

                $a[$oid] = $document->queryForSave();
                $a[$oid]['_id'] = new \MongoId();
            }

            if ($a) {
                $collection->batchInsert($a, $batchInsertOptions);

                foreach ($a as $oid => $data) {
                    $document = $inserts[$oid];

                    $document->setId($data['_id']);
                    $document->setIsNew(false);
                    $document->clearModified();
                    $identityMap[(string) $data['_id']] = $document;
                    $document->resetGroups();
                $document->oncePostInsertEvent();

                }
            }
        }

        // updates
        foreach ($updates as $document) {
            if ($document->isModified()) {
                $document->oncePreUpdateEvent();

                $query = $document->queryForSave();
                $collection->update(array('_id' => $this->idToMongo($document->getId())), $query, $updateOptions);
                $document->clearModified();
                $document->resetGroups();
                $document->oncePostUpdateEvent();
            }
        }
    }

    /**
     * Delete documents.
     *
     * @param mixed $documents A document or an array of documents.
     */
    public function delete($documents)
    {
        if (!is_array($documents)) {
            $documents = array($documents);
        }

        $ids = array();
        foreach ($documents as $document) {
            $ids[] = $id = $this->idToMongo($document->getId());
        }

        foreach ($documents as $document) {
            $document->processOnDelete();
        }

        $this->getCollection()->remove(array('_id' => array('$in' => $ids)));


        $identityMap =& $this->getIdentityMap()->allByReference();
        foreach ($documents as $document) {
            unset($identityMap[(string) $document->getId()]);
            $document->setIsNew(true);
            $document->setId(null);
        }
    }

    /**
     * Fixes the missing references.
     */
    public function fixMissingReferences($documentsPerBatch = 1000)
    {
        $skip = 0;
        do {
            $cursor = $this->getCollection()->find(array('author' => array('$exists' => 1)), array('author' => 1))->limit($documentsPerBatch)->skip($skip);
            $ids = array_unique(array_values(array_map(function ($result) { return $result['author']; }, iterator_to_array($cursor))));
            if (count($ids)) {
                $collection = $this->getMongator()->getRepository('Model\Author')->getCollection();
                $referenceCursor = $collection->find(array('_id' => array('$in' => $ids)), array('_id' => 1));
                $referenceIds =  array_values(array_map(function ($result) { return $result['_id']; }, iterator_to_array($referenceCursor)));

                if ($idsDiff = array_diff($ids, $referenceIds)) {
                    $this->remove(array('author' => array('$in' => $idsDiff)), array('multiple' => 1));
                }
            }

            $skip += $documentsPerBatch;
        } while(count($ids));
        $skip = 0;
        do {
            $cursor = $this->getCollection()->find(array('information' => array('$exists' => 1)), array('information' => 1))->limit($documentsPerBatch)->skip($skip);
            $ids = array_unique(array_values(array_map(function ($result) { return $result['information']; }, iterator_to_array($cursor))));
            if (count($ids)) {
                $collection = $this->getMongator()->getRepository('Model\ArticleInformation')->getCollection();
                $referenceCursor = $collection->find(array('_id' => array('$in' => $ids)), array('_id' => 1));
                $referenceIds =  array_values(array_map(function ($result) { return $result['_id']; }, iterator_to_array($referenceCursor)));

                if ($idsDiff = array_diff($ids, $referenceIds)) {
                    $this->remove(array('information' => array('$in' => $idsDiff)), array('multiple' => 1));
                }
            }

            $skip += $documentsPerBatch;
        } while(count($ids));

        $skip = 0;
        do {
            $cursor = $this->getCollection()->find(array('categories' => array('$exists' => 1)), array('categories' => 1))->limit($documentsPerBatch)->skip($skip);
            $ids = array_unique(array_reduce(
                array_values(array_map(function ($result) { return $result['categories']; }, iterator_to_array($cursor)))
            , 'array_merge', array()));
            if (count($ids)) {
                $collection = $this->getMongator()->getRepository('Model\Category')->getCollection();
                $referenceCursor = $collection->find(array('_id' => array('$in' => $ids)), array('_id' => 1));
                $referenceIds =  array_values(array_map(function ($result) { return $result['_id']; }, iterator_to_array($referenceCursor)));

                if ($idsDiff = array_diff($ids, $referenceIds)) {
                    $this->update(array(), array('$pull' => array('categories' => array('$in' => $idsDiff))), array('multiple' => 1));
                }
            }

            $skip += $documentsPerBatch;
        } while(count($ids));
    }
}