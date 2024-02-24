<?php
namespace CopyResources\Stdlib;

use Omeka\Api\Representation;
use Laminas\ServiceManager\ServiceLocatorInterface;

class CopyResources
{
    protected $services;

    protected $api;

    protected $entityManager;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
        $this->api = $this->services->get('Omeka\ApiManager');
        $this->entityManager = $this->services->get('Omeka\EntityManager');
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

    public function copySitePage(Representation\SitePageRepresentation $sitePage)
    {
        // The slug must be unique. Get the copy iteration.
        $i = 0;
        do {
            $hasSitePage = $this->entityManager
                ->getRepository('Omeka\Entity\SitePage')
                ->findOneBy(['slug' => sprintf('%s-%s', $sitePage->slug(), ++$i)]);
        } while ($hasSitePage);

        $jsonLd = json_decode(json_encode($sitePage), true);
        // Append the copy iteration to the slug.
        $jsonLd['o:slug'] = sprintf('%s-%s', $sitePage->slug(), $i);
        $sitePageCopy = $this->api->create('site_pages', $jsonLd)->getContent();
        return $sitePageCopy;
    }
}
