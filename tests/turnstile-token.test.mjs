/**
 * Turnstile token type handling for the login and register handlers.
 * Zero mock or synthetic data: stubs only supply the configured secret and the request body.
 */

import { describe, it } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';
import assert from 'node:assert';

const CONTAINER_PLUGIN_DIR = '/var/www/html/wp-content/plugins/xophz-compass-card-vault';

const runHandler = (handler, tokenPhp) => runPhpJson(`
  function users_can_register_stub() {}
  class WP_REST_Response { public $data; public $status; public function __construct( $d = null, $s = 200 ) { $this->data = $d; $this->status = $s; } }
  class Card_Vault_Connectors {
    const TURNSTILE_SITE_KEY = 'a';
    const TURNSTILE_SECRET_KEY = 'b';
    public static function get_key( $k ) { return 'configured'; }
  }
  class Stub_Request { public $p; public function __construct( $p ) { $this->p = $p; } public function get_json_params() { return $this->p; } }
  require_once '${CONTAINER_PLUGIN_DIR}/includes/class-card-vault-api.php';
  $api = new Card_Vault_API();
  try {
    $r = $api->${handler}( new Stub_Request( array( 'username' => 'u', 'email' => 'u@example.invalid', 'password' => 'p', 'turnstileToken' => ${tokenPhp} ) ) );
    echo json_encode( array( 'status' => $r->status, 'error' => null ) );
  } catch ( Throwable $e ) {
    echo json_encode( array( 'status' => null, 'error' => get_class( $e ) ) );
  }
`);

describe('Turnstile token type handling', () => {
  for (const handler of ['handle_auth_login', 'handle_auth_register']) {
    it(`${handler} rejects an array-valued token without a TypeError`, () => {
      const res = runHandler(handler, "array( 'x' )");
      assert.strictEqual(res.error, null);
      assert.ok(res.status >= 400 && res.status < 500);
    });
  }
});
