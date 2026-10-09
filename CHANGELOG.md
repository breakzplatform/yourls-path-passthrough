# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-10-02

### Added

- Quoted string arguments in saved templates (for example `{{TrimPrefix "/" .Path}}`) survive the YOURLS sanitizer.

### Changed

- Keywords that contain slashes pass through to the longest matching keyword, so `team/project/foo` goes to `team/project` when it exists, instead of to `team`.
- Saved templates are shown intact in the admin list and stats page instead of being displayed with their braces and spaces stripped.

## [1.1.0] - 2026-09-21

### Added

- Destination templates in long URLs, with a golink-style template engine.
- Plain-append fallback and hooks for logging when a template is invalid.

### Security

- Control characters are stripped from the path, query and rendered output before the `Location` header is sent.

## [1.0.0] - 2026-09-19

### Added

- Append the extra URL path and query string of a short URL to its long URL.
- `path_passthrough_location` filter.
- Plugin text domain loading for translations.

[1.2.0]: https://github.com/breakzplatform/yourls-path-passthrough/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/breakzplatform/yourls-path-passthrough/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/breakzplatform/yourls-path-passthrough/releases/tag/v1.0.0
