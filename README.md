# yourls-path-passthrough

A [YOURLS](https://yourls.org) plugin for [golink](https://github.com/tailscale/golink)-style
redirects: anything after the keyword is passed through to the long URL.

| Request | Keyword `gh` → `https://github.com/` |
|---|---|
| `sho.rt/gh` | `https://github.com/` |
| `sho.rt/gh/tailscale/golink` | `https://github.com/tailscale/golink` |
| `sho.rt/gh/tailscale/golink?tab=readme` | `https://github.com/tailscale/golink?tab=readme` |

| Request | Keyword `search` → `https://example.com/search?q=default&lang=en#top` |
|---|---|
| `sho.rt/search/docs?q=yourls` | `https://example.com/search/docs?lang=en&q=yourls#top` |

Tested on YOURLS 1.10.6 with PHP 8.5. Requires YOURLS 1.10.5+ and PHP 8.1+.

## Install

```sh
cd /path/to/yourls/user/plugins
git clone https://github.com/breakzplatform/yourls-path-passthrough.git
```

Then activate it on the Plugins page. There are no settings.

## Templates

You can place `{{.Path}}` templates in the long URL to control exactly where the passed-through path is injected, instead of just appending it to the end.

| Request | Keyword `barsa` → `https://barsa.example/{{if .Path}}?link=/{{.Path}}{{end}}` |
|---|---|
| `sho.rt/barsa` | `https://barsa.example/` |
| `sho.rt/barsa/50-ref/winston-avisos` | `https://barsa.example/?link=/50-ref/winston-avisos` |

| Request | Keyword `search` → `https://example.com/search?q={{if .Path}}{{.Path}}{{else}}default{{end}}&lang=en#top` |
|---|---|
| `sho.rt/search` | `https://example.com/search?q=default&lang=en#top` |
| `sho.rt/search/yourls` | `https://example.com/search?q=yourls&lang=en#top` |

Supported actions inside `{{ }}`:

- `{{.Path}}`: Inserts the remaining path exactly as received.
- `{{if .Path}} ... {{end}}` and `{{if .Path}} ... {{else}} ... {{end}}`: Conditionally inserts text based on whether a path was provided. Blocks can nest.

### Invalid templates

If a template fails to parse or render, its actions are stripped and the link falls back to plain append behavior. The plugin hooks `path_passthrough_invalid_template` (action; receives a SINGLE array argument `[ $url, $error, $path, $query ]`) and `path_passthrough_invalid_template_url` (filter) for logging or overriding.

## Rules

- The longest leading run of path segments that is a keyword wins. Usually that is the
  first segment, but with keywords that contain slashes (via a plugin such as
  `yourls-forward-slash-in-urls`) and both `team` and `team/project` saved,
  `sho.rt/team/project/foo` goes to `team/project` with `foo` passed through, and
  `sho.rt/team/other` goes to `team` with `other`. If the long URL does not contain templates, the rest is joined to the long URL's path with a single slash (a trailing slash on either side doesn't produce `//`).
- If the long URL contains templates, the extra path is not appended at the end; it only appears where the template specifies. Template links are evaluated even without an extra path.
- The request's query string is merged into the long URL's query: request parameters
  replace long-URL parameters with the same name, the others are kept in order. The long
  URL's fragment is kept.
- For non-template links, only requests with extra path are touched. `sho.rt/gh` and `sho.rt/gh?x=1` behave exactly
  as in plain YOURLS (which drops the query string). Template links, however, also merge the request query string when there is NO extra path (e.g. `sho.rt/barsa?x=1`).
- Control characters (`\x00`–`\x1F`, `\x7F`) are stripped from the path, query, and rendered output.
- If the whole request is itself a keyword or a YOURLS page (for keywords that contain
  slashes), it wins over passthrough.
- If the first segment is not a keyword, nothing changes: YOURLS does its usual
  "not found" redirect to the site root.
- Stats pages (`sho.rt/gh+`) are unaffected. `sho.rt/gh/foo+` passes `foo+` through.

Differences from golink: v1.1 supports `{{.Path}}` and `if`/`else`/`end`, but functions (`PathEscape`, `QueryEscape`, `ToLower`, etc.), `.Now`, and `.User` are not yet supported (planned for v1.2+). The query string of a plain
`sho.rt/gh?x=1` is not forwarded, and golink adds repeated parameters instead of replacing
them.

## Sanitizer

YOURLS strips `{`, `}` and spaces from long URLs on save (due to `yourls_esc_url`'s allowed-character list, since YOURLS commit 9451c6d1, 2012-12-09, which imported WordPress's KSES-based URL allowlist; before that, commit c4581a0f of 2012-07-01 allowed them).

The plugin hooks the `sanitize_url` filter and restores ONLY well-formed actions made of identifiers and `.Field` references. The restore is structural (valid template or not; validity checked at expansion; keeps links saved today working once v1.2 adds functions). Bare braces are never restored, and whitespace inside actions is normalized (edge spaces trimmed, one space between tokens). Admin output still escapes the long URL (`esc_url` for href, `esc_html` for text, `esc_attr` in the edit form).

Double-quoted string arguments are restored too, so links saved for v1.2 functions such as `{{TrimPrefix "/" .Path}}` survive the save (until v1.2 they fall back to plain append, like any template with an unknown action). Strings hold printable ASCII only, without escape sequences and without `"`, `\`, `{`, `}`, `<`, `>`, `&`, `'` or backticks; anything else is not restored. Whitespace inside a string is kept as typed.

The same restore runs on the `esc_url` filter in the `display` context, so the admin list, the stats page and its share box show the saved template (`{{if .Path}}`) instead of the stripped `if.Path`. Quotes in string arguments are shown as `&quot;` in that output.

## Hooks used

- `get_request` (filter): `yourls-loader.php` parses the request from its return value.
  When the first segment is an existing keyword, the plugin returns just that segment and
  remembers the rest, so the loader serves `yourls-go.php` for the keyword as usual (click
  counting and stats included).
- `redirect_shorturl` (action): marks that the redirect about to happen is the one for the
  remembered keyword. It now also arms plain requests (no extra path) to template links.
- `redirect_location` (filter): appends the path and merges the query into that redirect
  only. Other redirects are untouched.
- `sanitize_url` (filter): restores well-formed template actions that YOURLS stripped on save.
- `esc_url` (filter, `display` context only): shows saved templates intact in the admin pages.

The expanded URL goes through a `path_passthrough_location` filter
(`$expanded, $original, $path, $query`) before it is sent.

## Caveats

- **301 caching:** YOURLS answers short URLs with `301 Moved Permanently`, which browsers cache. After you change a link's target, browsers that already followed it may keep going to the old one until their cache expires. That applies to passthrough URLs as much as to plain keywords. To avoid it, install a plugin that changes the redirect: [302-Instead](https://github.com/EpicPilgrim/302-instead) sends `302` instead of `301` ([fork with more options](https://github.com/timcrockford/302-instead)), and [Caching Disabled for Redirects](https://github.com/dgw/yourls-shorturl-no-cache) sends no-cache headers.
- **Do not create an `uninstall.php` file:** In setups where this plugin is pinned as active (like the `ts-golink-yourls` stack), clicking "Deactivate" in the admin panel will run the uninstallation script and wipe all plugin data without actually deactivating the plugin.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. Contact: dev@joseli.to.
