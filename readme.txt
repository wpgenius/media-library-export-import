=== Media Library Export Import ===
Contributors: wpgenius
Tags: media, export, import, migration, multisite
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Move your media library to another site with a CSV file, or transfer media between subsites of a multisite network.

== Description ==

Media Library Export Import copies media library items from one WordPress site to another, keeping each file's year/month path so existing file URLs stay the same.

**Export**

Go to *Media → Export / Import* and download a CSV file that lists every item in the media library: file URL, path, title, caption, description, alt text, upload date and file type.

**Import**

Upload that CSV file on the target site. Each item is processed in small batches with a progress bar, so large libraries do not time out. For each item the importer:

* skips it if the library already has an item for the same file,
* registers the file if you already copied it into the uploads folder (for example over FTP or SSH),
* otherwise downloads it from its original URL (optional).

Image sizes are generated, and the title, caption, description, alt text and upload date are kept.

**ID mapping**

When the import finishes, download a CSV file that maps each old attachment ID and URL to the new ID and URL. Use it to update galleries, featured images or custom fields that store attachment IDs.

**Multisite**

Super admins get *Network Admin → Sites → Media Transfer*: pick a source and a target subsite and the media is copied directly on the server, with no CSV file and no HTTP downloads.

**What it does not do**

The plugin moves media items only. It does not change posts, pages or other content, and it does not search and replace URLs.

== Installation ==

1. Install and activate the plugin on both sites (or network activate it on multisite).
2. On the source site, go to *Media → Export / Import* and download the export CSV.
3. On the target site, go to *Media → Export / Import*, upload the CSV file and start the import.
4. Optionally download the ID mapping CSV when the import finishes.

== Frequently Asked Questions ==

= Do I need to copy the files myself? =

No. If a file is missing on the target site, the importer downloads it from its original URL when "Download files that are missing" is ticked. Copying the uploads folder yourself first is faster for very large libraries; the importer then only registers the files.

= Will the files keep the same URLs? =

Files keep their path inside the uploads folder (for example `2024/05/photo.jpg`). The domain changes to the target site's. If a different file (by file size) already uses that path on the target site, the new file is saved with a numeric suffix such as `photo-1.jpg`, which the ID mapping CSV shows.

= What happens if I run the import twice? =

Items imported earlier from the same URL, or whose file is already in the target library, are reported as "Already in library" and are not duplicated.

= Which file types are imported? =

Only file types the target site allows for uploads. Downloaded files are checked against their real content type.

= The import stopped halfway. What now? =

Click *Resume*. The import continues from the last finished item. Unfinished imports are cleaned up after a day.

= Can I change the batch size or download timeout? =

Yes, with filters:

`add_filter( 'mlei_batch_size', function () { return 10; } );`

`add_filter( 'mlei_download_timeout', function () { return 120; } );`

= Does the plugin connect to other servers? =

Only when you import a CSV file with "Download files that are missing" ticked: for each file that is not on the target site yet, the importer downloads it from the URL listed in your CSV file, which is the source site you exported from. Nothing is sent to that site beyond the normal file request, and the plugin uses no third-party services, tracking or remote code. Export, network transfer and imports without downloads stay on your own server.

= Who can use it? =

Export and import need the `manage_options` and `upload_files` capabilities (administrators). The network transfer needs `manage_network_options` (super admins).

== Changelog ==

= 1.0.1 =

* Plugin description matches the readme summary (#22)
* Sanitize the "Download missing files" import option (#23)
* Readme explains when the plugin connects to other servers (#24)

= 1.0.0 =

* Initial release.

== Upgrade Notice ==

= 1.0.0 =
First public release.
