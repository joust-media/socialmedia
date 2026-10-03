<?php
/**
 * Clean links — one place that knows every portal URL.
 *
 *   portalUrl('posts', ['client' => 'kenda', 'post' => 12])
 *       clean links on  → /portal/kenda/posts/12
 *       clean links off → /portal/posts.php?client=kenda&post=12      (works on any Apache folder)
 *   portalRouteMatch('kenda/posts/12') → ['script' => 'posts', 'params' => ['client' => 'kenda', 'post' => '12']]
 *   cleanUrlsOn()                       → whether links are emitted in the clean form (see below)
 *
 * The route map (portalRouteTable()) is shared by route.php (the front controller the .htaccess sends unknown
 * paths to), by portalUrl() (the reverse direction), by clientLink() (client-auth-lib.php) and by the JS twin
 * App.urls (static/js/app.js reads window.PortalUrls, written by helpers.php portalUrlsScript()).
 *
 * When are clean links on?  config.php 'clean_urls' => true|false forces it; otherwise they are on exactly when
 * the portal's own .htaccess carries the block Manage → Tools → Clean links writes (cleanLinksInstalled()). The
 * block is only kept when a self-request proved it works, so a folder without mod_rewrite keeps the query-string
 * URLs, and every old link keeps working either way (old URLs 301 to the clean form once the block is in).
 *
 * Machine endpoints (drive-ingest, slack-events, slack-actions, notify-cron, notify-thumb, …) are never captured by the router:
 * an extensionless name whose .php exists is served by that file first, and the reserved names never reach route.php.
 *
 * Function definitions only (function_exists-guarded); no output, no DB.
 */

if (!defined('CLEAN_LINKS_BEGIN')) define('CLEAN_LINKS_BEGIN', '# BEGIN joust-portal-clean-links');
if (!defined('CLEAN_LINKS_END'))   define('CLEAN_LINKS_END', '# END joust-portal-clean-links');
if (!defined('CLEAN_LINKS_VERSION')) define('CLEAN_LINKS_VERSION', 1);

if (!function_exists('portalConfigAliases')) {
    /**
     * config.php keys that were named differently by the notifications and the client sign-in work: canonical key →
     * the older names still accepted. config.example.php lists only the canonical names. Either name may be asked for
     * (portalConfig('portal_base_url') finds a 'portal_url' entry and the reverse); a blank value counts as not set,
     * and the canonical key wins when both are set.
     */
    function portalConfigAliases(): array {
        return [
            'portal_url'       => ['portal_base_url'],
            'mail_sink_dir'    => ['mail_capture_dir'],
            'notify_from'      => ['auth_mail_from'],
            'notify_from_name' => ['auth_mail_from_name'],
            'notify_reply_to'  => ['auth_mail_reply_to'],
            'notify_envelope'  => ['auth_mail_envelope'],
        ];
    }
}

if (!function_exists('portalConfigPick')) {
    /** $key from a config array, alias-aware (portalConfigAliases()); null when neither name holds a value. */
    function portalConfigPick(array $cfg, string $key) {
        $group = null;
        foreach (portalConfigAliases() as $canon => $aliases) {
            if ($key === $canon || in_array($key, $aliases, true)) { $group = array_merge([$canon], $aliases); break; }
        }
        if ($group === null) return array_key_exists($key, $cfg) ? $cfg[$key] : null;
        foreach ($group as $k) {
            if (!array_key_exists($k, $cfg) || $cfg[$k] === null) continue;
            if (is_string($cfg[$k]) && trim($cfg[$k]) === '') continue;
            return $cfg[$k];
        }
        return null;
    }
}

if (!function_exists('portalConfigArray')) {
    /** config.php as an array (the global $config db.php loaded, else read once; never fatal, never printed). */
    function portalConfigArray(): array {
        static $cfg = null;
        if (isset($GLOBALS['config']) && is_array($GLOBALS['config'])) return $GLOBALS['config'];
        if ($cfg === null) {
            $cfg = [];
            $file = __DIR__ . '/config.php';
            if (is_file($file)) {
                try { $c = (static function (string $f) { return include $f; })($file); if (is_array($c)) $cfg = $c; }
                catch (Throwable $e) { $cfg = []; }
            }
        }
        return $cfg;
    }
}

if (!function_exists('portalConfig')) {
    /** One key of config.php (alias-aware: portalConfigAliases()); $default when missing. */
    function portalConfig(string $key, $default = null) {
        $v = portalConfigPick(portalConfigArray(), $key);
        return $v === null ? $default : $v;
    }
}

if (!function_exists('portalBasePath')) {
    /** '' or '/portal' — dirname(SCRIPT_NAME), same rule as helpers.php basePath() (which delegates here). */
    function portalBasePath(): string {
        static $cached = null;
        if ($cached !== null) return $cached;
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $dir = rtrim(str_replace('\\', '/', dirname((string)$script)), '/');
        if ($dir === '.' || $dir === '') $dir = '';
        return $cached = $dir;
    }
}

if (!function_exists('portalRouteTable')) {
    /**
     * The clean-link map: [pattern, script, fixed params, dropped defaults]. Patterns are relative to the portal
     * folder; {client} = a client slug, {section} = a lower-case word, every other {name} = a positive integer.
     * Order matters for the forward match (literals before placeholders of the same shape).
     *
     *   /portal/                         index.php                  (admin: all clients · client: → their Home)
     *   /portal/<c>/                     index.php?client=<c>       Home
     *   /portal/<c>/posts[/<id>]         posts.php[&post=<id>]
     *   /portal/<c>/assets               assets.php                 (Library; view=library is the default)
     *   /portal/<c>/tires[/<id>]         assets.php?view=collections[&item=<id>]
     *   /portal/<c>/tires/new            add-feature.php?module=tires              (admin)
     *   /portal/<c>/tires/<id>/edit      add-feature.php?module=tires&edit_item=<id> (admin)
     *   /portal/<c>/emails[/<id>]        emails.php[&email=<id>]
     *   /portal/<c>/emails/new           add-email.php               (admin)
     *   /portal/<c>/emails/<id>/edit     add-email.php?edit=<id>     (admin)
     *   /portal/<c>/flows[/<id>]         flows.php[&flow=<id>]
     *   /portal/<c>/pages[/<id>]         pages.php[&page=<id>]
     *   /portal/<c>/pages/new            add-page.php                (admin)
     *   /portal/<c>/pages/<id>/edit      add-page.php?edit=<id>      (admin)
     *   /portal/<c>/projects             projects.php
     *   /portal/<c>/build                build.php                   (admin)
     *   /portal/<c>/manage[/<section>]   manage.php[&section=…]      (admin, scoped)
     *   /portal/<c>/sign-in              sign-in.php?client=<c>      (branded sign-in)
     *   /portal/manage[/<section>]       manage.php[?section=…]      (admin)
     *   /portal/<name>                   <name>.php                  (every other script, extensionless: sign-in, sign-out,
     *                                                                login, logout, drive, prompts, vehicles, drive-ingest, …)
     * Anything else keeps its remaining parameters in the query string (?status=approved, ?msg=…).
     */
    function portalRouteTable(): array {
        return [
            ['{client}/',                    'index'],
            ['{client}/posts/{post}',        'posts'],
            ['{client}/posts',               'posts'],
            ['{client}/assets',              'assets',      [], ['view' => 'library']],
            ['{client}/tires/new',           'add-feature', ['module' => 'tires']],
            ['{client}/tires/{edit_item}/edit', 'add-feature', ['module' => 'tires']],
            ['{client}/tires/{item}',        'assets',      ['view' => 'collections']],
            ['{client}/tires',               'assets',      ['view' => 'collections']],
            ['{client}/emails/new',          'add-email'],
            ['{client}/emails/{edit}/edit',  'add-email'],
            ['{client}/emails/{email}',      'emails'],
            ['{client}/emails',              'emails'],
            ['{client}/flows/{flow}',        'flows'],
            ['{client}/flows',               'flows'],
            ['{client}/pages/new',           'add-page'],
            ['{client}/pages/{edit}/edit',   'add-page'],
            ['{client}/pages/{page}',        'pages'],
            ['{client}/pages',               'pages'],
            ['{client}/projects',            'projects'],
            ['{client}/build',               'build'],
            ['{client}/manage/{section}',    'manage'],
            ['{client}/manage',              'manage'],
            ['{client}/sign-in',             'sign-in'],
            ['manage/{section}',             'manage'],
            ['manage',                       'manage'],
        ];
    }
}

if (!function_exists('portalReservedSegments')) {
    /** First path segments that can never be a client slug (route.php, client-admin.php slug validation). */
    function portalReservedSegments(): array {
        static $out = null;
        if ($out !== null) return $out;
        $out = ['manage', 'sign-in', 'sign-out', 'login', 'logout', 'static', 'uploads', 'media', 'legacy', 'partials',
                'docs', 'tests', 'api', 'route', 'admin', 'new', 'view-as',
                // machine endpoints (portalMachineEndpoints()): never a client, never routed
                'drive-ingest', 'slack-events', 'slack-actions', 'notify-cron', 'notify-thumb', 'notify-pump', 'mail-inbound',
                '__clean-links-check'];
        foreach (glob(__DIR__ . '/*.php') ?: [] as $f) $out[] = basename($f, '.php');
        foreach (glob(__DIR__ . '/*', GLOB_ONLYDIR) ?: [] as $d) $out[] = basename($d);
        $out = array_values(array_unique(array_map('strtolower', $out)));
        return $out;
    }
}

if (!function_exists('portalMachineEndpoints')) {
    /** Extensionless machine endpoints the router must never capture (the .htaccess passes them straight through). */
    function portalMachineEndpoints(): array {
        return ['drive-ingest', 'slack-events', 'slack-actions', 'notify-cron', 'notify-thumb', 'notify-pump', 'mail-inbound'];
    }
}

if (!function_exists('portalParamPattern')) {
    /** Regex for one placeholder ({client}, {section}, else a positive id). */
    function portalParamPattern(string $name): string {
        if ($name === 'client')  return '/^[a-z0-9][a-z0-9-]{0,39}$/';
        if ($name === 'section') return '/^[a-z]{2,20}$/';
        return '/^[1-9][0-9]{0,9}$/';
    }
}

if (!function_exists('portalRouteMatch')) {
    /**
     * Forward direction: a path below the portal folder ('kenda/posts/12', '/kenda/', 'manage/tools') →
     * ['script' => …, 'params' => […], 'canonical' => the clean path the map prints] or null.
     */
    function portalRouteMatch(string $path): ?array {
        $path = ltrim($path, '/');
        $trailing = $path !== '' && substr($path, -1) === '/';
        $segs = $path === '' ? [] : explode('/', rtrim($path, '/'));
        if (!$segs) return null;
        foreach ($segs as $s) { if ($s === '' || $s === '.' || $s === '..') return null; }
        $first = strtolower($segs[0]);
        foreach (portalRouteTable() as $route) {
            [$pattern, $script] = $route;
            $fixed = $route[2] ?? [];
            $psegs = explode('/', rtrim($pattern, '/'));
            if (count($psegs) !== count($segs)) continue;
            $params = [];
            $ok = true;
            foreach ($psegs as $i => $p) {
                $v = $segs[$i];
                if (preg_match('/^\{([a-z_]+)\}$/', $p, $m)) {
                    if ($m[1] === 'client') {
                        $v = strtolower($v);
                        if (in_array($v, portalReservedSegments(), true)) { $ok = false; break; }
                    }
                    if (!preg_match(portalParamPattern($m[1]), $v)) { $ok = false; break; }
                    $params[$m[1]] = $v;
                } elseif (strtolower($v) !== $p) {
                    $ok = false; break;
                }
            }
            if (!$ok) continue;
            if (isset($params['client']) === false && $first !== strtolower($psegs[0])) continue;
            return ['script' => $script, 'params' => $params + $fixed, 'canonical' => portalFillPattern($pattern, $params),
                    'trailing' => $trailing];
        }
        return null;
    }
}

if (!function_exists('portalFillPattern')) {
    function portalFillPattern(string $pattern, array $params): string {
        return preg_replace_callback('/\{([a-z_]+)\}/', static function ($m) use ($params) {
            return rawurlencode((string)($params[$m[1]] ?? ''));
        }, $pattern);
    }
}

if (!function_exists('portalRouteReverse')) {
    /**
     * Reverse direction: script + params → [clean path below the folder, leftover params] or null when the map has
     * no entry. A placeholder accepts a real value or a JS template token like __ID__ (posts.php?post=__ID__ → posts/__ID__).
     */
    function portalRouteReverse(string $script, array $params): ?array {
        $best = null; $bestScore = -1;
        foreach (portalRouteTable() as $route) {
            [$pattern, $rScript] = $route;
            if ($rScript !== $script) continue;
            $fixed = $route[2] ?? [];
            $defaults = $route[3] ?? [];
            preg_match_all('/\{([a-z_]+)\}/', $pattern, $m);
            $names = $m[1];
            $hasClient = in_array('client', $names, true);
            if ($hasClient !== (isset($params['client']) && is_scalar($params['client']) && (string)$params['client'] !== '')) continue;
            $ok = true;
            foreach ($names as $n) {
                $v = isset($params[$n]) && is_scalar($params[$n]) ? (string)$params[$n] : '';
                if ($v === '' || (!preg_match(portalParamPattern($n), $v) && !preg_match('/^__[A-Z_]+__$/', $v))) { $ok = false; break; }
                if ($n === 'client' && in_array($v, portalReservedSegments(), true)) { $ok = false; break; }
            }
            if (!$ok) continue;
            foreach ($fixed as $k => $fv) {
                if (!isset($params[$k]) || !is_scalar($params[$k]) || (string)$params[$k] !== (string)$fv) { $ok = false; break; }
            }
            if (!$ok) continue;
            $score = count($names) * 2 + count($fixed);
            if ($score > $bestScore) {
                $rest = $params;
                foreach ($names as $n) unset($rest[$n]);
                foreach ($fixed as $k => $_) unset($rest[$k]);
                foreach ($defaults as $k => $dv) { if (isset($rest[$k]) && is_scalar($rest[$k]) && (string)$rest[$k] === (string)$dv) unset($rest[$k]); }
                $best = [portalFillPattern($pattern, $params), $rest];
                $bestScore = $score;
            }
        }
        return $best;
    }
}

if (!function_exists('cleanLinksHtaccessPath')) {
    function cleanLinksHtaccessPath(): string { return __DIR__ . '/.htaccess'; }
}

if (!function_exists('cleanLinksInstalled')) {
    /** The portal's .htaccess carries our clean-links block (written + verified by Manage → Tools → Clean links). */
    function cleanLinksInstalled(): bool {
        static $on = null;
        if ($on !== null) return $on;
        $f = cleanLinksHtaccessPath();
        if (!is_file($f) || is_link($f)) return $on = false;
        $t = @file_get_contents($f, false, null, 0, 65536);
        return $on = (is_string($t) && strpos($t, CLEAN_LINKS_BEGIN) !== false && strpos($t, CLEAN_LINKS_END) !== false);
    }
}

if (!function_exists('cleanUrlsOn')) {
    /** Emit clean links? config 'clean_urls' (true / false) wins; otherwise: the verified block is installed. */
    function cleanUrlsOn(): bool {
        if (array_key_exists('__portal_clean_override', $GLOBALS)) return (bool)$GLOBALS['__portal_clean_override'];
        $forced = portalConfig('clean_urls', null);
        if ($forced !== null && $forced !== '') return (bool)$forced;
        return cleanLinksInstalled();
    }
}

if (!function_exists('portalScriptName')) {
    /** 'posts.php' / '/posts' / 'posts' → 'posts'; '' / 'index' → 'index'. */
    function portalScriptName(string $page): string {
        $name = preg_replace('/\.php$/', '', trim($page, '/'));
        return $name === '' ? 'index' : $name;
    }
}

if (!function_exists('portalUrl')) {
    /**
     * THE link builder. $page = a script name with or without '.php' ('posts', 'manage.php', '' = Home);
     * $params = query parameters, 'client' included when scoped (null / '' values are dropped).
     * Root-rooted output ('/portal/kenda/posts/12' or '/portal/posts.php?client=kenda&post=12').
     */
    function portalUrl(string $page, array $params = []): string {
        $script = portalScriptName($page);
        $clean = [];
        foreach ($params as $k => $v) { if ($v !== null && $v !== '') $clean[(string)$k] = is_bool($v) ? ($v ? '1' : '0') : $v; }
        $base = portalBasePath();
        if (cleanUrlsOn()) {
            $rev = portalRouteReverse($script, $clean);
            if ($rev !== null) {
                [$path, $rest] = $rev;
                return $base . '/' . $path . ($rest ? '?' . http_build_query($rest) : '');
            }
            if ($script === 'index') {
                return $base . '/' . ($clean ? '?' . http_build_query($clean) : '');
            }
            return $base . '/' . $script . ($clean ? '?' . http_build_query($clean) : '');
        }
        if ($script === 'index') return $base . '/' . ($clean ? '?' . http_build_query($clean) : '');
        $ext = (defined('CLEAN_URLS') && CLEAN_URLS) ? '' : '.php';
        return $base . '/' . $script . $ext . ($clean ? '?' . http_build_query($clean) : '');
    }
}

if (!function_exists('portalOrigin')) {
    /** 'https://joustmedia.com' — config 'portal_url' (alias 'portal_base_url'; scheme + host part) wins, else the request. */
    function portalOrigin(): string {
        $cfgUrl = trim((string)portalConfig('portal_url', ''));
        if ($cfgUrl !== '' && preg_match('#^(https?://[^/]+)#i', $cfgUrl, $m)) return rtrim($m[1], '/');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443')
              || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
        $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host);
        return ($https ? 'https' : 'http') . '://' . ($host !== '' ? $host : 'localhost');
    }
}

if (!function_exists('portalAbsoluteUrl')) {
    /** An absolute URL for a root-rooted portal path. With config 'portal_url' set (cron / CLI senders) its folder
     *  replaces the request's base path. */
    function portalAbsoluteUrl(string $rootedPath): string {
        $cfgUrl = rtrim(trim((string)portalConfig('portal_url', '')), '/');
        if ($cfgUrl !== '' && preg_match('#^https?://[^/]+(/.*)?$#i', $cfgUrl, $m)) {
            $cfgBase = rtrim($m[1] ?? '', '/');
            $base = portalBasePath();
            if ($base !== '' && strpos($rootedPath, $base . '/') === 0) $rootedPath = substr($rootedPath, strlen($base));
            elseif ($base !== '' && $rootedPath === $base) $rootedPath = '/';
            return portalOrigin() . $cfgBase . $rootedPath;
        }
        return portalOrigin() . $rootedPath;
    }
}

if (!function_exists('portalCurrentRequest')) {
    /** The script + effective params of THIS request (route.php sets PORTAL_ROUTED; the params are $_GET as the page sees
     *  them). For the JS twin (portalUrlsScript) and the old → clean redirect. */
    function portalCurrentRequest(): array {
        $script = portalScriptName(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php')));
        $params = [];
        foreach ($_GET as $k => $v) { if (is_scalar($v)) $params[(string)$k] = (string)$v; }
        return ['script' => $script, 'params' => $params];
    }
}

// ---------------------------------------------------------------------
// .htaccess writer (Manage → Tools → Clean links)
// ---------------------------------------------------------------------

if (!function_exists('cleanLinksBlock')) {
    /**
     * The block we own in <portal>/.htaccess. Every directive sits inside <IfModule mod_rewrite.c> (an unguarded
     * directive answers 500 for the whole folder on this host). Substitutions are absolute URL paths, so a
     * RewriteBase elsewhere in the file cannot bend them. Order:
     *   1. real files / folders are served as they are (static/, uploads/, every *.php, the folder index)
     *   2. extensionless names whose .php exists → that script (drive-ingest, slack-events, notify-cron, sign-in, …)
     *   3. the reserved machine names never reach the router (a 404 rather than a client page)
     *   4. everything else → route.php (the clean-link map)
     */
    function cleanLinksBlock(string $base): string {
        $b = rtrim($base, '/');
        $machine = implode('|', portalMachineEndpoints());   // plain [a-z-] names
        return CLEAN_LINKS_BEGIN . ' v' . CLEAN_LINKS_VERSION . "\n"
             . "# Written by Manage > Tools > Clean links (url-lib.php). Remove this block (BEGIN to END) to turn clean links off;\n"
             . "# the portal then prints its old ?client= links again, which keep working either way.\n"
             . "<IfModule mod_rewrite.c>\n"
             . "    RewriteEngine On\n"
             . "    RewriteCond %{REQUEST_FILENAME} -f [OR]\n"
             . "    RewriteCond %{REQUEST_FILENAME} -d\n"
             . "    RewriteRule ^ - [L]\n"
             . "    RewriteCond %{REQUEST_FILENAME}.php -f\n"
             . "    RewriteRule ^([A-Za-z0-9_-]+)$ {$b}/\$1.php [L]\n"
             . "    RewriteRule ^({$machine})/?$ - [L]\n"
             . "    RewriteRule ^ {$b}/route.php [L,QSA]\n"
             . "</IfModule>\n"
             . CLEAN_LINKS_END . "\n";
    }
}

if (!function_exists('cleanLinksMerge')) {
    /** $existing .htaccess text with our block replaced in place, or prepended (ours must run before host rules). */
    function cleanLinksMerge(string $existing, string $block): string {
        $b = strpos($existing, CLEAN_LINKS_BEGIN);
        $e = strpos($existing, CLEAN_LINKS_END);
        if ($b !== false && $e !== false && $e > $b) {
            $endLine = strpos($existing, "\n", $e);
            $after = $endLine === false ? '' : substr($existing, $endLine + 1);
            return substr($existing, 0, $b) . $block . $after;
        }
        if (trim($existing) === '') return $block;
        return $block . "\n" . $existing;
    }
}

if (!function_exists('cleanLinksStrip')) {
    /** $existing without our block (Remove clean links). */
    function cleanLinksStrip(string $existing): string {
        $b = strpos($existing, CLEAN_LINKS_BEGIN);
        $e = strpos($existing, CLEAN_LINKS_END);
        if ($b === false || $e === false || $e < $b) return $existing;
        $endLine = strpos($existing, "\n", $e);
        $after = $endLine === false ? '' : substr($existing, $endLine + 1);
        $out = substr($existing, 0, $b) . ltrim($after, "\n");
        return $out;
    }
}

if (!function_exists('cleanLinksUnguarded')) {
    /** Directives in OUR block that are not inside an <IfModule> (must be none) — used by the tests and the installer. */
    function cleanLinksUnguarded(string $block): array {
        $depth = 0; $bad = [];
        foreach (preg_split('/\r?\n/', $block) as $line) {
            $t = trim($line);
            if ($t === '' || $t[0] === '#') continue;
            if (stripos($t, '<IfModule') === 0) { $depth++; continue; }
            if (stripos($t, '</IfModule') === 0) { $depth--; continue; }
            if ($depth <= 0) $bad[] = $t;
        }
        return $bad;
    }
}

if (!function_exists('cleanLinksSelfCheck')) {
    /**
     * Ask the live server whether the rules work: GET <base>/__clean-links-check?n=<nonce> must come back from
     * route.php with the nonce, the folder index must not 500, and an extensionless script must still answer.
     * Returns ['ok' => bool, 'checks' => [[url, code, ok]], 'error' => string].
     */
    function cleanLinksSelfCheck(string $base, int $timeout = 8): array {
        $out = ['ok' => false, 'checks' => [], 'error' => ''];
        if (!function_exists('curl_init')) { $out['error'] = 'PHP curl is not available, so the rules cannot be checked.'; return $out; }
        $nonce = bin2hex(random_bytes(8));
        $origin = rtrim((string)portalConfig('clean_links_check_origin', ''), '/');
        if ($origin === '') $origin = portalOrigin();
        $b = rtrim($base, '/');
        $probes = [
            ['url' => $origin . $b . '/__clean-links-check?n=' . $nonce, 'want' => 'router'],
            ['url' => $origin . $b . '/',                                 'want' => 'not500'],
            ['url' => $origin . $b . '/sign-in',                          'want' => 'ok200'],
        ];
        foreach ($probes as $p) {
            $ch = curl_init($p['url']);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $timeout,
                                    CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_USERAGENT => 'joust-portal-clean-links/1',
                                    CURLOPT_HTTPHEADER => ['Accept: application/json, text/html']]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = $body === false ? curl_error($ch) : '';
            curl_close($ch);
            $good = false;
            if ($p['want'] === 'router') {
                $j = is_string($body) ? json_decode($body, true) : null;
                $good = $code === 200 && is_array($j) && ($j['router'] ?? '') === 'clean-links' && ($j['n'] ?? '') === $nonce;
            } elseif ($p['want'] === 'not500') {
                $good = $code >= 200 && $code < 500;
            } else {
                $good = $code === 200;
            }
            $out['checks'][] = ['url' => $p['url'], 'code' => $code, 'ok' => $good];
            if (!$good) {
                $out['error'] = 'The server answered ' . ($code ?: 'nothing') . ' for ' . $p['url'] . ($err !== '' ? ' (' . $err . ')' : '') . '.';
                return $out;
            }
        }
        $out['ok'] = true;
        return $out;
    }
}

if (!function_exists('cleanLinksInstall')) {
    /**
     * Install (or refresh) the block: back up the current .htaccess (.htaccess.bak-<stamp>, unreadable over the
     * web like every .ht* file), merge our block in (other rules are kept, ours first), self-check, and put the
     * old file back when the check fails. $check = callable(string $base): array (tests inject one).
     * Returns ['ok', 'action' => installed|updated|rolled-back|failed, 'backup', 'error', 'checks'].
     */
    function cleanLinksInstall(string $base, ?callable $check = null): array {
        $file = cleanLinksHtaccessPath();
        $out = ['ok' => false, 'action' => 'failed', 'backup' => '', 'error' => '', 'checks' => []];
        if (is_link($file) || (file_exists($file) && !is_file($file))) { $out['error'] = 'The .htaccess is not a regular file; nothing was changed.'; return $out; }
        $had = is_file($file);
        $old = $had ? (string)@file_get_contents($file) : '';
        if ($had && $old === '' && filesize($file) > 0) { $out['error'] = 'The current .htaccess could not be read; nothing was changed.'; return $out; }
        $block = cleanLinksBlock($base);
        if (cleanLinksUnguarded($block)) { $out['error'] = 'Refusing to write an unguarded rule.'; return $out; }
        $new = cleanLinksMerge($old, $block);
        if ($had) {
            $bak = $file . '.bak-' . date('Ymd-His');
            if (@file_put_contents($bak, $old) === false) { $out['error'] = 'Could not back up the current .htaccess; nothing was changed.'; return $out; }
            @chmod($bak, 0644);
            $out['backup'] = basename($bak);
        }
        if (@file_put_contents($file, $new) === false) { $out['error'] = 'Could not write .htaccess (permissions?).'; return $out; }
        @chmod($file, 0644);
        $res = $check ? $check($base) : cleanLinksSelfCheck($base);
        $out['checks'] = $res['checks'] ?? [];
        if (empty($res['ok'])) {
            if ($had) @file_put_contents($file, $old); else @unlink($file);
            $out['action'] = 'rolled-back';
            $out['error'] = 'The check failed, so the previous rules were put back. ' . (string)($res['error'] ?? '');
            return $out;
        }
        $out['ok'] = true;
        $out['action'] = strpos($old, CLEAN_LINKS_BEGIN) !== false ? 'updated' : 'installed';
        return $out;
    }
}

if (!function_exists('cleanLinksRemove')) {
    /** Take our block out again (backup first). ['ok', 'backup', 'error']. */
    function cleanLinksRemove(): array {
        $file = cleanLinksHtaccessPath();
        $out = ['ok' => false, 'backup' => '', 'error' => ''];
        if (!is_file($file) || is_link($file)) { $out['ok'] = true; return $out; }
        $old = (string)@file_get_contents($file);
        if (strpos($old, CLEAN_LINKS_BEGIN) === false) { $out['ok'] = true; return $out; }
        $bak = $file . '.bak-' . date('Ymd-His');
        if (@file_put_contents($bak, $old) === false) { $out['error'] = 'Could not back up the current .htaccess.'; return $out; }
        $out['backup'] = basename($bak);
        $new = cleanLinksStrip($old);
        $ok = trim($new) === '' ? @unlink($file) : (@file_put_contents($file, $new) !== false);
        if (!$ok) { $out['error'] = 'Could not write .htaccess.'; return $out; }
        $out['ok'] = true;
        return $out;
    }
}
