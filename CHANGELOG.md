# Changelog

## 1.0.0 - 2026-11-07

First release.

- Sender brand logo as contact photo in the message view: BIMI logo, website icon or an optional favicon service.
- DMARC gate: logos only for domains with an enforced policy (`quarantine`/`reject` at 100%).
- Round sender avatars in the message list (Elastic, widescreen layout), with the sender initial when there is no logo.
- User options in Preferences to turn off the logos and the list avatars.
- Logos cached in Roundcube's shared cache and padded to square PNG (Imagick, GD or rsvg-convert).
- English and Spanish translations.
