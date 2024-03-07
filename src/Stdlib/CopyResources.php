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

    public function __construct(ApiManager $api, EntityManager $entityManager, EventManager $eventManager)
    {
        $this->api = $api;
        $this->entityManager = $entityManager;
        $this->eventManager = $eventManager;
        $this->connection = $entityManager->getConnection();
    }

    /**
     * Copy an item resource.
     *
     * @param Representation\ItemRepresentation $item The original item
     * @return Representation\ItemRepresentation The item copy
     */
    public function copyItem(Representation\ItemRepresentation $item)
    {
        $callback = function (&$jsonLd) {
            unset($jsonLd['o:owner']);
            unset($jsonLd['o:primary_media']);
            unset($jsonLd['o:media']);
        };
        $itemCopy = $this->createResourceCopy('items', $item, $callback);

        // Allow modules to copy their data.
        $eventArgs = [
            'resource' => $item,
            'resource_copy' => $itemCopy,
        ];
        $this->triggerEvent('copy_resources.copy_item', $eventArgs);

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
        $callback = function (&$jsonLd) {
            unset($jsonLd['o:owner']);
        };
        $itemSetCopy = $this->createResourceCopy('item_sets', $itemSet, $callback);

        // Copy item/item-set links.
        $sql = 'INSERT INTO item_item_set (item_id, item_set_id)
            SELECT item_id, :item_set_copy_id FROM item_item_set WHERE item_set_id = :item_set_id';
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('item_set_copy_id', $itemSetCopy->id());
        $stmt->bindValue('item_set_id', $itemSet->id());
        $stmt->executeStatement();

        // Allow modules to copy their data.
        $eventArgs = [
            'resource' => $itemSet,
            'resource_copy' => $itemSetCopy,
        ];
        $this->triggerEvent('copy_resources.copy_item_set', $eventArgs);

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

        $callback = function (&$jsonLd) use ($sitePage, $i) {
            // Append the copy iteration to the slug.
            $jsonLd['o:slug'] = sprintf('%s-%s', $sitePage->slug(), $i);
        };
        $sitePageCopy = $this->createResourceCopy('site_pages', $sitePage, $callback);

        // Allow modules to copy their data.
        $eventArgs = [
            'resource' => $sitePage,
            'resource_copy' => $sitePageCopy,
        ];
        $this->triggerEvent('copy_resources.copy_site_page', $eventArgs);

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

        // Copy the site.
        $callback = function (&$jsonLd) use ($site, $i) {
            // Append the copy iteration to the slug.
            $jsonLd['o:slug'] = sprintf('%s-%s', $site->slug(), $i);
            // Set to an empty array to avoid the auto-generated "Browse" link.
            $jsonLd['o:navigation'] = [];
            unset($jsonLd['o:owner']);
            unset($jsonLd['o:page']);
            unset($jsonLd['o:homepage']);
        };
        $siteCopy = $this->createResourceCopy('sites', $site, $callback);

        // Delete the auto-generated "Welcome" page.
        $this->api->delete('site_pages', array_key_first($siteCopy->pages()));

        // Copy site pages. Set a high per_page to avoid paginating.
        $sitePages = $this->api->search('site_pages', ['site_id' => $site->id(), 'per_page' => 1000])->getContent();
        $sitePageMap = [];
        foreach ($sitePages as $sitePage) {
            $callback = function (&$jsonLd) use ($siteCopy, $coreBlockLayouts) {
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
            };
            $sitePageCopy = $this->createResourceCopy('site_pages', $sitePage, $callback);
            $sitePageMap[$sitePage->id()] = $sitePageCopy->id();
        }

        // Add homepage to the site. Note that we must add the homepage after
        // the page is created above.
        $siteHomepage = $site->homepage();
        if ($siteHomepage) {
            $sql = 'UPDATE site SET homepage_id = :homepage_id WHERE id = :site_copy_id';
            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue('homepage_id', $sitePageMap[$siteHomepage->id()]);
            $stmt->bindValue('site_copy_id', $siteCopy->id());
            $stmt->executeStatement();
        }

        // Add navigation to the site. Note that we must add the navigation
        // after the pages are created above.
        $siteNavigation = $site->navigation();
        if ($siteNavigation) {
            $callback = function (&$link) use ($coreNavLinkTypes, $sitePageMap) {
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
            };
            $this->modifySiteNavigation($siteNavigation, $callback);
            $this->updateSiteNavigation($siteCopy->id(), $siteNavigation);
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

        // Allow modules to copy their data.
        $eventArgs = [
            'resource' => $site,
            'resource_copy' => $siteCopy,
            'site_page_map' => $sitePageMap,
        ];
        $this->triggerEvent('copy_resources.copy_site', $eventArgs);

        return $siteCopy;
    }

    /**
     * Trigger an event.
     *
     * @param string $eventName
     * @param array $eventArgs
     */
    public function triggerEvent(string $eventName, array $eventArgs)
    {
        $eventArgs['copy_resources'] = $this; // Always pass $this
        $event = new Event($eventName, null, $eventArgs);
        $this->eventManager->triggerEvent($event);
    }

    /**
     * Create a copy of a resource.
     *
     * This will pass the original resource's JSON-LD to your callback. There,
     * you may modify the JSON-LD if needed for the copy. Make sure to pass the
     * JSON-LD by reference (using &) so modifications are preserved.
     *
     * @param string $resourceName
     * @param Representation\RepresentationInterface $resource
     * @param callable $callback
     * @return Representation\RepresentationInterface
     */
    public function createResourceCopy(string $resourceName, Representation\RepresentationInterface $resource, callable $callback)
    {
        $jsonLd = json_decode(json_encode($resource), true);
        $callback($jsonLd);
        return $this->api->create($resourceName, $jsonLd)->getContent();
    }

    /**
     * Convenience function used by modules to revert copied site block layout
     * names to their original name.
     *
     * @param int $siteCopyId
     * @param string $originalLayoutName
     */
    public function revertSiteBlockLayouts(int $siteCopyId, string $originalLayoutName)
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
        $stmt->bindValue('site_copy_id', $siteCopyId);
        $stmt->executeStatement();
    }

    /**
     * Convenience function used by modules to revert copied site navigation
     * link types to their original name.
     *
     * @param int $siteCopyId
     * @param string $originalLinkType
     */
    public function revertSiteNavigationLinkTypes(int $siteCopyId, string $originalLinkType)
    {
        // Recursive function to prepare the navigation array.
        $siteNavigation = $this->getSiteNavigation($siteCopyId);
        if ($siteNavigation) {
            $callback = function (&$link) use ($originalLinkType) {
                if (sprintf('%s__copy', $originalLinkType) === $link['type']) {
                    // Revert to the original name.
                    $link['type'] = $originalLinkType;
                }
            };
            $this->modifySiteNavigation($siteNavigation, $callback);
            $this->updateSiteNavigation($siteCopyId, $siteNavigation);
        }
    }

    /**
     * Convenience function to get the navigation array of a site.
     *
     * @param int $siteId
     * @return array
     */
    public function getSiteNavigation(int $siteId)
    {
        $sql = 'SELECT navigation FROM site WHERE id = :site_copy_id';
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('site_copy_id', $siteId);
        return json_decode($stmt->executeQuery()->fetchOne(), true);
    }

    /**
     * Recursive function to modify the site's navigation array.
     *
     * This will pass the original resource's navigation links to your callback.
     * There, you may modify the link array if needed. Make sure to pass the
     * link array by reference (using &) so modifications are preserved.
     *
     * @param array &$links
     * @param callable $callback
     */
    public function modifySiteNavigation(array &$links, callable $callback)
    {
        foreach ($links as &$link) {
            // The callback function should modify the link array as necessary.
            $callback($link);
            if ($link['links']) {
                // Recursively follow sub-links.
                $link['links'] = $this->modifySiteNavigation($link['links'], $callback);
            }
        }
        return $links;
    }

    /**
     * Convenience function to update the navigation array of a site.
     *
     * @param int $siteId
     */
    public function updateSiteNavigation(int $siteId, array $siteNavigation)
    {
        $sql = 'UPDATE site SET navigation = :navigation WHERE id = :site_id';
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('navigation', json_encode($siteNavigation));
        $stmt->bindValue('site_id', $siteId);
        $stmt->executeStatement();
    }
}
