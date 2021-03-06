<?php

namespace Model\Base;

/**
 * Base class of query of Model\Message document.
 */
abstract class MessageQuery extends \Mongator\Query\Query
{

    /**
     * {@inheritdoc}
     */
    public function all()
    {
        $repository = $this->getRepository();
        $mongator = $repository->getMongator();
        $documentClass = $repository->getDocumentClass();
        $identityMap =& $repository->getIdentityMap()->allByReference();
        $isFile = $repository->isFile();

        $documents = array();
        foreach ($this->execute() as $data) {
            
            $id = (string) $data['_id'];
            if (isset($identityMap[$id])) {
                $document = $identityMap[$id];
                $document->addQueryHash($this->getHash());
            } else {
                if ($isFile) {
                    $file = $data;
                    $data = $file->file;
                    $data['file'] = $file;
                }
                $data['_query_hash'] = $this->getHash();
                $data['_query_fields'] = $this->getFields();

                $document = new $documentClass($mongator);
                $document->setDocumentData($data);

                $identityMap[$id] = $document;
            }
            $documents[$id] = $document;
        }

        if ($references = $this->getReferences()) {
            $mongator = $this->getRepository()->getMongator();
            $metadata = $mongator->getMetadataFactory()->getClass($this->getRepository()->getDocumentClass());
            foreach ($references as $referenceName) {
                // one
                if (isset($metadata['referencesOne'][$referenceName])) {
                    $reference = $metadata['referencesOne'][$referenceName];
                    $field = $reference['field'];

                    $ids = array();
                    foreach ($documents as $document) {
                        if ($id = $document->get($field)) {
                            $ids[] = $id;
                        }
                    }
                    if ($ids) {
                        $mongator->getRepository($reference['class'])->findById(array_unique($ids));
                    }

                    continue;
                }

                // many
                if (isset($metadata['referencesMany'][$referenceName])) {
                    $reference = $metadata['referencesMany'][$referenceName];
                    $field = $reference['field'];

                    $ids = array();
                    foreach ($documents as $document) {
                        if ($id = $document->get($field)) {
                            foreach ($id as $i) {
                                $ids[] = $i;
                            }
                        }
                    }
                    if ($ids) {
                        $mongator->getRepository($reference['class'])->findById(array_unique($ids));
                    }

                    continue;
                }

                // invalid
                throw new \RuntimeException(sprintf('The reference "%s" does not exist in the class "%s".', $referenceName, $documentClass));
            }
        }

        return $documents;
    }

    /**
     * Find by "author" field.
     *
     * @param mixed $value The value.
     *
     * @return Model\MessageQuery The query with added criteria (fluent interface).
     */
    public function findByAuthor($value)
    {
        $castValue = (string) $value;
        if ($castValue !== $value) throw new \Exception('Bad value: type string expected');
        
        return $this->mergeCriteria(array('author' => $castValue ));
    }

    /**
     * Find by "text" field.
     *
     * @param mixed $value The value.
     *
     * @return Model\MessageQuery The query with added criteria (fluent interface).
     */
    public function findByText($value)
    {
        $castValue = (string) $value;
        if ($castValue !== $value) throw new \Exception('Bad value: type string expected');
        
        return $this->mergeCriteria(array('text' => $castValue ));
    }

    /**
     * Find by "replyToId" reference.
     *
     * @param MongoId|Document $value
     *
     * @return Model\MessageQuery The query with added criteria (fluent interface).
     */
    private function findByReplyToId($value)
    {
        $id = $this->valueToMongoId($value);
        return $this->mergeCriteria(array('replyTo' => $id));
    }

    private function findByReplyToIdIds(array $ids)
    {
        $ids = $this->getRepository()->idsToMongo($ids);
        return $this->mergeCriteria(array('replyTo' => array('$in' => $ids)));
    }

    public function findByReplyTo($value)
    {
        return $this->findByReplyToId($value);
    }

    public function findByReplyToIds(array $ids)
    {
        return $this->findByReplyToIdIds($ids);
    }
}