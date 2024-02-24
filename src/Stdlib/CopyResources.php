<?php
namespace CopyResources\Stdlib;

use Omeka\Api\Representation;
use Laminas\ServiceManager\ServiceLocatorInterface;

class CopyResources
{
    protected $services;

    protected $api;

    protected $em;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
        $this->api = $this->services->get('Omeka\ApiManager');
        $this->entityManager = $this->services->get('Omeka\EntityManager');
    }

    public function copyItem(Representation\ItemRepresentation $item)
    {
        $jsonLd = json_decode(json_encode($item), true);
        unset($jsonLd['o:owner']);
        unset($jsonLd['o:primary_media']);
        unset($jsonLd['o:media']);
        $itemCopy = $this->api->create('items', $jsonLd)->getContent();
        return $itemCopy;
    }

    public function copyItemSet(Representation\ItemSetRepresentation $itemSet)
    {
        $jsonLd = json_decode(json_encode($itemSet), true);
        unset($jsonLd['o:owner']);
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

    public function copySite(Representation\SiteRepresentation $site)
    {
        // The slug must be unique. Get the copy iteration.
        $i = 0;
        do {
            $hasSite = $this->entityManager
                ->getRepository('Omeka\Entity\Site')
                ->findOneBy(['slug' => sprintf('%s-%s', $site->slug(), ++$i)]);
        } while ($hasSite);

        $jsonLd = json_decode(json_encode($site), true);
        // Append the copy iteration to the slug.
        $jsonLd['o:slug'] = sprintf('%s-%s', $site->slug(), $i);
        $siteHomepage = $jsonLd['o:homepage'];
        $siteNavigation = $jsonLd['o:navigation'];
        unset($jsonLd['o:owner']);
        unset($jsonLd['o:page']);
        unset($jsonLd['o:homepage']);
        unset($jsonLd['o:navigation']);
        $siteCopy = $this->api->create('sites', $jsonLd)->getContent();

        // Delete the auto-generated "Welcome" page.
        $this->api->delete('site_pages', array_key_first($siteCopy->pages()));

        // Copy site pages. Set a high per_page to avoid paginating.
        $sitePages = $this->api->search('site_pages', ['site_id' => $site->id(), 'per_page' => 1000])->getContent();
        $sitePageMap = [];
        foreach ($sitePages as $sitePage) {
            $jsonLd = json_decode(json_encode($sitePage), true);
            $jsonLd['o:site']['o:id'] = $siteCopy->id();
            $sitePageCopy = $this->api->create('site_pages', $jsonLd)->getContent();
            $sitePageMap[$sitePage->id()] = $sitePageCopy->id();
        }

        // Add homepage to the site.
        if ($siteHomepage) {
            $dql = 'UPDATE Omeka\Entity\Site s SET s.homepage = :page_id WHERE s.id = :site_id';
            $query = $this->entityManager->createQuery($dql);
            $query->setParameters([
                'page_id' => $sitePageMap[$siteHomepage['o:id']],
                'site_id' => $siteCopy->id(),
            ]);
            $query->execute();
        }

        // @todo: Add navigation to the site.

        // @todo: Copy site settings.

        // @todo: Copy site-item links.

        return $siteCopy;
    }
}
