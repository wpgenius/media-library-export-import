# Media Library Export Import

WordPress plugin that exports a media library to a CSV file and imports it into another site, or transfers media between subsites of a multisite network.

- **Export**: *Media → Export / Import* downloads a CSV with each item's URL, uploads path, title, caption, description, alt text, upload date and MIME type.
- **Import**: upload the CSV on the target site. Items are processed in AJAX batches. Each file is reused if already in the library, registered if already in the uploads folder, or downloaded from its original URL.
- **ID mapping**: download an old ID → new ID CSV after the import.
- **Multisite**: *Network Admin → Sites → Media Transfer* copies media between subsites on the server.

Requires WordPress 6.2+ and PHP 7.4+. See [readme.txt](readme.txt) for the full description and FAQ.

## Filters

| Filter | Default | Purpose |
| --- | --- | --- |
| `mlei_batch_size` | `5` | Items imported per AJAX request |
| `mlei_download_timeout` | `60` | Seconds allowed to download one file |

## License

GPLv3 or later. See [LICENSE](LICENSE).
