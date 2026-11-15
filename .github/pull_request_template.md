<!-- Every pull request closes at least one issue. One line per issue: -->
Closes #

## What changed

<!-- For several issues, list the changes under each issue number. -->

## How it was tested

- [ ] `composer lint`, `composer phpcs` and `composer phpstan` pass
- [ ] `npm run lint` passes
- [ ] Tried in WordPress (single site, and multisite for network changes)

<!--
Will merging this make a release? Only if the plugin zip changes, that is, a
file .distignore does not exclude (PHP, assets, readme.txt...). Docs,
tests, CI and config changes make no release. The version goes up by a patch;
add the label release:minor or release:major for more. Don't edit the version
or the changelog by hand.
-->
