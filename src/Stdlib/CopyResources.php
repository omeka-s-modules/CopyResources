<?php
namespace CopyResources\Stdlib;

use Zend\ServiceManager\ServiceLocatorInterface;

class CopyResources
{
    protected $services;

    protected $entityManager;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
        $this->entityManager = $this->services->get('Omeka\EntityManager');
    }
}
