<?php
namespace CopyResources\Stdlib;

use Omeka\Api\Representation;
use Laminas\ServiceManager\ServiceLocatorInterface;

class CopyResources
{
    protected $services;

    protected $api;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
        $this->api = $this->services->get('Omeka\ApiManager');
    }

    public function copyItem(Representation\ItemRepresentation $item)
    {
        $jsonLd = json_decode(json_encode($item), true);
        $jsonLd['o:owner'] = null;
        $jsonLd['o:primary_media'] = null;
        $jsonLd['o:media'] = [];
        $itemCopy = $this->api->create('items', $jsonLd)->getContent();
        return $itemCopy;
    }

    public function copyItemSet(Representation\ItemSetRepresentation $itemSet)
    {
        $jsonLd = json_decode(json_encode($itemSet), true);
        $jsonLd['o:owner'] = null;
        $itemSetCopy = $this->api->create('item_sets', $jsonLd)->getContent();
        return $itemSetCopy;
    }
}
