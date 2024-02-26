<?php
namespace CopyResources\Stdlib;

use Doctrine\ORM\EntityManager;
use Omeka\Api\Representation;
use Omeka\Api\Manager as ApiManager;
use Laminas\ServiceManager\ServiceLocatorInterface;

class CopyResources
{
    protected $entityManager;

    protected $api;

    public function __construct(EntityManager $entityManager, ApiManager $api)
    {
        $this->entityManager = $entityManager;
        $this->api = $api;
    }

    /**
     * Copy an item resource.
     *
     * @param Representation\ItemRepresentation $item The original item
     * @return Representation\ItemRepresentation The item copy
     */
    public function copyItem(Representation\ItemRepresentation $item)
    {
        $jsonLd = json_decode(json_encode($item), true);
        unset($jsonLd['o:owner']);
        unset($jsonLd['o:primary_media']);
        unset($jsonLd['o:media']);
        $itemCopy = $this->api->create('items', $jsonLd)->getContent();
        return $itemCopy;
    }

    /**
     * Copy an item set resource.
     *
     * @param Representation\ItemSetRepresentation $itemSet The original item set
     * @return Representation\ItemSetRepresentation The item set copy
     */
    public function copyItemSet(Representation\ItemSetRepresentation $itemSet)
    {
        $jsonLd = json_decode(json_encode($itemSet), true);
        unset($jsonLd['o:owner']);
        $itemSetCopy = $this->api->create('item_sets', $jsonLd)->getContent();
        return $itemSetCopy;
    }

    /**
     * Copy a site page resource.
     *
     * @param Representation\SitePageRepresentation $sitePage The original site page
     * @return Representation\SitePageRepresentation The site page copy
     */
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

    /**
     * Copy a site resource.
     *
     * @param Representation\SiteRepresentation $site The original site
     * @return Representation\SiteRepresentation The site copy
     */
    public function copySite(Representation\SiteRepresentation $site)
    {
        // Get service names from core config (not merged with modules config).
        // We do this for two reasons: 1) We don't need to update a local copy
        // of the block layouts and link types when they're updated in config;
        // 2) Block layouts and link types added by modules are likely invalid
        // outside the context of the original site.
        $coreConfig = include sprintf('%s/application/config/module.config.php', OMEKA_PATH);
        $coreBlockLayouts = array_merge(
            array_keys($coreConfig['block_layouts']['invokables']),
            array_keys($coreConfig['block_layouts']['factories'])
        );
        $coreNavLinkTypes = array_merge(
            array_keys($coreConfig['navigation_links']['invokables']),
            array_keys($coreConfig['navigation_links']['factories'])
        );

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
            // We must convert block layouts introduced by modules to stubs
            // because they likely contain data that are valid only within the
            // context of the original site. We use stubs instead of removing
            // the blocks becuase removing them may adversely affect the flow of
            // the copied page.
            foreach ($jsonLd['o:block'] as $index => $block) {
                $blockLayout = $block['o:layout'];
                if (!in_array($blockLayout, $coreBlockLayouts)) {
                    $jsonLd['o:block'][$index]['o:layout'] = sprintf('%s_copy', $blockLayout);
                }
            }
            $sitePageCopy = $this->api->create('site_pages', $jsonLd)->getContent();
            $sitePageMap[$sitePage->id()] = $sitePageCopy->id();
        }

        // Add homepage to the site. Note that we must add the homepage after
        // the page is created above.
        if ($siteHomepage) {
            $dql = 'UPDATE Omeka\Entity\Site s SET s.homepage = :page_id WHERE s.id = :site_id';
            $query = $this->entityManager->createQuery($dql);
            $query->setParameters([
                'page_id' => $sitePageMap[$siteHomepage['o:id']],
                'site_id' => $siteCopy->id(),
            ]);
            $query->execute();
        }

        // Add navigation to the site. Note that we must add the navigation
        // after the pages are created above.

        // Recursive function to prepare the navigation array.
        $getLinks = function($links) use (&$getLinks, $coreNavLinkTypes, $sitePageMap) {
            foreach ($links as $link) {
                $linkCopy = $link;
                // We must convert links introduced by modules to stubs because
                // they likely contain data that are valid only within the
                // context of the original site. We use stubs instead of removing
                // the links becuase removing them may adversely affect the flow
                // of the copied navigation.
                if (!in_array($link['type'], $coreNavLinkTypes)) {
                    $linkCopy['type'] = sprintf('%s_copy', $link['type']);
                }
                if ('page' === $link['type']) {
                    // Get the page ID from the site page map.
                    $linkCopy['data']['id'] = $sitePageMap[$linkCopy['data']['id']];
                }
                if ($link['links']) {
                    // Recursively follow sub-links.
                    $linkCopy['links'] = $getLinks($link['links']);
                }
                $linksCopy[] = $linkCopy;
            }
            return $linksCopy;
        };
        if ($siteNavigation) {
            $siteNavigationCopy = $getLinks($siteNavigation);
            $dql = 'UPDATE Omeka\Entity\Site s SET s.navigation = :navigation WHERE s.id = :site_id';
            $query = $this->entityManager->createQuery($dql);
            $query->setParameters([
                'navigation' => json_encode($siteNavigationCopy),
                'site_id' => $siteCopy->id(),
            ]);
            $query->execute();
        }

        // Copy site settings.
        $sql = sprintf('INSERT INTO site_setting (id, site_id, value)
        SELECT id, %s, value FROM site_setting WHERE site_id = %s', $siteCopy->id(), $site->id());
        $this->entityManager->getConnection()->executeUpdate($sql);

        // Copy site-item links.
        $sql = sprintf('INSERT INTO item_site (item_id, site_id)
        SELECT item_id, %s FROM item_site WHERE site_id = %s', $siteCopy->id(), $site->id());
        $this->entityManager->getConnection()->executeUpdate($sql);

        return $siteCopy;
    }
}
