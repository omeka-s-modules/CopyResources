<?php
namespace CopyResources;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include sprintf('%s/config/module.config.php', __DIR__);
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.browse.actions',
            function (Event $event) {
                $resource = $event->getParam('resource');
                if (!$resource->userIsAllowed('create')) {
                    return;
                }
                $view = $event->getTarget();
                echo sprintf('<li>%s</li>', $view->hyperlink('', '#', [
                    'data-sidebar-selector' => '#sidebar',
                    'data-sidebar-content-url' => $view->url('admin/copy-resources', ['action' => 'copy-confirm', 'resource-name' => 'items', 'id' => $resource->id()]),
                    'class' => 'fas fa-copy sidebar-content',
                    'title' => $view->translate('Copy'),
                    'aria-label' => $view->translate('Copy'),
                ]));
            }
        );
    }
}
