<?php

/**
 * Brand Logo
 *
 * Shows the sender's brand logo as contact photo: the BIMI logo published
 * by the sender domain, or the icon of its website as a fallback.
 *
 * @version 1.0.0
 * @author  Antonio Arias <antonio@antarias.com>
 * @license GNU GPLv3+
 */
class brand_logo extends rcube_plugin
{
    public const VERSION = '1.0.0';
    public const URI = 'https://github.com/antarias/roundcube-brand_logo';

    public $task = 'mail|addressbook|settings';

    // 1x1 transparent PNG: "no picture" answer for the message list, cacheable by the browser
    private const EMPTY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=';

    /** @var brand_logo_resolver|null */
    private $resolver;

    /**
     * Plugin details for Settings > About
     */
    public static function info()
    {
        return [
            'name' => 'Brand Logo',
            'vendor' => 'Antonio Arias',
            'version' => self::VERSION,
            'license' => 'GPL-3.0-or-later',
            'uri' => self::URI,
        ];
    }

    #[\Override]
    public function init()
    {
        $this->load_config();

        $rcmail = rcmail::get_instance();
        $config = $rcmail->config;

        if ($rcmail->task == 'settings') {
            $this->add_texts('localization/');
            $this->add_hook('preferences_list', [$this, 'preferences_list']);
            $this->add_hook('preferences_save', [$this, 'preferences_save']);
            return;
        }

        if ($rcmail->task == 'mail') {
            if (empty($rcmail->action) && $rcmail->output->type == 'html'
                && $config->get('brand_logo_enabled', true) && $config->get('brand_logo_list', true)
            ) {
                $this->include_script('brand_logo.js');
                $this->include_stylesheet('brand_logo.css');
            }
            return;
        }

        $this->add_hook('contact_photo', [$this, 'contact_photo']);
        $this->register_action('plugin.brand_logo', [$this, 'serve']);
    }

    /**
     * 'contact_photo' hook: runs after address books, before identicon-like plugins
     */
    public function contact_photo($args)
    {
        $rcmail = rcmail::get_instance();

        if (!empty($args['url']) || !empty($args['data']) || empty($args['email'])
            || $rcmail->action != 'photo' || !$rcmail->config->get('brand_logo_enabled', true)
        ) {
            return $args;
        }

        $domain = self::email_domain($args['email']);
        $logo = $domain ? $this->get_logo($domain) : null;

        if ($logo && $logo['mime'] == 'image/png') {
            // Served by the photo action itself, which lets the browser cache it for a day
            $args['data'] = $logo['data'];
        } elseif ($logo) {
            $args['url'] = ['_action' => 'plugin.brand_logo', '_domain' => $domain];
        } elseif (rcube_utils::get_input_string('_bl', rcube_utils::INPUT_GET) === '1') {
            // List avatars draw the sender initial themselves, skip identicon & co.
            $args['data'] = base64_decode(self::EMPTY_PNG);
        }

        return $args;
    }

    /**
     * Serves a cached logo with its real content type
     */
    public function serve()
    {
        $rcmail = rcmail::get_instance();
        $domain = self::normalize_domain(rcube_utils::get_input_string('_domain', rcube_utils::INPUT_GET));
        $logo = $domain ? $this->get_logo($domain) : null;

        if (!$logo) {
            $rcmail->output->sendExitError(404);
        }

        $headers = [
            'Content-Type: ' . $logo['mime'],
            'X-Content-Type-Options: nosniff',
        ];

        // SVG is a document: never let it run scripts, even when opened directly
        if ($logo['mime'] == 'image/svg+xml') {
            $headers[] = "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox";
        }

        $rcmail->output->future_expire_header(86400);
        $rcmail->output->sendExit($logo['data'], $headers);
    }

    public function preferences_list($args)
    {
        $rcmail = rcmail::get_instance();
        $dont_override = (array) $rcmail->config->get('dont_override', []);

        $prefs = ['mailview' => ['brand_logo_enabled', 'showbrandlogos'], 'mailbox' => ['brand_logo_list', 'showbrandlogoslist']];

        if (!isset($prefs[$args['section']])) {
            return $args;
        }

        [$name, $label] = $prefs[$args['section']];

        if (in_array($name, $dont_override)) {
            return $args;
        }

        $field_id = 'rcmfd_' . $name;
        $checkbox = new html_checkbox(['name' => '_' . $name, 'id' => $field_id, 'value' => 1]);

        $args['blocks']['main']['options'][$name] = [
            'title' => html::label($field_id, rcube::Q($this->gettext($label))),
            'content' => $checkbox->show($rcmail->config->get($name, true) ? 1 : 0),
        ];

        return $args;
    }

    public function preferences_save($args)
    {
        $dont_override = (array) rcmail::get_instance()->config->get('dont_override', []);
        $prefs = ['mailview' => 'brand_logo_enabled', 'mailbox' => 'brand_logo_list'];
        $name = $prefs[$args['section']] ?? null;

        if ($name && !in_array($name, $dont_override)) {
            $args['prefs'][$name] = !empty($_POST['_' . $name]);
        }

        return $args;
    }

    /**
     * Returns ['mime' => ..., 'data' => ...] or null, using the shared cache
     */
    private function get_logo($domain)
    {
        $rcmail = rcmail::get_instance();
        $config = $rcmail->config;

        if ($config->get('brand_logo_cache') === null) {
            $config->set('brand_logo_cache', 'db');
        }
        if ($config->get('brand_logo_cache_ttl') === null) {
            $config->set('brand_logo_cache_ttl', '30d');
        }

        $cache = $rcmail->get_cache_shared('brand_logo');
        $key = 'v1.' . md5($domain);
        $now = time();

        if ($cache && is_array($entry = $cache->get($key)) && ($entry['expires'] ?? 0) > $now) {
            return $entry['mime'] ? ['mime' => $entry['mime'], 'data' => base64_decode($entry['data'])] : null;
        }

        $logo = $this->resolver()->resolve($domain);

        if ($cache) {
            $ttl = $logo ? $config->get('brand_logo_ttl', 7 * 86400) : $config->get('brand_logo_negative_ttl', 86400);
            $cache->set($key, [
                'expires' => $now + (int) $ttl,
                'mime' => $logo['mime'] ?? null,
                'data' => $logo ? base64_encode($logo['data']) : null,
            ]);
        }

        return $logo;
    }

    private function resolver()
    {
        if (!$this->resolver) {
            require_once __DIR__ . '/lib/brand_logo_resolver.php';

            $config = rcmail::get_instance()->config;
            $this->resolver = new brand_logo_resolver([
                'sources' => $config->get('brand_logo_sources', ['bimi', 'favicon']),
                'favicon_require_dmarc' => $config->get('brand_logo_favicon_require_dmarc', true),
                'service_url' => $config->get('brand_logo_service_url'),
                'size' => $config->get('brand_logo_size', 128),
                'min_size' => $config->get('brand_logo_min_size', 32),
                'timeout' => $config->get('brand_logo_timeout', 5),
                'max_bytes' => $config->get('brand_logo_max_bytes', 512 * 1024),
                'rsvg_convert' => $config->get('brand_logo_rsvg_convert'),
                'user_agent' => $config->get('brand_logo_user_agent')
                    ?: 'Mozilla/5.0 (compatible; RoundcubeBrandLogo/' . self::VERSION . '; +' . self::URI . ')',
            ]);
        }

        return $this->resolver;
    }

    private static function email_domain($email)
    {
        $pos = strrpos((string) $email, '@');

        return $pos === false ? null : self::normalize_domain(substr($email, $pos + 1));
    }

    private static function normalize_domain($domain)
    {
        $domain = rcube_utils::idn_to_ascii(strtolower(trim((string) $domain)));

        return preg_match('/^(?=.{3,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', (string) $domain)
            ? $domain : null;
    }
}
