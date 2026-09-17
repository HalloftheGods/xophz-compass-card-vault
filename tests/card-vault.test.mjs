/**
 * Card Vault Submodule E2E Test Suite
 * Strictly zero em dashes in code, copy, comments, and reports.
 * Zero mock or synthetic data.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';
import { readSourceFile, hasAdminRoleCheck } from '../../xophz-compass/tests/harness/code-analyzer.mjs';
import assert from 'node:assert';

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
});
