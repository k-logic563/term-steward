#!/usr/bin/env sh

set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
version=$(sed -n 's/^ \* Version:[[:space:]]*\([^[:space:]]*\).*$/\1/p' "$project_dir/term-steward.php")

if [ -z "$version" ]; then
	echo "Could not determine the plugin version." >&2
	exit 1
fi

build_root="$project_dir/build/dist"
stage_root="$build_root/stage"
plugin_dir="$stage_root/term-steward"
dist_dir="$project_dir/dist"
archive="$dist_dir/term-steward-$version.zip"
checksum="$archive.sha256"

case "$build_root" in
	"$project_dir"/build/dist) ;;
	*)
		echo "Refusing to use an unexpected build directory." >&2
		exit 1
		;;
esac

rm -rf "$build_root"
mkdir -p "$plugin_dir" "$dist_dir"

rsync -a --delete --exclude-from="$project_dir/.distignore" "$project_dir/" "$plugin_dir/"
cp "$project_dir/composer.json" "$project_dir/composer.lock" "$plugin_dir/"

docker compose --project-directory "$project_dir" run --rm --no-deps \
	-e "COMPOSER_ROOT_VERSION=$version" \
	-v "$plugin_dir:/dist" \
	composer install \
	--working-dir=/dist \
	--no-dev \
	--prefer-dist \
	--optimize-autoloader \
	--no-interaction \
	--no-progress

rm -f "$plugin_dir/composer.lock"

for required_file in \
	term-steward.php \
	readme.txt \
	README.md \
	CHANGELOG.md \
	LICENSE \
	composer.json \
	vendor/autoload.php
do
	test -f "$plugin_dir/$required_file"
done

if [ -e "$plugin_dir/taxonomy-tidy.php" ]; then
	echo "The staged package contains the old main plugin file." >&2
	exit 1
fi

if grep -R -I -n -E 'Taxonomy Tidy|taxonomy-tidy|taxonomy_tidy|TaxonomyTidy|TAXONOMY_TIDY' "$plugin_dir"; then
	echo "The staged package contains an old product identifier." >&2
	exit 1
fi

stable_tag=$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*$/\1/p' "$plugin_dir/readme.txt")
contributors=$(sed -n 's/^Contributors:[[:space:]]*\(.*\)$/\1/p' "$plugin_dir/readme.txt")

if [ "$stable_tag" != "$version" ]; then
	echo "Stable tag must match the plugin version." >&2
	exit 1
fi

if [ "$contributors" != "klogic563" ]; then
	echo "The WordPress.org contributor must be klogic563." >&2
	exit 1
fi

if find "$plugin_dir" \
	\( -name '.env*' -o -name '.git*' -o -name .github -o -name node_modules -o -name tests -o -name tools -o -name languages -o -name '*.po' -o -name '*.mo' -o -name '*.pot' -o -name '*.l10n.php' -o -name 'docker-compose*.yml' -o -name 'package*.json' -o -name 'playwright*' \) \
	-print -quit | grep -q .; then
	echo "The staged package contains a forbidden development file." >&2
	exit 1
fi

find "$plugin_dir" -exec touch -t 202601010000 {} +
rm -f "$archive" "$checksum"
(
	cd "$stage_root"
	find term-steward -print | LC_ALL=C sort | zip -X -q "$archive" -@
)

(
	cd "$dist_dir"
	shasum -a 256 "$(basename "$archive")" > "$(basename "$checksum")"
)

unzip -tq "$archive" >/dev/null
echo "Created $archive"
cat "$checksum"
