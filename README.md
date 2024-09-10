# Copy Resources

An [Omeka S](https://omeka.org/s/) module for copying resources, including items, item sets, sites, and site pages.

Copy Resources inserts a "copy" button into the interface of several pages in the admin side, including when viewing the resource, and on the table of sites, each site's table of pages, and the items and item sets tables. 

Items and item sets will get a new unique identifier, but have all their metadata fields duplicated, including data captured by modules, such as geolocation data in Mapping. Duplicate items will be added to the same item sets as the originals; duplicated resources will be added to the same sites as the originals. However, duplicated items will have no media copied with them.

Sites and site pages will have the same name, but their URL slugs will be unique. Pages will have all their page blocks duplicated, including blocks added by modules, and those blocks' settings.

Some content will not copy exactly - be sure you review duplicated resources thoroughly and look for anything missing before making the new resource public. 

## Permissions

Global Administrators, Supervisors, and Editors can use this module to copy items, item sets, sites, and site pages.

Users at Author and Reviewer levels can copy items and item sets.

Site-specific permissions do not affect a user's ability to copy sites. Site copying is reserved for Global Administrators, Supervisors, and Editors.

Users with site-specific permissions of Creator and Manager can copy pages.

Users can copy resources that they do not own. When a resource is copied, the user who made the duplicate will become its owner; the resource will not retain the owner of the original. 

## Requirements

Copy Resources requires Omeka S 4.1.0 or later.

If you are using the following modules, we recommend you upgrade to versions that have been updated since Copy Resources' release, specifically:

- Collecting version 1.12 or later
- Data Visualization version 1.3
- Item Carousel Block version 1.3
- Faceted Browse version 1.5.1
- Mapping version 2.0
- Scripto version 1.5
- Sharing version 1.5.

Other modules with data potentially copyable by Copy Resources, such as Metadata Browse, do not have dependencies. 

See the [Omeka S user manual](https://omeka.org/s/docs/user-manual/modules/copyresources/) for user documentation.

## For developers

### Events

This module provides events where other modules may do something after copies have
been made.

- `copy_resources.*.pre`: Do something before copying a resource. Replace `*` with the resource name. Intended to allow modules to modify the JSON-LD before the resource has been copied. Params:
    - `copy_resources`: The CopyResources service object
    - `resource`: The original resource
    - `json_ld`: The JSON-LD used to copy the resource. Modules may modify the JSON-LD and send it back using `$event->setParam('json_ld', $jsonLd)`
- `copy_resources.*.post`: Do something after copying a resource. Replace `*` with the resource name. Intended to allow modules to copy their data after the resource has been copied. Params:
    - `copy_resources`: The CopyResources service object
    - `resource`: The original resource
    - `resource_copy`: The item set copy

### Copying Sites

Copying sites is more involved than copying other resources because modules may
add data to sites that must also be copied. These modules will need to listen to
the `copy_resources.sites.post` event and make adjustments so their data is correctly
copied over.

Modules that add block layouts and navigation links to sites via the `block_layouts`
and `navigation_links` configuration will need to **revert** the copied layouts and
links to their original state. Thankfully, the `CopyResources` service object has
convenience methods for this. For example:

```php
$sharedEventManager->attach(
    '*',
    'copy_resources.sites.post',
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
    'copy_resources.sites.post',
    function (Event $event) {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $site = $event->getParam('resource');
        $siteCopy = $event->getParam('resource_copy');
        $copyResources = $event->getParam('copy_resources');

        // Create resource copies.
        $myApiResources = $api->search('my_api_resource', ['site_id' => $site->id()])->getContent();
        foreach ($myApiResources as $myApiResource) {
            $preCallback = function (&$jsonLd) use ($siteCopy){
                unset($jsonLd['o:owner']);
                $jsonLd['o:site']['o:id'] = $siteCopy->id();
            };
            $copyResources->createResourceCopy('my_api_resource', $myApiResource, $preCallback);
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
    'copy_resources.sites.post',
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

## Copyright

Copy Resources is Copyright © 2021-present Corporation for Digital Scholarship,
Vienna, Virginia, USA http://digitalscholar.org

The Corporation for Digital Scholarship distributes the Omeka source code under
the GNU General Public License, version 3 (GPLv3). The full text of this license
is given in the license file.

The Omeka name is a registered trademark of the Corporation for Digital Scholarship.

Third-party copyright in this distribution is noted where applicable.

All rights not expressly granted are reserved.
