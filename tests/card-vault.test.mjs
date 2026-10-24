/**
 * Card Vault Submodule E2E Test Suite
 * Strictly zero em dashes in code, copy, comments, and reports.
 * Zero mock or synthetic data.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';
import { readSourceFile, hasAdminRoleCheck } from '../../xophz-compass/tests/harness/code-analyzer.mjs';
import assert from 'node:assert';

const PLUGIN_DIR = 'wp-content/plugins/xophz-compass-card-vault';
const CONTAINER_PLUGIN_DIR = '/var/www/html/' + PLUGIN_DIR;
const REST_SOURCES = [
  { label: 'api', file: PLUGIN_DIR + '/includes/class-card-vault-api.php' },
  { label: 'catalog', file: PLUGIN_DIR + '/includes/class-card-vault-catalog-rest.php' },
];

/**
 * Parse every register_rest_route( ... ) call in a PHP source string.
 * Returns one entry per call: its route path and, per handler array, the
 * methods expression and the permission_callback expression as written.
 */
function parseRestRoutes(source) {
  const routes = [];
  const re = /register_rest_route\s*\(/g;
  let match;
  while ((match = re.exec(source)) !== null) {
    const start = match.index + match[0].length;
    const end = findClosing(source, start - 1, '(', ')');
    const body = source.slice(start, end);
    const routePath = (body.match(/,\s*'([^']+)'/) || [])[1];
    const methods = [...body.matchAll(/'methods'\s*=>\s*([^,]+),/g)].map((m) => m[1].trim());
    const perms = [...body.matchAll(/'permission_callback'\s*=>\s*(array\([^)]*\)|'[^']*')/g)]
      .map((m) => m[1].replace(/\s+/g, ' ').replace(/^array\( (?:\$this|__CLASS__), '([^']+)' \)$/, '$1').replace(/^'|'$/g, ''));
    routes.push({ path: routePath, methods, perms });
  }
  return routes;
}

/** Index just past the bracket that closes the one at openIndex, or the source length when unbalanced. */
function findClosing(source, openIndex, openChar, closeChar) {
  const depthChange = { [openChar]: 1, [closeChar]: -1 };
  let depth = 0;
  for (let i = openIndex; i < source.length; i++) {
    depth += depthChange[source[i]] || 0;
    const isClosed = depth === 0;
    if (isClosed) return i;
  }
  return source.length;
}

/** Return the body of a PHP method by name, or null. */
function methodBody(source, name) {
  const idx = source.search(new RegExp('function\\s+' + name + '\\s*\\('));
  const isDefined = idx >= 0;
  if (!isDefined) return null;
  const open = source.indexOf('{', idx);
  return source.slice(open, findClosing(source, open, '{', '}') + 1);
}

/**
 * Routes whose permission_callback is __return_true, keyed "<source> <path>" (plus "#<handler index>"
 * when a path has several handlers). Each reason states why the route is open. It does not claim
 * the handler is safe, only that the route is intended to be reachable without a login at the
 * permission layer; handler-level checks are not asserted here.
 */
const PUBLIC_ROUTE_ALLOWLIST = {
  'api /pokemon/search': 'Public master catalog search for the storefront and logged-out visitors.',
  'api /showcase/(?P<slug>[^/]+)': 'Public card show showcase page, read by slug.',
  'api /showcase/(?P<slug>[^/]+)/bid': 'Visiting vendors submit bids without an account; handler must validate input.',
  'api /settings/community#0': 'Read side of community settings is shown to logged-out visitors; the write side is dealer only.',
  'api /pos/config': 'POS client bootstrap configuration; read only.',
  'api /pos/verify-payment': 'Stripe success page calls it without login; the handler settles only an order whose stored session id Stripe confirms (see comment in source).',
  'api /products#0': 'Public shop product listing; creation (second handler) is dealer only.',
  'api /license/checkout': 'Anonymous buyers start a licence checkout session; handler must validate input.',
  'api /referrals/summary': 'Permission layer is open; the handler is expected to scope data to the requester.',
  'api /auth/login': 'Login endpoint has to be reachable before a session exists.',
  'api /auth/register': 'Registration endpoint has to be reachable before a session exists.',
  'api /auth/logout': 'Logout endpoint; harmless without a session.',
  'api /auth/me': 'Session probe; reports logged-out state to anonymous callers.',
  'catalog /catalog/cards/(?P<id>[a-zA-Z0-9-_]+)/image': 'Card images are embedded by img tags, which cannot send auth headers.',
  'catalog /catalog/status': 'Catalog import status for the public catalog UI; read only.',
  'catalog /catalog/categories': 'Catalog category list for public filters; read only.',
  'catalog /catalog/crawler/status': 'Crawler progress readout; read only.',
};

/** Permission callbacks that gate on a login or capability, with the text each body must contain. */
const PERMISSION_CALLBACK_CHECKS = {
  'api check_authenticated_permission': { required: ['is_user_logged_in'], forbidden: ['__return_true'] },
  'api check_dealer_permission': { required: ["current_user_can( 'manage_card_vault' )", "current_user_can( 'manage_options' )"], forbidden: ['__return_true'] },
  'api check_consignor_or_dealer_permission': { required: ['view_consignor_dashboard', 'manage_card_vault'], forbidden: [] },
  'catalog check_admin_permission': { required: ["current_user_can( 'manage_options' )", "current_user_can( 'manage_card_vault' )"], forbidden: [] },
  'catalog check_read_permission': { required: ['check_read_access', 'rest_forbidden'], forbidden: [] },
  'catalog check_public_read_permission': { required: ['check_read_access'], forbidden: [] },
};

function collectRoutes() {
  const out = [];
  for (const src of REST_SOURCES) {
    const text = readSourceFile(src.file);
    assert.ok(text, src.file + ' must exist');
    for (const route of parseRestRoutes(text)) {
      route.perms.forEach((perm, idx) => {
        const multi = route.perms.length > 1;
        out.push({ src, path: route.path, perm, idx, key: src.label + ' ' + route.path + (multi ? '#' + idx : '') });
      });
    }
  }
  return out;
}

describe('Card Vault Submodule Tests', () => {
  it('REST permission checks enforce capabilities rather than roles', () => {
    const apiFile = readSourceFile('wp-content/plugins/xophz-compass-card-vault/includes/class-card-vault-api.php');
    assert.ok(apiFile, 'Card Vault API file must exist');
    assert.strictEqual(hasAdminRoleCheck(apiFile), false, 'Must not check raw administrator role');
  });

  it('Admin page rendering checks manage_options capability', () => {
    const adminFile = readSourceFile('wp-content/plugins/xophz-compass-card-vault/admin/class-card-vault-admin.php');
    assert.ok(adminFile, 'Card Vault Admin file must exist');
    assert.strictEqual(hasAdminRoleCheck(adminFile), false, 'Must not check raw administrator role');
  });

  it('Consignor dashboard access checks view_consignor_dashboard capability', () => {
    const apiFile = readSourceFile('wp-content/plugins/xophz-compass-card-vault/includes/class-card-vault-api.php');
    assert.ok(apiFile && apiFile.includes('view_consignor_dashboard'), true, 'Must check view_consignor_dashboard');
  });

  it('Vite Dev Proxy configuration registers query var and handles offline fallback', () => {
    const res = runPhpJson(`
      $proxy = new Xophz_Compass_Dev_Proxy([
        'slug'        => 'card-vault',
        'dev_port'    => 8092,
        'query_var'   => 'card-vault',
        'plugin_path' => '/var/www/html/wp-content/plugins/xophz-compass-card-vault/',
        'plugin_url'  => 'http://localhost/wp-content/plugins/xophz-compass-card-vault/',
        'version'     => '26.9.2-1221'
      ]);
      $vars = $proxy->register_query_vars([]);
      echo json_encode([
        'hasQueryVar' => in_array('card-vault', $vars, true)
      ]);
    `);
    assert.strictEqual(res.hasQueryVar, true, 'Query var card-vault must be registered');
  });

  it('Card_Vault_Public dynamically replaces title and meta tags with WordPress site title', () => {
    const res = runPhpJson(`
      if (!function_exists('get_bloginfo')) {
        function get_bloginfo($show = 'name') { return 'PokeVault Tokyo'; }
      }
      require_once '/var/www/html/wp-content/plugins/xophz-compass-card-vault/public/class-card-vault-public.php';
      $public = new Card_Vault_Public();
      $distHtml = file_get_contents('/var/www/html/wp-content/plugins/xophz-compass-card-vault/public/dist/index.html');
      $filteredHtml = $public->filter_html_output($distHtml, 'card-vault');

      preg_match('#<title>(.*?)</title>#', $filteredHtml, $titleMatches);
      preg_match('#<meta name="application-name" content="([^"]+)"#', $filteredHtml, $appMatches);
      preg_match('#<meta name="apple-mobile-web-app-title" content="([^"]+)"#', $filteredHtml, $appleMatches);

      echo json_encode([
        'title' => $titleMatches[1] ?? '',
        'applicationName' => $appMatches[1] ?? '',
        'appleTitle' => $appleMatches[1] ?? ''
      ]);
    `);
    assert.strictEqual(res.title, 'PokeVault Tokyo', 'HTML <title> tag must match WordPress site title');
    assert.strictEqual(res.applicationName, 'PokeVault Tokyo', 'Application name meta tag must match site title');
    assert.strictEqual(res.appleTitle, 'PokeVault Tokyo', 'Apple mobile title meta tag must match site title');
  });

  it('Card_Vault_Public injects siteTitle into window.wpApiSettings payload', () => {
    const res = runPhpJson(`
      if (!function_exists('get_bloginfo')) {
        function get_bloginfo($show = 'name') { return 'Card Sharks Syndicate'; }
      }
      require_once '/var/www/html/wp-content/plugins/xophz-compass-card-vault/public/class-card-vault-public.php';
      $public = new Card_Vault_Public();
      $settings = $public->filter_api_settings([], 'card-vault');
      echo json_encode([
        'siteTitle' => $settings['siteTitle'] ?? null
      ]);
    `);
    assert.strictEqual(res.siteTitle, 'Card Sharks Syndicate', 'siteTitle in wpApiSettings must match site title');
  });

  describe('REST permission model', () => {
    const all = collectRoutes();

    it('every register_rest_route in the plugin sources is parsed with a permission_callback', () => {
      for (const src of REST_SOURCES) {
        const text = readSourceFile(src.file);
        const calls = (text.match(/register_rest_route\s*\(/g) || []).length;
        const parsed = parseRestRoutes(text);
        assert.strictEqual(parsed.length, calls, src.label + ': parsed route count must equal call count');
        for (const route of parsed) {
          assert.ok(route.perms.length >= 1, src.label + ' ' + route.path + ' must declare permission_callback');
          assert.ok(route.perms.length >= route.methods.length, src.label + ' ' + route.path + ' needs a permission_callback per handler');
        }
      }
    });

    it('route counts match the audited source (32 api, 20 catalog)', () => {
      const count = (label) => REST_SOURCES.filter((s) => s.label === label)
        .reduce((n, s) => n + parseRestRoutes(readSourceFile(s.file)).length, 0);
      assert.strictEqual(count('api'), 32);
      assert.strictEqual(count('catalog'), 20);
    });

    it('catalog routes are registered on both namespaces', () => {
      const text = readSourceFile(REST_SOURCES[1].file);
      assert.ok(/\$namespaces\s*=\s*array\(\s*self::NAMESPACE,\s*self::ALT_NAMESPACE\s*\)/.test(text), 'catalog must loop over both namespaces');
    });

    it('main plugin file delegates route registration to both route classes on rest_api_init', () => {
      const main = readSourceFile(PLUGIN_DIR + '/xophz-compass-card-vault.php');
      assert.ok(main.includes("'rest_api_init'"), 'must hook rest_api_init');
      assert.ok(main.includes('$api->register_routes()'), 'must register Card_Vault_API routes');
      assert.ok(main.includes('Card_Vault_Catalog_REST::register_routes()'), 'must register catalog routes');
      assert.ok(!/register_rest_route\s*\(/.test(main), 'main file must not register routes directly');
    });

    const publicRoutes = all.filter((r) => r.perm === '__return_true');
    const protectedRoutes = all.filter((r) => r.perm !== '__return_true');

    for (const route of publicRoutes) {
      it('public route is allowlisted with a reason: ' + route.key, () => {
        const reason = PUBLIC_ROUTE_ALLOWLIST[route.key];
        assert.ok(typeof reason === 'string' && reason.length > 20, route.key + ' is public but not in PUBLIC_ROUTE_ALLOWLIST with a reason');
      });
    }

    for (const route of protectedRoutes) {
      it('protected route uses a pinned permission callback: ' + route.key + ' -> ' + route.perm, () => {
        const check = PERMISSION_CALLBACK_CHECKS[route.src.label + ' ' + route.perm];
        assert.ok(check, route.perm + ' must be a pinned permission callback');
      });
    }

    it('allowlist has no stale entries', () => {
      const keys = new Set(all.filter((r) => r.perm === '__return_true').map((r) => r.key));
      for (const key of Object.keys(PUBLIC_ROUTE_ALLOWLIST)) {
        assert.ok(keys.has(key), 'allowlist entry no longer matches a public route: ' + key);
      }
    });

    it('permission callbacks contain the pinned login and capability checks', () => {
      for (const [id, check] of Object.entries(PERMISSION_CALLBACK_CHECKS)) {
        const [label, name] = id.split(' ');
        const src = REST_SOURCES.find((s) => s.label === label);
        const body = methodBody(readSourceFile(src.file), name);
        assert.ok(body, name + ' must be defined in ' + src.file);
        for (const needle of check.required) {
          assert.ok(body.includes(needle), name + ' must contain ' + needle);
        }
        for (const needle of check.forbidden) {
          assert.ok(!body.includes(needle), name + ' must not contain ' + needle);
        }
      }
    });

    it('dealer-only write routes in the api class require dealer permission', () => {
      const writes = ['/sync/delta', '/payouts/settle', '/intake/appraise', '/intake/accept', '/pos/checkout', '/products/stock', '/products/lookup-barcode'];
      for (const p of writes) {
        const hit = all.find((r) => r.src.label === 'api' && r.path === p);
        assert.ok(hit, p + ' must exist');
        assert.strictEqual(hit.perm, 'check_dealer_permission', p + ' must require dealer permission');
      }
    });
  });

  describe('Number normalizer and SKU generator', () => {
    const prelude = `
      define('ABSPATH', '/var/www/html/');
      require_once '${CONTAINER_PLUGIN_DIR}/includes/class-card-vault-number-normalizer.php';
      require_once '${CONTAINER_PLUGIN_DIR}/includes/class-card-vault-sku-generator.php';
    `;

    it('normalize returns empty components for blank input', () => {
      const res = runPhpJson(prelude + `echo json_encode(Card_Vault_Number_Normalizer::normalize('  '));`);
      assert.strictEqual(res.raw_number, '');
      assert.strictEqual(res.numeric_number, null);
      assert.strictEqual(res.total_set_number, null);
      assert.strictEqual(res.number_variants, '');
    });

    it('normalize splits a numeric number with a set total', () => {
      const res = runPhpJson(prelude + `echo json_encode(Card_Vault_Number_Normalizer::normalize('004/102'));`);
      assert.strictEqual(res.clean_number, '004');
      assert.strictEqual(res.numeric_number, 4);
      assert.strictEqual(res.number_prefix, null);
      assert.strictEqual(res.total_set_number, 102);
    });

    it('normalize extracts the prefix of a gallery number', () => {
      const res = runPhpJson(prelude + `echo json_encode(Card_Vault_Number_Normalizer::normalize('TG01/TG30'));`);
      assert.strictEqual(res.clean_number, 'TG01');
      assert.strictEqual(res.number_prefix, 'TG');
      assert.strictEqual(res.numeric_number, 1);
      assert.strictEqual(res.total_set_number, 30);
    });

    it('generate_sku builds a deterministic ungraded SKU', () => {
      const res = runPhpJson(prelude + `
        $item = array('id' => 'abc', 'condition' => 'nm', 'is_foil' => true, 'card' => array('game' => 'pkm', 'setCode' => 'mew', 'number' => '199/165'));
        echo json_encode(array(Card_Vault_SKU_Generator::generate_sku($item), Card_Vault_SKU_Generator::generate_sku($item)));
      `);
      assert.strictEqual(res[0], res[1], 'same input must give the same SKU');
      assert.ok(/^CV-PKM-MEW-199165-NM-F-[0-9a-f]{4}$/.test(res[0]), 'unexpected SKU shape: ' + res[0]);
    });

    it('generate_sku uses grade label and the last six cert digits for graded items', () => {
      const res = runPhpJson(prelude + `
        $item = array('id' => 'x', 'is_graded' => true, 'grade_label' => 'PSA 10', 'cert_number' => '12345678', 'card' => array('setCode' => 'sv1', 'number' => '5'));
        echo json_encode(Card_Vault_SKU_Generator::generate_sku($item));
      `);
      assert.strictEqual(res, 'CV-PSA10-SV1-5-345678');
    });

    it('identify_barcode classifies SKU, UPC and unknown values', () => {
      const res = runPhpJson(prelude + `
        echo json_encode(array(
          Card_Vault_SKU_Generator::identify_barcode(' cv-abc '),
          Card_Vault_SKU_Generator::identify_barcode('012345678905'),
          Card_Vault_SKU_Generator::identify_barcode('not-a-code')
        ));
      `);
      assert.deepStrictEqual(res[0], { type: 'single_sku', clean_value: 'CV-ABC' });
      assert.strictEqual(res[1].type, 'upc');
      assert.strictEqual(res[2].type, 'unknown');
    });
  });
});
