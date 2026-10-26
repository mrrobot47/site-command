#!/bin/bash
# upload-progress.sh <action> [<site>]: helpers for upload-progress.feature, whose steps can't nest quotes.
set -e
ini=/opt/easyengine/sites/$2/config/php/php/conf.d/custom.ini
case $1 in
crlf-path) printf 'upload_max_filesize = 100M\r\n[PATH=/var/www/htdocs/x]\r\nsession.upload_progress.enabled = Off\r\n' > "$ini" ;;
append-on) printf '\nsession.upload_progress.enabled = On\n' >> "$ini" ;;
strip) sed -i '/session\.upload_progress\.enabled/d; /Session upload progress is off/d' "$ini" ;;
remove) rm "$ini" ;;
exists) if [ -e "$ini" ] || [ -L "$ini" ]; then echo present; else echo missing; fi ;;
probe) printf '%s' '<?php var_dump(ini_get("session.upload_progress.enabled"));' > "/opt/easyengine/sites/$2/app/htdocs/upe.php" ;;
# Makes the next command run the upgrade migrations again, the re-check included.
reset)
	php <<'PHP'
<?php
$db = new PDO( 'sqlite:/opt/easyengine/db/ee.sqlite' );
$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$db->exec( "DELETE FROM migrations WHERE migration LIKE '%recheck_session_upload_progress%'" );
$db->exec( "UPDATE options SET value = '4.13.2' WHERE key = 'version'" );
PHP
	;;
save) cp "$ini" "/tmp/upload-progress-$2.before" ;;
same) cmp "/tmp/upload-progress-$2.before" "$ini" && echo same ;;
line2) tr -s '\r\n' '\n' < "$ini" | sed -n 2p ;;
*) echo "unknown action $1" >&2; exit 1 ;;
esac
