Feature: Update SSL and PHP of html sites
  `ee site update --ssl[=...]`, `--wildcard` and `--php` on html sites. Let's Encrypt paths are in le.feature.

  Scenario: Enable self-signed SSL on a plain site
    Given the html site "bh-site-command-70.test" exists
    When I run 'bin/ee site update bh-site-command-70.test --ssl=self'
    Then the command should succeed
    And STDOUT should contain "Starting SSL update for: bh-site-command-70.test"
    And STDOUT should contain "Enabled SSL for bh-site-command-70.test"
    And the "site_ssl" field of site "bh-site-command-70.test" in the database should be "self"
    And the file "{PROXY_DIR}/certs/bh-site-command-70.test.crt" should exist
    And the file "{PROXY_DIR}/certs/bh-site-command-70.test.key" should exist
    When I wait up to 60 seconds for "https://bh-site-command-70.test/" to return 200
    Then the response body should contain "Your html site with EasyEngine"
    And the TLS certificate served for "bh-site-command-70.test" should cover "bh-site-command-70.test"
    When I send a GET request to "http://bh-site-command-70.test/"
    Then the response code should be 301
    And the response header "Location" should be "https://bh-site-command-70.test/"

  Scenario: Enabling SSL twice fails
    Given the html site "bh-site-command-72.test" exists
    When I run 'bin/ee site update bh-site-command-72.test --ssl=self'
    Then the command should succeed
    When I run 'bin/ee site update bh-site-command-72.test --ssl=self'
    Then the command should fail
    And STDERR should contain "SSL is already enabled on bh-site-command-72.test"

  Scenario: Disable SSL
    Given the html site "bh-site-command-73.test" exists
    When I run 'bin/ee site update bh-site-command-73.test --ssl=self'
    Then the command should succeed
    When I run 'bin/ee site update bh-site-command-73.test --ssl=off'
    Then the command should succeed
    And STDOUT should contain "Disabled SSL for bh-site-command-73.test"
    And the "site_ssl" field of site "bh-site-command-73.test" in the database should not be "self"
    And the service "nginx" of site "bh-site-command-73.test" should have the environment variable "HTTPS_METHOD" containing "nohttps"
    When I wait up to 60 seconds for "http://bh-site-command-73.test/" to return 200
    Then the response body should contain "Your html site with EasyEngine"

  Scenario: Disabling SSL on a plain site fails
    Given the html site "bh-site-command-74.test" exists
    When I run 'bin/ee site update bh-site-command-74.test --ssl=off'
    Then the command should fail
    And STDERR should contain "SSL is already disabled on bh-site-command-74.test"

  Scenario: Switching a normal site to wildcard SSL is refused
    Given the html site "bh-site-command-74.test" exists
    When I run 'bin/ee site update bh-site-command-74.test --ssl=self --wildcard'
    Then the command should fail
    And STDERR should contain "Update from normal SSL to wildcard SSL is not supported yet."
    And the "site_ssl" field of site "bh-site-command-74.test" in the database should be ""

  Scenario: An unknown SSL type is rejected
    Given the html site "bh-site-command-74.test" exists
    When I run 'bin/ee site update bh-site-command-74.test --ssl=bh-bogus'
    Then the command should fail
    And STDERR should contain "Invalid value specified for 'ssl'"

  Scenario: --wildcard cannot be combined with --ssl=off
    Given the site "bh-site-command-71.test" exists, created with "--type=html --ssl=self"
    When I run 'bin/ee site update bh-site-command-71.test --ssl=off --wildcard'
    Then the command should fail
    And STDERR should contain "You cannot use --wildcard flag with --ssl=off"

  Scenario: Inherit SSL from a self-signed parent through update
    Given the site "bh-site-command-76.test" exists, created with "--type=html --ssl=self"
    And the html site "sub.bh-site-command-76.test" exists
    When I run 'bin/ee site update sub.bh-site-command-76.test --ssl=inherit'
    Then the command should succeed
    And the "site_ssl" field of site "sub.bh-site-command-76.test" in the database should be "inherit"
    When I wait up to 60 seconds for "https://sub.bh-site-command-76.test/" to return 200
    Then the TLS certificate served for "sub.bh-site-command-76.test" should cover "sub.bh-site-command-76.test"

  # --ssl=off on the parent would remove the certificate that the child's www redirect loads, and nginx could not load its config.
  Scenario: SSL can't be turned off on a parent whose certificate is inherited
    Given the site "bh-site-command-76.test" exists, created with "--type=html --ssl=self"
    And the html site "sub.bh-site-command-76.test" exists
    When I run 'bin/ee site update sub.bh-site-command-76.test --ssl=inherit'
    And I run 'bin/ee site update bh-site-command-76.test --ssl=off'
    Then the command should fail
    And STDERR should contain "Cannot disable SSL on bh-site-command-76.test: sub.bh-site-command-76.test inherit its certificate."
    And the "site_ssl" field of site "bh-site-command-76.test" in the database should be "self"
    And the file "{PROXY_DIR}/certs/bh-site-command-76.test.crt" should exist

  Scenario: Changing the PHP version of an html site fails and leaves it serving
    Given the html site "bh-site-command-77.test" exists
    When I run 'bin/ee site update bh-site-command-77.test --php=8.3'
    Then the command should fail
    And STDERR should contain "Error:"
    And the "php_version" field of site "bh-site-command-77.test" in the database should be ""
    When I wait up to 60 seconds for "http://bh-site-command-77.test/" to return 200

  Scenario: An unsupported PHP version is rejected
    Given the html site "bh-site-command-77.test" exists
    When I run 'bin/ee site update bh-site-command-77.test --php=9.9'
    Then the command should fail
    And STDERR should contain "Invalid value specified for 'php'"

  # SC-3 (fixed on fix/proxy-cache-rollback-and-le-alias): --ssl=off was refused for sites stored with site_ssl_wildcard=1, which create --ssl=self sets.
  # WPT-12: --ssl=off also removes the certificate (the www redirect's HTTPS block is only written when www resolves, so it isn't checked here).
  Scenario: SSL can be disabled on a site created with self-signed SSL
    Given the site "bh-site-command-71.test" exists, created with "--type=html --ssl=self"
    When I run 'bin/ee site update bh-site-command-71.test --ssl=off'
    Then the command should succeed
    And STDOUT should contain "Disabled SSL for bh-site-command-71.test"
    And the "site_ssl" field of site "bh-site-command-71.test" in the database should not be "self"
    And the file "{PROXY_DIR}/certs/bh-site-command-71.test.crt" should not exist
    And the file "{PROXY_DIR}/certs/bh-site-command-71.test.key" should not exist
    When I run 'docker exec services_global-nginx-proxy_1 nginx -t'
    Then the command should succeed
    When I wait up to 60 seconds for "http://bh-site-command-71.test/" to return 200

  # SC-3: create --ssl=self stores site_ssl_wildcard=1, update --ssl=self saves 0 (init_ssl() only changes the array copy). No open PR.
  @known-bug
  Scenario: Enabling self-signed SSL through update records it as wildcard like create does
    Given the html site "bh-site-command-75.test" exists
    When I run 'bin/ee site update bh-site-command-75.test --ssl=self'
    Then the command should succeed
    And the "site_ssl_wildcard" field of site "bh-site-command-75.test" in the database should be "1"

  # SC-4: update --ssl=inherit skips check_parent_site_certs(), which create runs, so a site can "inherit" from a parent that does not exist. No open PR.
  @known-bug
  Scenario: Inheriting SSL through update requires a wildcard parent
    Given the site "bh-site-command-78.test" does not exist
    And the html site "sub.bh-site-command-78.test" exists
    When I run 'bin/ee site update sub.bh-site-command-78.test --ssl=inherit'
    Then the command should fail
    And STDERR should contain "Unable to find existing site: bh-site-command-78.test"
    And the "site_ssl" field of site "sub.bh-site-command-78.test" in the database should be ""

  # SC-13: update_php() never checks the site type; on html it mkdirs .backup/config/php- and then dies on mirror() of the
  # missing config/php with Symfony's raw "The origin directory specified ... was not found." No open PR.
  @known-bug
  Scenario: Changing the PHP version of an html site is refused up front without leftovers
    Given the html site "bh-site-command-79.test" exists
    When I run 'bin/ee site update bh-site-command-79.test --php=8.3'
    Then the command should fail
    And STDERR should not contain "The origin directory specified"
    And the directory "{SITES_DIR}/bh-site-command-79.test/.backup" should not exist

  # SC-15: update has no --ssl-key/--ssl-crt, so --ssl=custom can only be set at create time. PR #489 adds both options.
  @known-bug
  Scenario: Enable custom SSL on an existing site
    Given the html site "bh-site-command-108.test" exists
    When I run "openssl req -x509 -newkey rsa:2048 -nodes -days 30 -keyout {TMP}/bh.key -out {TMP}/bh.crt -subj '/CN=bh-site-command-108.test/O=bh-site-command-custom' -addext 'subjectAltName=DNS:bh-site-command-108.test'"
    Then the command should succeed
    When I run 'bin/ee site update bh-site-command-108.test --ssl=custom --ssl-key={TMP}/bh.key --ssl-crt={TMP}/bh.crt'
    Then the command should succeed
    And the "site_ssl" field of site "bh-site-command-108.test" in the database should be "custom"
    And the file "{PROXY_DIR}/certs/bh-site-command-108.test.crt" should be identical to the file "{TMP}/bh.crt"
    When I wait up to 60 seconds for "https://bh-site-command-108.test/" to return 200
    Then the TLS certificate served for "bh-site-command-108.test" should be issued by "bh-site-command-custom"
