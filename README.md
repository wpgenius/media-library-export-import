# Media Library Export Import

[![Try it in your browser](https://img.shields.io/badge/WordPress_Playground-Try_it_in_your_browser-3858e9?logo=wordpress)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/wpgenius/media-library-export-import/main/.wordpress-org/blueprints/blueprint.json)

WordPress plugin that exports a media library to a CSV file and imports it into another site, or transfers media between subsites of a multisite network.

- **Export**: *Media → Export / Import* downloads a CSV with each item's URL, uploads path, title, caption, description, alt text, upload date and MIME type.
- **Import**: upload the CSV on the target site. Items are processed in AJAX batches. Each file is reused if already in the library, registered if already in the uploads folder, or downloaded from its original URL.
- **ID mapping**: download an old ID → new ID CSV after the import.
- **Multisite**: *Network Admin → Sites → Media Transfer* copies media between subsites on the server.

Requires WordPress 6.2+ and PHP 7.4+. See [readme.txt](readme.txt) for the full description and FAQ.

## Try it in your browser

[**Open the demo in WordPress Playground**](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/wpgenius/media-library-export-import/main/.wordpress-org/blueprints/blueprint.json): it installs the latest release with a few sample images and opens *Media → Export / Import*. Playground runs a single site, so the network transfer screen isn't in the demo.

## Filters

| Filter | Default | Purpose |
| --- | --- | --- |
| `mlei_batch_size` | `5` | Items imported per AJAX request |
| `mlei_download_timeout` | `60` | Seconds allowed to download one file |

## Development

```bash
composer install
npm ci
cp .env.example .env && docker compose up -d   # WordPress at http://localhost:8000
```

| Command | What it checks |
| --- | --- |
| `composer lint` | PHP syntax |
| `composer phpcs` | WordPress Coding Standards (`composer phpcbf` fixes most issues) |
| `composer phpstan` | Static analysis |
| `npm run lint` | JS and CSS |

CI runs the same checks, plus WordPress Plugin Check, on every pull request. See [CONTRIBUTING.md](CONTRIBUTING.md).

## How changes ship

```
issue  →  branch  →  pull request (Closes #N)  →  squash merge to main  →  automatic release  →  deploy (if enabled)
```

- **`main` is protected.** Changes go through pull requests, merged with squash. Every PR closes an issue (the *Linked issue* check), and CI must pass.
- **Releases are automatic.** On each merge to `main`, the [Release workflow](.github/workflows/release.yml) builds the plugin zip (everything [`.distignore`](.distignore) doesn't exclude) and releases only if it differs from the last release, so docs, CI and config changes don't release. It bumps the version (patch by default; label the PR `release:minor` or `release:major` for more), writes the version and the changelog (one line per closed issue) into the plugin header and `readme.txt`, tags it and publishes a GitHub release with the zip. Don't edit the version or changelog by hand.
- **Deploys** to the WordPress.org plugin SVN run after each release, only while the repo variable `DEPLOY_ENABLED` is `true` (off by default). See the header of [deploy.yml](.github/workflows/deploy.yml) for the variables and secrets.

## License

GPLv3 or later. See [LICENSE](LICENSE).
