<?php

/**
 * Finds a brand logo for a mail domain: BIMI first, then the website icon.
 * Independent of Roundcube so it can be tested from the command line.
 *
 * @author  Antonio Arias <antonio@antarias.com>
 * @license GNU GPLv3+
 */
class brand_logo_resolver
{
    // Second-level labels under ccTLDs that act as public suffixes (co.uk, com.es, ...)
    private const SLD_SUFFIXES = ['ac', 'co', 'com', 'edu', 'gob', 'go', 'gov', 'ltd', 'me', 'mil', 'ne', 'net', 'nhs', 'nom', 'or', 'org', 'plc', 'sch'];

    private $opts;

    public function __construct(array $opts = [])
    {
        $this->opts = array_merge([
            'sources' => ['bimi', 'favicon'],
            'favicon_require_dmarc' => true,
            'service_url' => null,
            'size' => 128,
            'min_size' => 32,
            'timeout' => 5,
            'max_bytes' => 512 * 1024,
            'rsvg_convert' => null,
            'user_agent' => null,
        ], array_filter($opts, static fn ($v) => $v !== null));

        if (!$this->opts['user_agent']) {
            $this->opts['user_agent'] = 'Mozilla/5.0 (compatible; RoundcubeBrandLogo/1.0)';
        }
    }

    /**
     * @return array|null ['mime' => string, 'data' => string, 'source' => 'bimi'|'favicon']
     */
    public function resolve($domain)
    {
        $enforced = $this->dmarc_enforced($domain);

        foreach ((array) $this->opts['sources'] as $source) {
            $logo = null;

            if ($source == 'bimi' && $enforced) {
                $logo = $this->bimi_logo($domain);
            } elseif ($source == 'favicon' && ($enforced || !$this->opts['favicon_require_dmarc'])) {
                $logo = $this->site_icon(self::org_domain($domain));
            } elseif ($source == 'service' && $this->opts['service_url'] && ($enforced || !$this->opts['favicon_require_dmarc'])) {
                $url = str_replace('%d', rawurlencode(self::org_domain($domain)), $this->opts['service_url']);
                $res = $this->fetch($url);
                $logo = $res ? $this->normalize($res['body']) : null;
            }

            if ($logo) {
                return $logo + ['source' => $source];
            }
        }

        return null;
    }

    /**
     * True when the domain's DMARC policy is quarantine or reject at 100%,
     * the precondition BIMI sets and the safeguard against spoofed logos.
     */
    public function dmarc_enforced($domain)
    {
        $org = self::org_domain($domain);

        foreach (array_unique([$domain, $org]) as $name) {
            if (!($rec = $this->txt("_dmarc.{$name}", 'v=DMARC1'))) {
                continue;
            }

            $tags = self::tags($rec);
            $policy = ($name != $domain && !empty($tags['sp'])) ? $tags['sp'] : ($tags['p'] ?? 'none');
            $pct = $tags['pct'] ?? '100';

            return in_array(strtolower($policy), ['quarantine', 'reject']) && $pct === '100';
        }

        return false;
    }

    public static function org_domain($domain)
    {
        $labels = explode('.', $domain);
        $n = count($labels);

        if ($n <= 2) {
            return $domain;
        }

        $keep = (strlen($labels[$n - 1]) == 2 && in_array($labels[$n - 2], self::SLD_SUFFIXES)) ? 3 : 2;

        return implode('.', array_slice($labels, -$keep));
    }

    private function bimi_logo($domain)
    {
        foreach (array_unique([$domain, self::org_domain($domain)]) as $name) {
            if (!($rec = $this->txt("default._bimi.{$name}", 'v=BIMI1'))) {
                continue;
            }

            // An empty l= tag means the domain explicitly declines BIMI
            $url = trim(explode(',', self::tags($rec)['l'] ?? '')[0]);
            $res = $url ? $this->fetch($url) : null;

            return $res ? $this->normalize($res['body'], 'image/svg+xml') : null;
        }

        return null;
    }

    private function site_icon($domain)
    {
        $candidates = [];
        $base = "https://{$domain}/";

        if ($page = $this->fetch($base)) {
            $base = $page['url'];
            $candidates = $this->html_icons($page['body'], $base);
        }

        $candidates[] = ['url' => self::absolute_url($base, '/apple-touch-icon.png'), 'score' => 179];
        $candidates[] = ['url' => self::absolute_url($base, '/favicon.ico'), 'score' => 16];

        usort($candidates, static fn ($a, $b) => $b['score'] <=> $a['score']);

        $tried = [];
        foreach ($candidates as $c) {
            if (isset($tried[$c['url']]) || count($tried) >= 5) {
                continue;
            }
            $tried[$c['url']] = true;

            if (($res = $this->fetch($c['url'])) && ($logo = $this->normalize($res['body']))) {
                return $logo;
            }
        }

        return null;
    }

    private function html_icons($html, $base)
    {
        if (!class_exists('DOMDocument') || stripos($html, '<link') === false) {
            return [];
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML($html, \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $icons = [];
        foreach ($doc->getElementsByTagName('link') as $link) {
            $rel = ' ' . strtolower(preg_replace('/\s+/', ' ', $link->getAttribute('rel'))) . ' ';
            $href = trim($link->getAttribute('href'));

            if (!$href || str_starts_with($href, 'data:') || !preg_match('/ (icon|apple-touch-icon(-precomposed)?) /', $rel)) {
                continue;
            }

            $type = strtolower($link->getAttribute('type'));
            $sizes = strtolower($link->getAttribute('sizes'));
            $score = str_contains($rel, 'apple-touch-icon') ? 180 : 32;

            if (preg_match_all('/(\d+)x(\d+)/', $sizes, $m)) {
                $score = max(array_map('intval', $m[1]));
            } elseif ($sizes == 'any' || $type == 'image/svg+xml' || preg_match('/\.svgz?(\?|$)/i', $href)) {
                $score = 256;
            }

            $icons[] = ['url' => self::absolute_url($base, $href), 'score' => $score];
        }

        return array_filter($icons, static fn ($i) => $i['url']);
    }

    /**
     * Converts any supported image into a square PNG; SVG stays SVG when it can't be rasterized.
     */
    private function normalize($data, $expect = null)
    {
        if (str_starts_with($data, "\x1f\x8b")) {
            $data = @gzdecode($data, $this->opts['max_bytes']) ?: '';
        }

        $is_svg = preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*|<!DOCTYPE[^>]*>\s*)*<svg[\s>]/is', $data);

        if ($expect == 'image/svg+xml' && !$is_svg) {
            return null;
        }

        if ($is_svg) {
            if (preg_match('/<script|<foreignObject|<!ENTITY|\son[a-z]+\s*=|javascript:/i', $data)) {
                return null;
            }

            $png = $this->rasterize_svg($data);

            return $png ? ['mime' => 'image/png', 'data' => $png] : ['mime' => 'image/svg+xml', 'data' => $data];
        }

        return $this->square_png($data);
    }

    private function rasterize_svg($svg)
    {
        $size = (int) $this->opts['size'];

        if (class_exists('Imagick') && in_array('SVG', Imagick::queryFormats())) {
            try {
                $im = new Imagick();
                $im->setBackgroundColor(new ImagickPixel('transparent'));
                $im->setResolution(288, 288);
                $im->readImageBlob($svg);

                return $this->imagick_square($im);
            } catch (Throwable $e) {
            }
        }

        $bin = $this->opts['rsvg_convert'];
        if ($bin === null) {
            foreach (['/usr/bin/rsvg-convert', '/usr/local/bin/rsvg-convert'] as $path) {
                if (@is_executable($path)) {
                    $bin = $path;
                    break;
                }
            }
        }

        if (!$bin || !function_exists('proc_open')) {
            return null;
        }

        $proc = proc_open([$bin, '-w', (string) $size, '-h', (string) $size, '-a', '-f', 'png'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($proc)) {
            return null;
        }

        fwrite($pipes[0], $svg);
        fclose($pipes[0]);
        $png = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($proc) === 0 && $png ? ($this->square_png($png)['data'] ?? null) : null;
    }

    private function square_png($data)
    {
        $mimes = [\IMAGETYPE_PNG => 'image/png', \IMAGETYPE_GIF => 'image/gif', \IMAGETYPE_JPEG => 'image/jpeg',
            \IMAGETYPE_ICO => 'image/x-icon', \IMAGETYPE_WEBP => 'image/webp'];

        // Only hand known raster formats to image libraries
        $info = @getimagesizefromstring($data);
        if (!$info || !isset($mimes[$info[2]])) {
            return null;
        }

        if (class_exists('Imagick')) {
            try {
                $im = new Imagick();
                $im->readImageBlob($data, 'logo.' . image_type_to_extension($info[2], false));

                // ICO files hold several sizes: keep the largest
                $best = 0;
                $best_w = -1;
                foreach ($im as $i => $frame) {
                    if ($frame->getImageWidth() > $best_w) {
                        $best_w = $frame->getImageWidth();
                        $best = $i;
                    }
                }
                $im->setIteratorIndex($best);
                $frame = $im->getImage();

                if (min($frame->getImageWidth(), $frame->getImageHeight()) < $this->opts['min_size']) {
                    return null;
                }

                return ['mime' => 'image/png', 'data' => $this->imagick_square($frame)];
            } catch (Throwable $e) {
                return null;
            }
        }

        if (min($info[0], $info[1]) < $this->opts['min_size']) {
            return null;
        }

        if ($info[2] != \IMAGETYPE_ICO && function_exists('imagecreatefromstring') && ($src = @imagecreatefromstring($data))) {
            $size = (int) $this->opts['size'];
            $scale = min($size / $info[0], $size / $info[1]);
            $w = max(1, (int) round($info[0] * $scale));
            $h = max(1, (int) round($info[1] * $scale));

            $dst = imagecreatetruecolor($size, $size);
            imagealphablending($dst, false);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, intdiv($size - $w, 2), intdiv($size - $h, 2), 0, 0, $w, $h, $info[0], $info[1]);

            ob_start();
            imagepng($dst);

            return ['mime' => 'image/png', 'data' => ob_get_clean()];
        }

        // No image library: pass through formats browsers render natively
        return ['mime' => $mimes[$info[2]], 'data' => $data];
    }

    private function imagick_square(Imagick $im)
    {
        $size = (int) $this->opts['size'];

        $im->setImageBackgroundColor(new ImagickPixel('transparent'));
        $im->thumbnailImage($size, $size, true);

        $canvas = new Imagick();
        $canvas->newImage($size, $size, new ImagickPixel('transparent'), 'png');
        $canvas->compositeImage($im, Imagick::COMPOSITE_OVER,
            intdiv($size - $im->getImageWidth(), 2), intdiv($size - $im->getImageHeight(), 2));
        $canvas->setImageFormat('png');

        return $canvas->getImageBlob();
    }

    /**
     * HTTPS GET that only connects to public addresses, re-checked on every redirect.
     *
     * @return array|null ['url' => final url, 'body' => string]
     */
    private function fetch($url, $redirects = 5)
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        for ($i = 0; $i <= $redirects; $i++) {
            $u = parse_url($url);

            if (!$u || strtolower($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user'])) {
                return null;
            }

            $host = strtolower($u['host']);
            $port = (int) ($u['port'] ?? 443);
            $ip = $this->public_ip($host);

            if (!$ip) {
                return null;
            }

            $body = '';
            $max = (int) $this->opts['max_bytes'];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                \CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
                \CURLOPT_PROTOCOLS => \CURLPROTO_HTTPS,
                \CURLOPT_FOLLOWLOCATION => false,
                \CURLOPT_CONNECTTIMEOUT => $this->opts['timeout'],
                \CURLOPT_TIMEOUT => $this->opts['timeout'] * 2,
                \CURLOPT_ENCODING => '',
                \CURLOPT_USERAGENT => $this->opts['user_agent'],
                \CURLOPT_HTTPHEADER => ['Accept: text/html,image/*;q=0.9,*/*;q=0.5'],
                \CURLOPT_HEADER => false,
                \CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$body, $max) {
                    $body .= $chunk;

                    return strlen($body) > $max ? 0 : strlen($chunk);
                },
            ]);

            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
            $location = curl_getinfo($ch, \CURLINFO_REDIRECT_URL);

            if ($code >= 300 && $code < 400 && $location) {
                $url = $location;
                continue;
            }

            return ($ok && $code == 200 && $body !== '') ? ['url' => $url, 'body' => $body] : null;
        }

        return null;
    }

    private function public_ip($host)
    {
        $flags = \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE;

        if (filter_var($host, \FILTER_VALIDATE_IP)) {
            return filter_var($host, \FILTER_VALIDATE_IP, $flags) ? $host : null;
        }

        // Every address must be public, otherwise a mixed record could reach internal hosts
        $ips = @gethostbynamel($host) ?: [];
        foreach ($ips as $ip) {
            if (!filter_var($ip, \FILTER_VALIDATE_IP, $flags)) {
                return null;
            }
        }

        return $ips[0] ?? null;
    }

    private function txt($name, $prefix)
    {
        foreach (@dns_get_record($name, \DNS_TXT) ?: [] as $r) {
            $value = isset($r['entries']) ? implode('', $r['entries']) : ($r['txt'] ?? '');

            if (stripos(ltrim($value), $prefix) === 0) {
                return $value;
            }
        }

        return null;
    }

    private static function tags($record)
    {
        $tags = [];
        foreach (explode(';', $record) as $part) {
            if (str_contains($part, '=')) {
                [$k, $v] = explode('=', $part, 2);
                $tags[strtolower(trim($k))] = trim($v);
            }
        }

        return $tags;
    }

    public static function absolute_url($base, $ref)
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $ref)) {
            return $ref;
        }

        $b = parse_url($base);
        $origin = 'https://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

        if (str_starts_with($ref, '//')) {
            return 'https:' . $ref;
        }
        if (str_starts_with($ref, '/')) {
            return $origin . $ref;
        }

        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
        $path = $dir . $ref;

        // Resolve ./ and ../ segments
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg == '..') {
                array_pop($out);
            } elseif ($seg != '.') {
                $out[] = $seg;
            }
        }

        return $origin . '/' . ltrim(implode('/', $out), '/');
    }
}
