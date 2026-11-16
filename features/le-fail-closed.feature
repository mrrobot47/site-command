@le
Feature: A failed Let's Encrypt issuance leaves the site on plain HTTP
  When the www name fails validation, the site must not be served over HTTPS with another site's certificate, and a later
  `update --ssl=le` must issue a certificate with www and the HTTPS www redirect. The www validation is made to fail by
  rejecting the ACME server's HTTP-01 requests for the www name only; the site's own www check is not affected. A self-signed
  certificate for the parent domain stands in for the closest matching certificate nginx-proxy would otherwise serve.

  Background:
    Given the EasyEngine config value "le-mail" is set
    And the variable "LF1" is "bh-site-command-lf1-{RUN_ID}.ee4-test.rt.gw"
    And the variable "LF2" is "bh-site-command-lf2-{RUN_ID}.ee4-test.rt.gw"
    And the variable "LF3" is "bh-site-command-lf3-{RUN_ID}.ee4-test.rt.gw"
    And the variable "LF4" is "bh-site-command-lf4-{RUN_ID}.ee4-test.rt.gw"
    And the variable "MATCH" is "-p tcp --dport 80 -m string --algo bm --string /.well-known/acme-challenge/ -m string --algo bm --icase --string"
    And the variable "REJECT" is "-j REJECT --reject-with tcp-reset"
    # The certs dir only exists once the global proxy has started (the first site create on a fresh host).
    When I run 'test -d {PROXY_DIR}/certs/ || bin/ee service enable nginx-proxy'
    Then the command should succeed
    When I run 'test -f {PROXY_DIR}/certs/ee4-test.rt.gw.crt || openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=ee4-test.rt.gw" -keyout {PROXY_DIR}/certs/ee4-test.rt.gw.key -out {PROXY_DIR}/certs/ee4-test.rt.gw.crt'
    Then the command should succeed

  Scenario: A create whose www validation fails stays HTTP-only, and a later update issues the certificate with the www redirect
    Given the site "{LF1}" does not exist
    When I run 'for c in INPUT DOCKER-USER; do iptables -I $c {MATCH} "Host: www.{LF1}" {REJECT} || exit 1; done'
    Then the command should succeed
    When I run 'bin/ee site create {LF1} --ssl=le'
    Then the command should succeed
    And STDERR should contain "SSL could not be enabled on {LF1}, so it is served over HTTP only."
    And the "site_ssl" field of site "{LF1}" in the database should be ""
    And the file "{SITES_DIR}/{LF1}/docker-compose.yml" should contain "HTTPS_METHOD=nohttps"
    And the file "{PROXY_DIR}/certs/{LF1}.crt" should not exist
    And the file "{PROXY_DIR}/conf.d/{LF1}-redirect.conf" should contain "return  301 http://{LF1}$request_uri;"
    And the file "{PROXY_DIR}/conf.d/{LF1}-redirect.conf" should not contain "443"
    When I wait up to 60 seconds for "http://{LF1}/" to return 200
    And I send a GET request to "http://{LF1}/"
    Then the response body should contain "Your html site with EasyEngine"
    When I send a GET request to "http://www.{LF1}/"
    Then the response code should be 301
    And the response header "Location" should be "http://{LF1}/"
    # Without HTTPS for the site, nginx-proxy answers its name on 443 with the default certificate (or rejects the handshake) and 503 (500 before nginx-proxy v4.14.0).
    When I send a GET request to "https://{LF1}/"
    Then the response code should be 503
    And the response body should not contain "Your html site with EasyEngine"
    When I run 'for c in INPUT DOCKER-USER; do iptables -D $c {MATCH} "Host: www.{LF1}" {REJECT}; done'
    And I run 'bin/ee site update {LF1} --ssl=le'
    Then the command should succeed
    And STDOUT should contain "Enabled SSL for {LF1}"
    And the "site_ssl" field of site "{LF1}" in the database should be "le"
    And the file "{SITES_DIR}/{LF1}/docker-compose.yml" should not contain "HTTPS_METHOD=nohttps"
    When I wait up to 60 seconds for "https://{LF1}/" to return 200
    Then the TLS certificate served for "{LF1}" should cover "www.{LF1}"
    And the TLS certificate served for "{LF1}" should be issued by "{LE_ISSUER}"
    And the file "{PROXY_DIR}/conf.d/{LF1}-redirect.conf" should contain "listen  443;"
    When I send a GET request to "http://www.{LF1}/"
    Then the response code should be 301
    And the response header "Location" should be "https://{LF1}/"
    When I run 'docker exec services_global-nginx-proxy_1 nginx -T 2>/dev/null | grep -n -A6 "server_name  www.{LF1}" >> {EE_ROOT_DIR}/logs/ee.log; ls -l {PROXY_DIR}/certs/ >> {EE_ROOT_DIR}/logs/ee.log'
    Then the TLS certificate served for "www.{LF1}" should cover "www.{LF1}" within 60 seconds
    When I send a verified GET request to "https://www.{LF1}/"
    Then the response code should be 301
    And the response header "Location" should be "https://{LF1}/"

  Scenario: Enabling Let's Encrypt on a site already redirected to HTTPS with another certificate keeps the www redirect
    Given the html site "{LF2}" exists
    # The state an earlier failed create left behind: no nohttps, so nginx-proxy redirects to HTTPS with the closest certificate.
    When I run 'sed -i "/HTTPS_METHOD=nohttps/d" {SITES_DIR}/{LF2}/docker-compose.yml && bin/ee site enable {LF2} --force'
    Then the command should succeed
    When I wait up to 60 seconds for "http://{LF2}/" to return 301
    And I send a GET request to "http://{LF2}/"
    Then the response header "Location" should be "https://{LF2}/"
    And the TLS certificate served for "{LF2}" should not cover "{LF2}"
    When I run 'bin/ee site update {LF2} --ssl=le'
    Then the command should succeed
    And the "site_ssl" field of site "{LF2}" in the database should be "le"
    When I wait up to 60 seconds for "https://{LF2}/" to return 200
    Then the TLS certificate served for "{LF2}" should cover "www.{LF2}"
    And the file "{PROXY_DIR}/conf.d/{LF2}-redirect.conf" should contain "listen  443;"
    When I run 'docker exec services_global-nginx-proxy_1 nginx -T 2>/dev/null | grep -n -A6 "server_name  www.{LF2}" >> {EE_ROOT_DIR}/logs/ee.log; ls -l {PROXY_DIR}/certs/ >> {EE_ROOT_DIR}/logs/ee.log'
    Then the TLS certificate served for "www.{LF2}" should cover "www.{LF2}" within 60 seconds
    When I send a verified GET request to "https://www.{LF2}/"
    Then the response code should be 301
    And the response header "Location" should be "https://{LF2}/"

  @wp
  Scenario: A WordPress create whose www validation fails stays HTTP-only with http URLs until a later update issues the certificate
    Given the site "{LF3}" does not exist
    When I run 'for c in INPUT DOCKER-USER; do iptables -I $c {MATCH} "Host: www.{LF3}" {REJECT} || exit 1; done'
    And I run 'bin/ee site create {LF3} --type=wp --ssl=le'
    Then the command should succeed
    And STDERR should contain "SSL could not be enabled on {LF3}, so it is served over HTTP only. Once the issue is fixed, enable SSL with `ee site update {LF3} --ssl=le`."
    And STDERR should not contain "ssl-verify"
    And STDOUT should not contain "ssl-verify"
    And the "site_ssl" field of site "{LF3}" in the database should be ""
    And the file "{SITES_DIR}/{LF3}/docker-compose.yml" should contain "HTTPS_METHOD=nohttps"
    And the file "{PROXY_DIR}/conf.d/{LF3}-redirect.conf" should not contain "443"
    When I send a GET request to "https://{LF3}/"
    Then the response code should be 503
    # WordPress was installed with https:// URLs before the certificate step; they are switched back to http.
    When I run 'bin/ee shell {LF3} --skip-tty --command="wp option get home; wp option get siteurl"'
    Then the command should succeed
    And STDOUT should contain "http://{LF3}"
    And STDOUT should not contain "https://"
    When I wait up to 60 seconds for "http://{LF3}/" to return 200
    And I send a GET request to "http://{LF3}/wp-login.php"
    Then the response code should be 200
    When I run 'for c in INPUT DOCKER-USER; do iptables -D $c {MATCH} "Host: www.{LF3}" {REJECT}; done'
    And I run 'bin/ee site update {LF3} --ssl=le'
    Then the command should succeed
    And STDOUT should contain "Enabled SSL for {LF3}"
    And STDOUT should contain "Successfully completed search-replace."
    And the "site_ssl" field of site "{LF3}" in the database should be "le"
    And the file "{SITES_DIR}/{LF3}/docker-compose.yml" should not contain "HTTPS_METHOD=nohttps"
    When I run 'bin/ee shell {LF3} --skip-tty --command="wp option get home; wp option get siteurl"'
    Then STDOUT should contain "https://{LF3}"
    And STDOUT should not contain "http://{LF3}"
    When I wait up to 60 seconds for "https://{LF3}/" to return 200
    Then the TLS certificate served for "{LF3}" should cover "www.{LF3}"
    And the TLS certificate served for "{LF3}" should be issued by "{LE_ISSUER}"
    And the file "{PROXY_DIR}/conf.d/{LF3}-redirect.conf" should contain "listen  443;"
    When I send a GET request to "http://{LF3}/"
    Then the response code should be 301
    And the response header "Location" should be "https://{LF3}/"
    When I send a GET request to "http://www.{LF3}/"
    Then the response code should be 301
    And the response header "Location" should be "https://{LF3}/"

  # The DNS-01 records are published in the Pebble harness's DNS server by hand, as a user would at their DNS provider.
  @wp
  Scenario: A manual DNS-01 WordPress create stays HTTP-only until ssl-verify issues the certificate
    Given the site "{LF4}" does not exist
    And the EasyEngine config value "cloudflare-api-key" is not set
    When I run 'bin/ee config set preferred_ssl_challenge dns'
    Then the command should succeed
    When I run 'bin/ee site create {LF4} --type=wp --ssl=le'
    Then the command should succeed
    And STDOUT should contain "Run `ee site ssl-verify {LF4}` once the DNS changes have propagated"
    And STDOUT should contain "{LF4} is served over HTTP until `ee site ssl-verify {LF4}` succeeds."
    And the "site_ssl" field of site "{LF4}" in the database should be "le"
    And the file "{SITES_DIR}/{LF4}/docker-compose.yml" should contain "HTTPS_METHOD=nohttps"
    And the file "{PROXY_DIR}/certs/{LF4}.crt" should not exist
    And the file "{PROXY_DIR}/conf.d/{LF4}-redirect.conf" should not contain "443"
    When I run 'bin/ee shell {LF4} --skip-tty --command="wp option get home; wp option get siteurl"'
    Then STDOUT should contain "http://{LF4}"
    And STDOUT should not contain "https://"
    When I wait up to 60 seconds for "http://{LF4}/" to return 200
    And I send a GET request to "https://{LF4}/"
    Then the response code should be 503
    When I run 'bin/ee site update {LF4} --ssl=le'
    Then the command should fail
    And STDERR should contain "Run `ee site ssl-verify {LF4}` once the DNS records have propagated."
    When I run 'bin/ee site ssl-info {LF4} --get-dns-records --format=json > {TMP}/lf4-dns.json && "$EE_BEHAT_PEBBLE_SETUP" txt-json {TMP}/lf4-dns.json'
    Then the command should succeed
    When I run 'bin/ee site ssl-verify {LF4}'
    Then the command should succeed
    And STDOUT should contain "HTTPS is enabled for {LF4}."
    And the "site_ssl" field of site "{LF4}" in the database should be "le"
    And the file "{SITES_DIR}/{LF4}/docker-compose.yml" should not contain "HTTPS_METHOD=nohttps"
    When I wait up to 60 seconds for "https://{LF4}/" to return 200
    Then the TLS certificate served for "{LF4}" should cover "www.{LF4}"
    And the TLS certificate served for "{LF4}" should be issued by "{LE_ISSUER}"
    And the file "{PROXY_DIR}/conf.d/{LF4}-redirect.conf" should contain "listen  443;"
    When I send a GET request to "http://{LF4}/"
    Then the response code should be 301
    And the response header "Location" should be "https://{LF4}/"
    When I run 'bin/ee shell {LF4} --skip-tty --command="wp option get home; wp option get siteurl"'
    Then STDOUT should contain "https://{LF4}"
    And STDOUT should not contain "http://{LF4}"

  Scenario: Remove the stand-in certificate and the DNS-01 preference
    When I run 'rm -f {PROXY_DIR}/certs/ee4-test.rt.gw.crt {PROXY_DIR}/certs/ee4-test.rt.gw.key'
    Then the command should succeed
    When I run 'bin/ee config unset preferred_ssl_challenge'
    Then the EasyEngine config value "preferred_ssl_challenge" is not set
