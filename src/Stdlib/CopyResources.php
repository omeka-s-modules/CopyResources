<?php
namespace CopyResources\Stdlib;

use Doctrine\ORM\EntityManager;
use Omeka\Api\Representation;
use Omeka\Api\Manager as ApiManager;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManager;

class CopyResources
{
    protected $api;

    protected $entityManager;

    protected $eventManager;

    protected $connection;

    protected $originalIdentityMap;

    public function __construct(ApiManager $api, EntityManager $entityManager, EventManager $eventManager)
    {
        $this->api = $api;
        $this->entityManager = $entityManager;
        $this->eventManager = $eventManager;
        $this->connection = $entityManager->getConnection();

        // Set the original identity map so we have a snapshot of the original
        // state of the entity manager.
        $this->originalIdentityMap = $entityManager->getUnitOfWork()->getIdentityMap();
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

        // Copy item/item-set links.
        $sql = 'INSERT INTO item_item_set (item_id, item_set_id)
            SELECT item_id, :item_set_copy_id FROM item_item_set WHERE item_set_id = :item_set_id';
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('item_set_copy_id', $itemSetCopy->id());
        $stmt->bindValue('item_set_id', $itemSet->id());
        $stmt->executeStatement();

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
        // We do this for two reasons: 1) Because we don't need to update a
        // local copy of the block layouts and link types when they're updated
        // in config; and 2) Because block layouts and link types added by
        // modules are likely invalid outside the context of the original site.
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
        $jsonLd['o:navigation'] = []; // Set to an empty array to avoid the auto-generated "Browse" link.
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
                    $jsonLd['o:block'][$index]['o:layout'] = sprintf('%s__copy', $blockLayout);
                }
            }
            $sitePageCopy = $this->api->create('site_pages', $jsonLd)->getContent();
            $sitePageMap[$sitePage->id()] = $sitePageCopy->id();
        }

        // Add homepage to the site. Note that we must add the homepage after
        // the page is created above.
        if ($siteHomepage) {
            $sql = 'UPDATE site SET homepage_id = :homepage_id WHERE id = :site_copy_id';
            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue('homepage_id', $sitePageMap[$siteHomepage['o:id']]);
            $stmt->bindValue('site_copy_id', $siteCopy->id());
            $stmt->executeStatement();
        }

        // Add navigation to the site. Note that we must add the navigation
        // after the pages are created above.

        // Recursive function to prepare the navigation array.
        $getLinks = function (&$links) use (&$getLinks, $coreNavLinkTypes, $sitePageMap) {
            $linksCopy = [];
            foreach ($links as &$link) {
                // We must convert links introduced by modules to stubs because
                // they likely contain data that are valid only within the
                // context of the original site. We use stubs instead of removing
                // the links becuase removing them may adversely affect the flow
                // of the copied navigation.
                if (!in_array($link['type'], $coreNavLinkTypes)) {
                    $link['type'] = sprintf('%s__copy', $link['type']);
                }
                if ('page' === $link['type']) {
                    // Get the page ID from the site page map.
                    $link['data']['id'] = $sitePageMap[$link['data']['id']];
                }
                if ($link['links']) {
                    // Recursively follow sub-links.
                    $link['links'] = $getLinks($link['links']);
                }
            }
            return $links;
        };
        if ($siteNavigation) {
            $getLinks($siteNavigation);
            $sql = 'UPDATE site SET navigation = :navigation WHERE id = :site_copy_id';
            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue('navigation', json_encode($siteNavigation));
            $stmt->bindValue('site_copy_id', $siteCopy->id());
            $stmt->executeStatement();
        }

        // Copy site settings.
        $sql = 'INSERT INTO site_setting (id, site_id, value)
            SELECT id, :site_copy_id, value FROM site_setting WHERE site_id = :site_id';
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('site_copy_id', $siteCopy->id());
        $stmt->bindValue('site_id', $site->id());
        $stmt->executeStatement();

        // Copy site/item links.
        $sql = 'INSERT INTO item_site (item_id, site_id)
            SELECT item_id, :site_copy_id FROM item_site WHERE site_id = :site_id';
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('site_copy_id', $siteCopy->id());
        $stmt->bindValue('site_id', $site->id());
        $stmt->executeStatement();

        // Refresh the site copy to update homepage, navigation, etc. Instead of
        // clearing the entity manager to force a refresh, detach entities that
        // were not part of the original state of the entity manager to avoid
        // "A new entity was found" Doctrine errors.
        $this->entityManager->flush();
        $identityMap = $this->entityManager->getUnitOfWork()->getIdentityMap();
        foreach ($identityMap as $entityClass => $entities) {
            foreach ($entities as $idHash => $entity) {
                if (!isset($this->originalIdentityMap[$entityClass][$idHash])) {
                    $this->entityManager->detach($entity);
                }
            }
        }
        $siteCopy = $this->api->read('sites', $siteCopy->id())->getContent();

        // Allow modules to copy their data.
        $eventArgs = [
            'copy_resources' => $this,
            'site' => $site,
            'site_copy' => $siteCopy,
            'site_page_map' => $sitePageMap,
        ];
        $event = new Event('copy_resources.copy_site', null, $eventArgs);
        $this->eventManager->triggerEvent($event);

        return $siteCopy;
    }

    /**
     * Convenience function used by modules to revert copied site block layout
     * names to their original name.
     *
     * @param Representation\SiteRepresentation $siteCopy
     * @param string $originalLayoutName
     */
    public function revertSiteBlockLayouts(Representation\SiteRepresentation $siteCopy, string $originalLayoutName)
    {
        $sql = 'UPDATE site_page_block b
            INNER JOIN site_page p ON p.id = b.page_id
            INNER JOIN site s ON s.id = p.site_id
            SET b.layout = :layout
            WHERE b.layout = :layout_copy
            AND s.id = :site_copy_id';
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('layout', $originalLayoutName);
        $stmt->bindValue('layout_copy', sprintf('%s__copy', $originalLayoutName));
        $stmt->bindValue('site_copy_id', $siteCopy->id());
        $stmt->executeStatement();
    }

    /**
     * Convenience function used by modules to revert copied site navigation
     * link types to their original name.
     *
     * @param Representation\SiteRepresentation $siteCopy
     * @param string $originalLinkType
     */
    public function revertSiteNavigationLinkTypes(Representation\SiteRepresentation $siteCopy, string $originalLinkType)
    {
        // Recursive function to prepare the navigation array.
        $getLinks = function (&$links) use (&$getLinks, $originalLinkType) {
            foreach ($links as &$link) {
                if (sprintf('%s__copy', $originalLinkType) === $link['type']) {
                    // Revert to the original name.
                    $link['type'] = $originalLinkType;
                }
                if ($link['links']) {
                    // Recursively follow sub-links.
                    $link['links'] = $getLinks($link['links']);
                }
            }
            return $links;
        };
        $siteNavigation = $siteCopy->navigation();
        if ($siteNavigation) {
            $getLinks($siteNavigation);
            $sql = 'UPDATE site SET navigation = :navigation WHERE id = :site_copy_id';
            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue('navigation', json_encode($siteNavigation));
            $stmt->bindValue('site_copy_id', $siteCopy->id());
            $stmt->executeStatement();
        }
    }
}
