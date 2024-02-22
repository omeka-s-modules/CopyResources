<?php
namespace CopyResources\Service\Controller\Admin;

use Interop\Container\ContainerInterface;
use CopyResources\Controller\Admin\IndexController;
use Zend\ServiceManager\Factory\FactoryInterface;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $copyResources = $services->get('CopyResources\CopyResources');
        return new IndexController($copyResources);
    }
}
