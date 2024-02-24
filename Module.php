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
        // Add copy icons to resource browse pages.
        $browseCopyActions = [
            ['controller' => 'Omeka\Controller\Admin\Item', 'resource_name' => 'items'],
            ['controller' => 'Omeka\Controller\Admin\ItemSet', 'resource_name' => 'item_sets'],
            ['controller' => 'Omeka\Controller\SiteAdmin\Page', 'resource_name' => 'site_pages'],
            ['controller' => 'Omeka\Controller\SiteAdmin\Index', 'resource_name' => 'sites'],
        ];
        foreach ($browseCopyActions as $browseCopyAction) {
            $sharedEventManager->attach(
                $browseCopyAction['controller'],
                'view.browse.actions',
                function (Event $event) use ($browseCopyAction) {
                    $resource = $event->getParam('resource');
                    if (!$resource->userIsAllowed('create')) {
                        return;
                    }
                    $view = $event->getTarget();
                    echo sprintf('<li>%s</li>', $view->hyperlink('', '#', [
                        'data-sidebar-selector' => '#sidebar',
                        'data-sidebar-content-url' => $view->url('admin/copy-resources', ['action' => 'copy-confirm', 'resource-name' => $browseCopyAction['resource_name'], 'id' => $resource->id()]),
                        'class' => 'fas fa-copy sidebar-content',
                        'title' => $view->translate('Copy'),
                        'aria-label' => $view->translate('Copy'),
                    ]));
                }
            );
        }
    }
}
