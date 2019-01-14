#!/usr/bin/env bash
function setup_test_requirements() {
    readonly LOG_FILE="/opt/easyengine/logs/install.log"
    # Adding software-properties-common for add-apt-repository.
    apt-get install -y software-properties-common
    # Adding ondrej/php repository for installing php, this works for all ubuntu flavours.
    add-apt-repository -y ppa:ondrej/php
    apt-get update
    # Installing php-cli, which is the minimum requirement to run EasyEngine
    apt-get -y install php7.2-cli

    php_modules=( pcntl curl sqlite3 )
    if command -v php > /dev/null 2>&1; then
      # Reading the php version.
      default_php_version="$(readlink -f /usr/bin/php | gawk -F "php" '{ print $2}')"
      for module in "${php_modules[@]}"; do
        if ! php -m | grep $module >> $LOG_FILE 2>&1; then
          echo "$module not installed. Installing..."
          apt install -y php$default_php_version-$module
        else
          echo "$module is already installed"
        fi
      done
    fi
}

get_latest_release() {
      curl --silent "https://api.github.com/repos/$1/releases/latest" | # Get latest release from GitHub api
        grep '"tag_name":' | # Get tag line
        sed -E 's/.*"([^"]+)".*/\1/' # Pluck JSON value
    }

ARTIFACT_URL="https://github.com/docker/compose/releases/download/$(get_latest_release docker/compose)/docker-compose-$(uname -s)-$(uname -m)"
curl -L $ARTIFACT_URL -o /usr/local/bin/docker-compose
chmod +x /usr/local/bin/docker-compose

setup_test_requirements
