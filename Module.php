<?php
namespace CopyResources;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include sprintf('%s/config/module.config.php', __DIR__);
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        $acl = $this->getServiceLocator()->get('Omeka\Acl');

        // Allow all roles to access copy actions. Rely on native permissions to
        // restrict resource creation should someone directly access copy forms.
        $acl->allow(null, 'CopyResources\Controller\Admin\Index', ['copy-confirm', 'copy']);
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $acl = $this->getServiceLocator()->get('Omeka\Acl');

        // Add copy icons to resource browse pages.
        $copyEvents = [
            [
                'controller' => 'Omeka\Controller\Admin\Item',
                'resource_name' => 'items',
                'is_allowed_callback' => fn ($resource) => $resource->userIsAllowed('create'),
            ],
            [
                'controller' => 'Omeka\Controller\Admin\ItemSet',
                'resource_name' => 'item_sets',
                'is_allowed_callback' => fn ($resource) => $resource->userIsAllowed('create'),
            ],
            [
                'controller' => 'Omeka\Controller\SiteAdmin\Page',
                'resource_name' => 'site_pages',
                'is_allowed_callback' => fn ($resource) => $resource->site()->userIsAllowed('add-page'),
            ],
            [
                'controller' => 'Omeka\Controller\SiteAdmin\Index',
                'resource_name' => 'sites',
                'is_allowed_callback' => fn ($resource) => $acl->userIsAllowed('Omeka\Entity\Site', 'create'),
            ],
        ];
        foreach ($copyEvents as $copyEvent) {
            $sharedEventManager->attach(
                $copyEvent['controller'],
                'view.browse.actions',
                function (Event $event) use ($copyEvent) {
                    $resource = $event->getParam('resource');
                    if (!$copyEvent['is_allowed_callback']($resource)) {
                        return;
                    }
                    $view = $event->getTarget();
                    echo sprintf('<li>%s</li>', $view->hyperlink('', '#', [
                        'data-sidebar-selector' => '#sidebar',
                        'data-sidebar-content-url' => $view->url('admin/copy-resources', ['action' => 'copy-confirm', 'resource-name' => $copyEvent['resource_name'], 'id' => $resource->id()]),
                        'class' => 'far fa-copy sidebar-content',
                        'title' => $view->translate('Copy'),
                        'aria-label' => $view->translate('Copy'),
                    ]));
                }
            );
        }

        // Add copy buttons to resource show pages.
        $copyEvents = [
            [
                'controller' => 'Omeka\Controller\Admin\Item',
                'event' => 'view.show.page_actions',
                'resource_name' => 'items',
                'is_allowed_callback' => fn ($resource) => $resource->userIsAllowed('create'),
            ],
            [
                'controller' => 'Omeka\Controller\Admin\ItemSet',
                'event' => 'view.show.page_actions',
                'resource_name' => 'item_sets',
                'is_allowed_callback' => fn ($resource) => $resource->userIsAllowed('create'),
            ],
            [
                'controller' => 'Omeka\Controller\SiteAdmin\Page',
                'event' => 'view.edit.page_actions',
                'resource_name' => 'site_pages',
                'is_allowed_callback' => fn ($resource) => $resource->site()->userIsAllowed('add-page'),
            ],
            [
                'controller' => 'Omeka\Controller\SiteAdmin\Index',
                'event' => 'view.show.page_actions',
                'resource_name' => 'sites',
                'is_allowed_callback' => fn ($resource) => $acl->userIsAllowed('Omeka\Entity\Site', 'create'),
            ],
        ];
        foreach ($copyEvents as $copyEvent) {
            $sharedEventManager->attach(
                $copyEvent['controller'],
                $copyEvent['event'],
                function (Event $event) use ($copyEvent) {
                    $view = $event->getTarget();
                    $resource = $event->getParam('resource');
                    if (!$copyEvent['is_allowed_callback']($resource)) {
                        return;
                    }
                    echo $view->hyperlink($view->translate('Copy'), '#', [
                        'data-sidebar-selector' => '#copy-resources-sidebar',
                        'data-sidebar-content-url' => $view->url('admin/copy-resources', ['action' => 'copy-confirm', 'resource-name' => $copyEvent['resource_name'], 'id' => $resource->id()]),
                        'class' => 'button sidebar-content',
                    ]);
                }
            );
        }

        // Add copy sidebars to resource show pages.
        $copyEvents = [
            ['controller' => 'Omeka\Controller\Admin\Item', 'event' => 'view.show.after'],
            ['controller' => 'Omeka\Controller\Admin\ItemSet', 'event' => 'view.show.after'],
            ['controller' => 'Omeka\Controller\SiteAdmin\Page', 'event' => 'view.edit.after'],
            ['controller' => 'Omeka\Controller\SiteAdmin\Index', 'event' => 'view.show.after'],
        ];
        foreach ($copyEvents as $copyEvent) {
            $sharedEventManager->attach(
                $copyEvent['controller'],
                $copyEvent['event'],
                function (Event $event) {
                    $view = $event->getTarget();
                    echo sprintf(
                        '<div id="copy-resources-sidebar" class="sidebar">%s<div class="sidebar-content"></div></div>',
                        $view->hyperlink('', '#', ['class' => 'sidebar-close o-icon-close', 'title' => $view->translate('Close')])
                    );
                }
            );
        }
    }
}
