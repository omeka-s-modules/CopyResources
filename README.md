# Copy Resources

An [Omeka S](https://omeka.org/s/) module for copying resources, including items,
item sets, sites, and site pages.

## For Module Developers

### Events

This module provides several events when other modules may do something after copies
have been made.

- `copy_resources.*.pre`: Do something before copying a resource. Replace `*` with the resource name. Params:
    -`resource`: The original resource
    -`json_ld`: The JSON-LD used to copy the resource. Modules may modify the JSON-LD and send it back using `$event->setParam('json_ld', $jsonLd)`
- `copy_resources.copy_item`: Do something after a user copies an item. Params:
    - `resource`: The original item
    - `resource_copy`: The item copy
    - `copy_resources`: The CopyResources service object
- `copy_resources.copy_item_set`: Do something after a user copies an item set. Params:
    - `resource`: The original item set
    - `resource_copy`: The item set copy
    - `copy_resources`: The CopyResources service object
- `copy_resources.copy_site_page`: Do something after a user copies a site page. Params:
    - `resource`: The original site page
    - `resource_copy`: The site page copy
    - `copy_resources`: The CopyResources service object
- `copy_resources.copy_site`: Do something after a user copies a site. Params:
    - `resource`: The original site page
    - `resource_copy`: The site page copy
    - `copy_resources`: The CopyResources service object
    - `site_page_map`: The array that maps the old page IDs to the new page IDs

### Copying Sites

Copying sites is more involved than copying other resources because modules may
add data to sites that must also be copied. These modules will need to listen to
the `copy_resources.copy_site` event and make adjustments so their data is correctly
copied over.

Modules that add block layouts and navigation links to sites via the `block_layouts`
and `navigation_links` configuration will need to **revert** the copied layouts and
links to their original state. Thankfully, the `CopyResources` service object has
convenience methods for this. For example:

```php
$sharedEventManager->attach(
    '*',
    'copy_resources.copy_site',
    function (Event $event) {
        $copyResources = $event->getParam('copy_resources');
        $siteCopy = $event->getParam('resource_copy');

        // Revert block layout and link types.
        $copyResources->revertSiteBlockLayouts($siteCopy->id(), 'my_block_layout');
        $copyResources->revertSiteNavigationLinkTypes($siteCopy->id(), 'my_link_type');
    }
);
```

Modules that add API resources that are assigned to sites will need to copy the
resources and assign them to the new site. Again, the `CopyResources` service object
has convenience methods for this. For example:

```php
$sharedEventManager->attach(
    '*',
    'copy_resources.copy_site',
    function (Event $event) {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $site = $event->getParam('resource');
        $siteCopy = $event->getParam('resource_copy');
        $copyResources = $event->getParam('copy_resources');

        // Create resource copies.
        $myApiResources = $api->search('my_api_resource', ['site_id' => $site->id()])->getContent();
        foreach ($myApiResources as $myApiResource) {
            $callback = function (&$jsonLd) use ($siteCopy){
                unset($jsonLd['o:owner']);
                $jsonLd['o:site']['o:id'] = $siteCopy->id();
            };
            $copyResources->createResourceCopy('my_api_resource', $myApiResource, $callback);
        }
    }
);
```

Modules may need to modify block layout data and navigation link data to update
for the site copy. Again, the `CopyResources` service object has convenience methods
for this. For example:

```php
$sharedEventManager->attach(
    '*',
    'copy_resources.copy_site',
    function (Event $event) {
        $copyResources = $event->getParam('copy_resources');
        $siteCopy = $event->getParam('resource_copy');

        // Modify block data.
        $callback = function (&$data) {
            $data['foo'] = 'bar';
        };
        $copyResources->modifySiteBlockData($siteCopy->id(), 'my_block_layout', $callback);

        // Modify site navigation.
        $callback = function (&$link) use ($visualizationmMap) {
            $data['baz'] = 'bat';
        };
        $copyResources->modifySiteNavigation($siteCopy->id(), 'my_link_type', $callback);

    }
);
```

# Copyright

Copy Resources is Copyright © 2021-present Corporation for Digital Scholarship,
Vienna, Virginia, USA http://digitalscholar.org

The Corporation for Digital Scholarship distributes the Omeka source code under
the GNU General Public License, version 3 (GPLv3). The full text of this license
is given in the license file.

The Omeka name is a registered trademark of the Corporation for Digital Scholarship.

Third-party copyright in this distribution is noted where applicable.

All rights not expressly granted are reserved.
