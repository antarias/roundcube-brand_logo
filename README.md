# Brand Logo for Roundcube

Shows the sender's brand logo in the message header, in the place of the contact photo, and as a round avatar in the message list, similar to what Yahoo Mail or Gmail do. Senders without a picture get their initial on a colored circle in the list.

Sources, in order:

1. **BIMI**: the logo the sender domain publishes in DNS (`default._bimi.<domain>`).
2. **Website icon**: `apple-touch-icon`, `<link rel="icon">` or `/favicon.ico` of the sender's organizational domain.
3. **Favicon service** (optional, off by default): an external service such as Google's favicon API, for sites that block automated downloads.

Personal contact photos (and other address book sources such as Gravatar) always take precedence. When no logo is found, the next `contact_photo` plugin (for example `identicon`) runs as usual.

## Safety

- **DMARC gate.** A logo is only shown when the sender domain's DMARC policy is `quarantine` or `reject` at 100%. BIMI requires this, and the plugin applies it to website icons too (configurable). Your mail server then rejects or quarantines messages that forge that domain, so a spoofed sender does not get the real brand's logo. Look-alike domains (`paypa1.com`) get their own icon, never the impersonated one.
- **No VMC check.** BIMI Verified Mark Certificates are not validated.
- **Server-side fetching.** Logos are downloaded by the Roundcube server, never by the browser, so remote sites do not learn who reads the mail. Only HTTPS is used; private, loopback and reserved addresses are refused on every redirect; downloads are size- and time-limited.
- **SVG.** BIMI logos are SVG. They are rasterized to PNG when `rsvg-convert` or Imagick with SVG support is available. Otherwise they are served as SVG with a sandboxing `Content-Security-Policy` and `nosniff`, after refusing documents with scripts, event handlers, `foreignObject` or entities.

## Requirements

- Roundcube 1.6 or newer (tested with 1.7.5), PHP 8.1+
- PHP `curl` extension
- Recommended: `imagick` (ICO/WebP support, square padding) or `gd`

## Installation

With composer, from the Roundcube directory:

```sh
composer require antarias/brand_logo
```

Or manually: copy this directory to `plugins/brand_logo`, then add it to the plugin list **before** `identicon` (if you use it):

```php
$config['plugins'] = ['brand_logo', 'identicon', /* ... */];
```

Copy `config.inc.php.dist` to `config.inc.php` to change the defaults. Logos are cached in Roundcube's shared cache (`cache_shared` table by default), no extra setup needed.

## Configuration

See [config.inc.php.dist](config.inc.php.dist). The most relevant options:

| Option | Default | Description |
|---|---|---|
| `brand_logo_sources` | `['bimi', 'favicon']` | Sources and their order. Add `'service'` to use `brand_logo_service_url`. |
| `brand_logo_service_url` | `null` | For example `https://www.google.com/s2/favicons?domain=%d&sz=128`. Sends sender domains to that service. |
| `brand_logo_favicon_require_dmarc` | `true` | Apply the DMARC gate to website icons and the service. |
| `brand_logo_enabled` | `true` | Default of the user option in *Preferences > Displaying Messages*. |
| `brand_logo_list` | `true` | Avatars in the message list, user option in *Preferences > Mailbox View*. |
| `brand_logo_min_size` | `32` | Discard smaller icons. |

## Limitations

- List avatars are designed for the Elastic skin (widescreen layout). In Sent folders they show the first recipient.
- The first time a list shows new sender domains, their logos are looked up on the server; after that they come from the cache and the browser keeps them for a day.
- DMARC is checked per domain, not per message. The plugin relies on the mail server enforcing DMARC on delivery.
- The organizational domain is derived with a short heuristic (`co.uk`, `com.es`, ...) instead of the full Public Suffix List.

## License

GNU General Public License v3.0 or later. See [LICENSE](LICENSE).
