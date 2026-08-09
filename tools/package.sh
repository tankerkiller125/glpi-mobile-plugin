#!/usr/bin/env bash
#
# Build the distributable plugin archives into dist/.
#
# GLPI installs a plugin by unpacking it into glpi/plugins/, so the archive must
# contain exactly one top-level directory named after the plugin ("glpimobile")
# — the same name setup.php and the class namespace use. Anything else and GLPI
# will not see the plugin at all.
#
# Usage: tools/package.sh          (version read from setup.php)
#
set -euo pipefail

PLUGIN=glpimobile

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$root"

version=$(sed -n "s/^define('PLUGIN_GLPIMOBILE_VERSION', *'\([^']*\)').*/\1/p" setup.php)
if [ -z "$version" ]; then
  echo "package.sh: could not read PLUGIN_GLPIMOBILE_VERSION from setup.php" >&2
  exit 1
fi

# Everything the plugin needs at runtime, plus the documents a distribution
# should carry. Nothing else ships: no .git, no CI config, no dev tooling.
contents=(setup.php hook.php src front README.md LICENSE CHANGELOG.md SECURITY.md AI-DISCLOSURE.md)

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/$PLUGIN"

for item in "${contents[@]}"; do
  if [ ! -e "$item" ]; then
    echo "package.sh: missing $item" >&2
    exit 1
  fi
  cp -R "$item" "$stage/$PLUGIN/"
done

# Compiled translations, once there are any (locales/*.mo are built, not
# committed — see CONTRIBUTING.md).
if [ -d locales ]; then
  cp -R locales "$stage/$PLUGIN/"
fi

rm -rf dist
mkdir -p dist

(cd "$stage" && zip -qr "$root/dist/$PLUGIN-$version.zip" "$PLUGIN")
tar -cjf "dist/$PLUGIN-$version.tar.bz2" -C "$stage" "$PLUGIN"
(cd dist && sha256sum "$PLUGIN-$version".* > SHA256SUMS.txt)

# Consumed by the release workflow so the version is read in exactly one place.
printf '%s' "$version" > dist/VERSION

echo "Packaged $PLUGIN $version:"
ls -l dist
