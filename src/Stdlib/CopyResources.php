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
     * @param array $options
     * @return Representation\ItemRepresentation The item copy
     */
    public function copyItem(Representation\ItemRepresentation $item, array $options = [])
    {
        $preCallback = function (&$jsonLd) use ($options) {
            unset($jsonLd['o:owner']);
            unset($jsonLd['o:primary_media']);
            unset($jsonLd['o:media']);
            $jsonLd['o:is_public'] = $this->getIsPublic($jsonLd, $options);
        };

        $itemCopy = $this->createResourceCopy('items', $item, $preCallback);
        return $itemCopy;
    }

    /**
     * Copy an item set resource.
     *
     * @param Representation\ItemSetRepresentation $itemSet The original item set
     * @param array $options
     * @return Representation\ItemSetRepresentation The item set copy
     */
    public function copyItemSet(Representation\ItemSetRepresentation $itemSet, array $options = [])
    {
        $preCallback = function (&$jsonLd) use ($options) {
            unset($jsonLd['o:owner']);
            $jsonLd['o:is_public'] = $this->getIsPublic($jsonLd, $options);
        };
        $postCallback = function ($itemSetCopy) use ($itemSet) {
            // Copy item/item-set links.
            $sql = 'INSERT INTO item_item_set (item_id, item_set_id)
                SELECT item_id, :item_set_copy_id
                FROM item_item_set
                WHERE item_set_id = :item_set_id';
            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue('item_set_copy_id', $itemSetCopy->id());
            $stmt->bindValue('item_set_id', $itemSet->id());
            $stmt->executeStatement();
        };

        $itemSetCopy = $this->createResourceCopy('item_sets', $itemSet, $preCallback, $postCallback);
        return $itemSetCopy;
    }

    /**
     * Copy a site page resource.
     *
     * @param Representation\SitePageRepresentation $sitePage The original site page
     * @param array $options
     * @return Representation\SitePageRepresentation The site page copy
     */
    public function copySitePage(Representation\SitePageRepresentation $sitePage, array $options = [])
    {
        // The slug must be unique. Get the copy iteration.
        $i = 0;
        do {
            $hasSitePage = $this->entityManager
                ->getRepository('Omeka\Entity\SitePage')
                ->findOneBy(['slug' => sprintf('%s-%s', $sitePage->slug(), ++$i)]);
        } while ($hasSitePage);

        $preCallback = function (&$jsonLd) use ($options, $sitePage, $i) {
            // Append the copy iteration to the slug.
            $jsonLd['o:slug'] = sprintf('%s-%s', $sitePage->slug(), $i);
            $jsonLd['o:is_public'] = $this->getIsPublic($jsonLd, $options);
        };

        $sitePageCopy = $this->createResourceCopy('site_pages', $sitePage, $preCallback);
        return $sitePageCopy;
    }

    /**
     * Copy a site resource.
     *
     * @param Representation\SiteRepresentation $site The original site
     * @param array $options
     * @return Representation\SiteRepresentation The site copy
     */
    public function copySite(Representation\SiteRepresentation $site, array $options = [])
    {
        // The slug must be unique. Get the copy iteration.
        $i = 0;
        do {
            $hasSite = $this->entityManager
                ->getRepository('Omeka\Entity\Site')
                ->findOneBy(['slug' => sprintf('%s-%s', $site->slug(), ++$i)]);
        } while ($hasSite);

        $preCallback = function (&$jsonLd) use ($options, $site, $i) {
            // Append the copy iteration to the slug.
            $jsonLd['o:slug'] = sprintf('%s-%s', $site->slug(), $i);
            // Set to an empty array to avoid the auto-generated "Browse" link.
            $jsonLd['o:navigation'] = [];
            unset($jsonLd['o:owner']);
            unset($jsonLd['o:page']);
            unset($jsonLd['o:homepage']);
            $jsonLd['o:is_public'] = $this->getIsPublic($jsonLd, $options);
        };
        $postCallback = function ($siteCopy) use ($site) {
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

            // Delete the auto-generated "Welcome" page.
            $this->api->delete('site_pages', array_key_first($siteCopy->pages()));

            // Copy site pages. Set a high per_page to avoid paginating.
            $sitePages = $this->api->search('site_pages', ['site_id' => $site->id(), 'per_page' => 1000])->getContent();
            $sitePageMap = [];
            foreach ($sitePages as $sitePage) {
                $preCallback = function (&$jsonLd) use ($siteCopy, $coreBlockLayouts) {
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
                $sitePageCopy = $this->createResourceCopy('site_pages', $sitePage, $preCallback);
                $sitePageMap[$sitePage->id()] = $sitePageCopy->id();
            }

            // Set the correct page IDs to "listOfPages" blocks. Normally we'd
            // modify block data while copying site pages above, but "listOfPages"
            // blocks reference pages that aren't created until now.
            $sqlSelect = 'SELECT spb.id, spb.data
                FROM site_page_block spb
                INNER JOIN site_page sp ON sp.id = spb.page_id
                WHERE sp.site_id = :site_copy_id
                AND spb.layout = "listOfPages"';
            $stmtSelect = $this->connection->prepare($sqlSelect);
            $stmtSelect->bindValue('site_copy_id', $siteCopy->id());
            $pageBlocks = $stmtSelect->executeQuery()->fetchAllAssociative();
            $sqlUpdate = 'UPDATE site_page_block spb SET spb.data = :data WHERE spb.id = :id';
            $stmtUpdate = $this->connection->prepare($sqlUpdate);
            foreach ($pageBlocks as $pageBlock) {
                // Modify the block data.
                $data = json_decode($pageBlock['data'], true);
                $pageList = json_decode($data['pagelist'], true);
                $pageListCopy = $this->recursePageList($pageList, $sitePageMap);
                $data['pagelist'] = json_encode($pageListCopy);
                $stmtUpdate->bindValue('data', json_encode($data));
                $stmtUpdate->bindValue('id', $pageBlock['id']);
                $stmtUpdate->executeStatement();
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
            $this->modifySiteNavigation([$site->id(), $siteCopy->id()], null, $callback);

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
        };

        $siteCopy = $this->createResourceCopy('sites', $site, $preCallback, $postCallback);
        return $siteCopy;
    }

    /**
     * Get the "o:is_public" for the copied resource.
     *
     * @param array $jsonLd
     * @param array $options
     * @return bool
     */
    public function getIsPublic(array $jsonLd, array $options)
    {
        $visibility = $options['visibility'] ?? null;
        switch ($visibility) {
            case 'public':
                return true;
            case 'private':
                return false;
            default:
                return (bool) $jsonLd['o:is_public'];
        }
    }

    /**
     * Create a copy of a resource.
     *
     * This will pass the original resource's JSON-LD array to your $preCallback.
     * There, you may modify the array if needed for the copy. Make sure to pass
     * the array by reference (using &) so modifications are preserved.
     *
     * This will pass the resource copy to your $postCallback. There, you may
     * perform any actions needed to complete the copy.
     *
     * @param string $resourceName
     * @param Representation\RepresentationInterface $resource
     * @param callable $preCallback
     * @param callable $postCallback
     * @return Representation\RepresentationInterface
     */
    public function createResourceCopy(string $resourceName, Representation\RepresentationInterface $resource, callable $preCallback = null, callable $postCallback = null)
    {
        $jsonLd = json_decode(json_encode($resource), true);

        // Trigger the resource.pre event. Intended to allow modules to modify
        // the JSON-LD before the resource has been copied.
        $eventArgs = $this->eventManager->prepareArgs([
            'copy_resources' => $this,
            'resource' => $resource,
            'json_ld' => $jsonLd,
        ]);
        $eventName = sprintf('copy_resources.%s.pre', $resourceName);
        $event = new Event($eventName, null, $eventArgs);
        $this->eventManager->triggerEvent($event);
        $jsonLd = $eventArgs['json_ld'];

        // Call the internal pre-copy callback.
        if ($preCallback) {
            $preCallback($jsonLd);
        }

        // Copy the resource.
        $resourceCopy = $this->api->create($resourceName, $jsonLd)->getContent();

        // Call the internal post-copy callback.
        if ($postCallback) {
            $postCallback($resourceCopy);
        }

        // Trigger the resource.post event. Intended to allow modules to copy
        // their data after the resource has been copied.
        $eventArgs = [
            'copy_resources' => $this,
            'resource' => $resource,
            'resource_copy' => $resourceCopy,
        ];
        $eventName = sprintf('copy_resources.%s.post', $resourceName);
        $event = new Event($eventName, null, $eventArgs);
        $this->eventManager->triggerEvent($event);

        return $resourceCopy;
    }

    /**
     * Convenience function used by modules to revert copied site block layout
     * names to their original name.
     *
     * @param int $siteId
     * @param string $originalLayout
     */
    public function revertSiteBlockLayouts(int $siteId, string $originalLayout)
    {
        $sql = 'UPDATE site_page_block b
            INNER JOIN site_page p ON p.id = b.page_id
            INNER JOIN site s ON s.id = p.site_id
            SET b.layout = :layout
            WHERE b.layout = :layout_copy
            AND s.id = :site_copy_id';
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('layout', $originalLayout);
        $stmt->bindValue('layout_copy', sprintf('%s__copy', $originalLayout));
        $stmt->bindValue('site_copy_id', $siteId);
        $stmt->executeStatement();
    }

    /**
     * Convenience function used by modules to revert copied site navigation
     * link types to their original name.
     *
     * @param int $siteId
     * @param string $originalLinkType
     */
    public function revertSiteNavigationLinkTypes(int $siteId, string $originalLinkType)
    {
        $callback = function (&$link) use ($originalLinkType) {
            if (sprintf('%s__copy', $originalLinkType) === $link['type']) {
                // Revert to the original name.
                $link['type'] = $originalLinkType;
            }
        };
        $this->modifySiteNavigation($siteId, null, $callback);
    }

    /**
     * Modify site navigation.
     *
     * This will pass the original resource's navigation link array to your
     * callback. There, you may modify the array if needed. Make sure to pass
     * the array by reference (using &) so modifications are preserved.
     *
     * @param int|array $siteId The site ID or an array containing the "from ID" and "to ID"
     * @param ?string $linkType
     * @param callable $callback
     */
    public function modifySiteNavigation($siteId, ?string $linkType, callable $callback)
    {
        if (is_array($siteId)) {
            $fromSiteId = $siteId[0];
            $toSiteId = $siteId[1];
        } else {
            $fromSiteId = (int) $siteId;
            $toSiteId = (int) $siteId;
        }

        // Get site navigation.
        $sql = 'SELECT navigation FROM site WHERE id = :site_copy_id';
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('site_copy_id', $fromSiteId);
        $siteNavigation = json_decode($stmt->executeQuery()->fetchOne(), true);

        if ($siteNavigation) {
            // Recursively modify the navigation array.
            $this->recurseSiteNavigation($siteNavigation, $linkType, $callback);

            // Update the navigation.
            $sql = 'UPDATE site SET navigation = :navigation WHERE id = :site_copy_id';
            $stmt = $this->connection->prepare($sql);
            $stmt->bindValue('navigation', json_encode($siteNavigation));
            $stmt->bindValue('site_copy_id', $toSiteId);
            $stmt->executeStatement();
        }
    }

    /**
     * Recursive function to modify the site's navigation array.
     *
     * The callback function should modify the link array as necessary.
     *
     * @param array &$links
     * @param ?string $linkType
     * @param callable $callback
     */
    public function recurseSiteNavigation(array &$links, ?string $linkType, callable $callback)
    {
        foreach ($links as &$link) {
            if ($linkType) {
                // Filter by link type.
                if ($linkType === $link['type']) {
                    $callback($link);
                } else {
                    // This is not the link type. Do nothing.
                }
            } else {
                // Filter all links.
                $callback($link);
            }
            if ($link['links']) {
                // Recursively follow sub-links.
                $link['links'] = $this->recurseSiteNavigation($link['links'], $linkType, $callback);
            }
        }
        return $links;
    }

    /**
     * Recursive function to modify the "listOfPages" block "pagelist" array.
     *
     * @param array &$pages
     * @param array $sitePageMap
     * @return array
     */
    public function recursePageList(array &$pages, array $sitePageMap)
    {
        foreach ($pages as $index => &$page) {
            $page['data']['data']['id'] = $sitePageMap[$page['data']['data']['id']];
            if (isset($page['children']) && $page['children']) {
                $page['children'] = $this->recursePageList($page['children'], $sitePageMap);
            }
        }
        return $pages;
    }

    /**
     * Modify site page blocks data.
     *
     * This will pass the original block's data array to your callback. There,
     * you may modify the array if needed for the copy. Make sure to pass the
     * array by reference (using &) so modifications are preserved.
     *
     * @param int $siteId
     * @param string $originalLayout
     * @param callable $callback
     */
    public function modifySiteBlockData(int $siteId, string $originalLayout, callable $callback)
    {
        // Get all page blocks of the passed site and layout.
        $sql = 'SELECT b.id, b.data
            FROM site_page_block b
            INNER JOIN site_page p ON p.id = b.page_id
            INNER JOIN site s ON s.id = p.site_id
            WHERE s.id = :site_copy_id
            AND b.layout = :layout';
        $stmt = $this->connection->prepare($sql);
        $stmt->bindValue('site_copy_id', $siteId);
        $stmt->bindValue('layout', $originalLayout);
        $siteBlocks = $stmt->executeQuery()->fetchAllAssociative();

        // Modify the page block data and update the page block.
        $sql = 'UPDATE site_page_block SET data = :data WHERE id = :id';
        $stmt = $this->connection->prepare($sql);
        foreach ($siteBlocks as $siteBlock) {
            $data = json_decode($siteBlock['data'], true);
            $callback($data);
            $stmt->bindValue('data', json_encode($data));
            $stmt->bindValue('id', $siteBlock['id']);
            $stmt->executeStatement();
        }
    }
}
