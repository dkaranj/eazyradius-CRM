#!/usr/bin/env sh
set -eu

# Lightweight Gradle bootstrapper for GitHub Actions.
# It downloads the pinned Gradle distribution if it is not already cached.
APP_HOME=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
GRADLE_VERSION="8.7"
GRADLE_USER_HOME="${GRADLE_USER_HOME:-$HOME/.gradle}"
DIST_DIR="$GRADLE_USER_HOME/wrapper/dists/gradle-$GRADLE_VERSION-bin/manual"
GRADLE_BIN="$DIST_DIR/gradle-$GRADLE_VERSION/bin/gradle"
ZIP="$DIST_DIR/gradle-$GRADLE_VERSION-bin.zip"

if [ ! -x "$GRADLE_BIN" ]; then
  mkdir -p "$DIST_DIR"
  if [ ! -f "$ZIP" ]; then
    echo "Downloading Gradle $GRADLE_VERSION..."
    if command -v curl >/dev/null 2>&1; then
      curl -fL --retry 3 \
        "https://services.gradle.org/distributions/gradle-$GRADLE_VERSION-bin.zip" \
        -o "$ZIP"
    elif command -v wget >/dev/null 2>&1; then
      wget -O "$ZIP" \
        "https://services.gradle.org/distributions/gradle-$GRADLE_VERSION-bin.zip"
    else
      echo "Error: curl or wget is required to download Gradle." >&2
      exit 1
    fi
  fi
  unzip -q -o "$ZIP" -d "$DIST_DIR"
fi

exec "$GRADLE_BIN" "$@"
