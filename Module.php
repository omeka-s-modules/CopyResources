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
                $view = $event->getTarget();
                $resource = $event->getParam('resource');
                if ($resource->userIsAllowed('create')) {
                    echo sprintf('<li>%s</li>', $view->hyperlink('', '#', [
                        'data-sidebar-selector' => '#sidebar',
                        'data-sidebar-content-url' => $resource->url('delete-confirm'),
                        'class' => 'fas fa-copy sidebar-content',
                        'title' => $view->translate('Copy'),
                        'aria-label' => $view->translate('Copy'),
                    ]));
                }
            }
        );
    }
}
