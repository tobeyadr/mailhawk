# Running the tests

The tests are integration tests on WordPress, run with PHPUnit 9 and the Yoast PHPUnit Polyfills (WordPress 7 does not work with PHPUnit 8).

You need:

- the WordPress test library (`wordpress-tests-lib`, from `bin/install-wp-tests.sh` or from the `wordpress-develop` repository) and a throwaway database it can drop tables in, never the database of a site you care about. Point `WP_TESTS_DIR` at the library.
- `WP_TESTS_PHPUNIT_POLYFILLS_PATH` set to the folder of the polyfills.
- PHP with `mysqli`, `mbstring` and `openssl`.

```bash
WP_TESTS_DIR=/path/to/wordpress-tests-lib \
WP_TESTS_PHPUNIT_POLYFILLS_PATH=/path/to/PHPUnit-Polyfills \
php phpunit.phar -c phpunit.xml.dist
```

Nothing here talks to the MailHawk service. The tests that send an email answer the request to the MTA themselves with the `pre_http_request` filter, so a test that sends for real fails instead of mailing someone. Keep it that way.

On Windows, PHP can not make a key without an `openssl.cnf`. The webhook test makes its own empty one when it needs to.
