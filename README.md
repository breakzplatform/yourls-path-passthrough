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

## Rules

- The first path segment is the keyword. The rest is joined to the long URL's path with a
  single slash (a trailing slash on either side doesn't produce `//`).
- The request's query string is merged into the long URL's query: request parameters
  replace long-URL parameters with the same name, the others are kept in order. The long
  URL's fragment is kept.
- Only requests with extra path are touched. `sho.rt/gh` and `sho.rt/gh?x=1` behave exactly
  as in plain YOURLS (which drops the query string).
- If the whole request is itself a keyword or a YOURLS page (for keywords that contain
  slashes), it wins over passthrough.
- If the first segment is not a keyword, nothing changes: YOURLS does its usual
  "not found" redirect to the site root.
- Stats pages (`sho.rt/gh+`) are unaffected. `sho.rt/gh/foo+` passes `foo+` through.

Differences from golink: no `{{.Path}}` templates in long URLs, the query string of a plain
`sho.rt/gh?x=1` is not forwarded, and golink adds repeated parameters instead of replacing
them.

## Hooks used

- `get_request` (filter): `yourls-loader.php` parses the request from its return value.
  When the first segment is an existing keyword, the plugin returns just that segment and
  remembers the rest, so the loader serves `yourls-go.php` for the keyword as usual (click
  counting and stats included).
- `redirect_shorturl` (action): marks that the redirect about to happen is the one for the
  remembered keyword.
- `redirect_location` (filter): appends the path and merges the query into that redirect
  only. Other redirects are untouched.

The expanded URL goes through a `path_passthrough_location` filter
(`$expanded, $original, $path, $query`) before it is sent.

## Caveats

YOURLS answers short URLs with `301 Moved Permanently`, which browsers cache. After you
change a link's target, browsers that already followed it may keep going to the old one
until their cache expires. That applies to passthrough URLs as much as to plain keywords.

## License

MIT. Contact: dev@joseli.to.
