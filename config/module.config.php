<?php
namespace CopyResources;

use Laminas\Router\Http;

return [
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => sprintf('%s/../language', __DIR__),
                'pattern' => '%s.mo',
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            sprintf('%s/../view', __DIR__),
        ],
    ],
    'service_manager' => [
        'factories' => [
            'CopyResources\CopyResources' => Service\Stdlib\CopyResourcesFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'CopyResources\Controller\Admin\Index' => Service\Controller\Admin\IndexControllerFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'copy-resources' => [
                        'type' => Http\Segment::class,
                        'options' => [
                            'route' => '/copy-resources/:action/:resource-name/:id',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                'resource-name' => '[a-zA-Z0-9_-]+',
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'CopyResources\Controller\Admin',
                                'controller' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
