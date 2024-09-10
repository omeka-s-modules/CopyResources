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

## Copyright

Copy Resources is Copyright © 2021-present Corporation for Digital Scholarship,
Vienna, Virginia, USA http://digitalscholar.org

The Corporation for Digital Scholarship distributes the Omeka source code under
the GNU General Public License, version 3 (GPLv3). The full text of this license
is given in the license file.

The Omeka name is a registered trademark of the Corporation for Digital Scholarship.

Third-party copyright in this distribution is noted where applicable.

All rights not expressly granted are reserved.
