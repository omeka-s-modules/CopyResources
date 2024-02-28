<?php
namespace CopyResources\Service\Stdlib;

use Interop\Container\ContainerInterface;
use CopyResources\Stdlib\CopyResources;
use Zend\ServiceManager\Factory\FactoryInterface;

class CopyResourcesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new CopyResources(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\EntityManager'),
            $services->get('EventManager')
        );
    }
}
