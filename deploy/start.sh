#!/bin/sh
set -eu

port=${PORT:-8080}
case "$port" in
    ''|*[!0-9]*)
        echo 'PORT must be an integer from 1 to 65535.' >&2
        exit 1
        ;;
esac
if [ "${#port}" -gt 5 ] || [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
    echo 'PORT must be an integer from 1 to 65535.' >&2
    exit 1
fi

php -r '
    if (! extension_loaded("pdo_mysql")) {
        fwrite(STDERR, "The pdo_mysql PHP extension is required.\n");
        exit(1);
    }
    $key = getenv("APP_KEY") ?: "";
    $decoded = str_starts_with($key, "base64:") ? base64_decode(substr($key, 7), true) : $key;
    if (! is_string($decoded) || strlen($decoded) !== 32) {
        fwrite(STDERR, "Set APP_KEY to a valid Laravel application key.\n");
        exit(1);
    }
'

# Railway mounts this persistent receipt directory only at application runtime.
receipts=/app/backend/storage/app/private
mount_path=${RAILWAY_VOLUME_MOUNT_PATH:-}
if [ -n "${RAILWAY_ENVIRONMENT_ID:-}" ] && [ "${mount_path%/}" != "$receipts" ]; then
    echo "Attach a persistent Railway volume at $receipts before starting OrderDesk." >&2
    exit 1
fi
install -d -o www-data -g www-data -m 0700 "$receipts"
printf 'Listen %s\n' "$port" > /etc/apache2/ports.conf
# PHP's Apache module uses prefork. Keep threaded MPM modules disabled.
rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf \
    /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf
a2enmod mpm_prefork >/dev/null
exec apache2-foreground
