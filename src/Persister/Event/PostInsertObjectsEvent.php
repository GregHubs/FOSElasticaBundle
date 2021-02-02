<?php

namespace FOS\ElasticaBundle\Persister\Event;

use FOS\ElasticaBundle\Event\AbstractEvent;
use FOS\ElasticaBundle\Persister\ObjectPersisterInterface;
use FOS\ElasticaBundle\Provider\PagerInterface;

final class PostInsertObjectsEvent extends AbstractEvent implements PersistEvent
{
    /**
     * @var PagerInterface
     */
    private $pager;

    /**
     * @var ObjectPersisterInterface
     */
    private $objectPersister;

    /**
     * @var object[]
     */
    private $objects;

    /**
     * @var array
     */
    private $options;

    public function __construct(PagerInterface $pager, ObjectPersisterInterface $objectPersister, array $objects, array $options)
    {
        $this->pager = $pager;
        $this->objectPersister = $objectPersister;
        $this->objects = $objects;
        $this->options = $options;
    }

    public function getPager(): PagerInterface
    {
        return $this->pager;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getObjectPersister(): ObjectPersisterInterface
    {
        return $this->objectPersister;
    }

    /**
     * @return object[]
     */
    public function getObjects(): array
    {
        return $this->objects;
    }
}
