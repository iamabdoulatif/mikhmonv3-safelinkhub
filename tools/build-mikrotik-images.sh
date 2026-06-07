#!/usr/bin/env bash
# Build flattened Mikhmon RouterOS container archives for MikroTik ARM targets.
#
# Targets:
#   linux/arm/v7 -> RB4011 / RB3011 / ARMv7 RouterOS container package
#   linux/arm64  -> hAP ax2 / Chateau Pro / ARM64 RouterOS container package

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
OUT="${OUT:-$PROJECT_DIR/docker-output}"
DOCKERFILE="${DOCKERFILE:-$PROJECT_DIR/Dockerfile.mikrotik}"
REPO="${REPO:-local/mikhmonv3-safelinkhub}"
BUILDER="${BUILDER:-safelink-builder}"
STAMP="${BUILD_STAMP:-$(date -u +%Y%m%d%H%M%S)}"
VERSION="${BUILD_VERSION:-v3.0.${STAMP}}"

mkdir -p "$OUT"

info() { printf '[INFO] %s\n' "$*"; }
warn() { printf '[WARN] %s\n' "$*" >&2; }
error() { printf '[ERR] %s\n' "$*" >&2; exit 1; }

command -v docker >/dev/null || error "docker requis"
command -v skopeo >/dev/null || error "skopeo requis"
docker info >/dev/null 2>&1 || error "Docker daemon inaccessible"
[[ -f "$DOCKERFILE" ]] || error "Dockerfile introuvable: $DOCKERFILE"

if ! docker buildx inspect "$BUILDER" >/dev/null 2>&1; then
  warn "Builder '$BUILDER' introuvable, utilisation du builder buildx courant"
  BUILDER=""
fi

build_image() {
  local platform="$1"
  local tag="$2"
  local builder_args=()

  if [[ -n "$BUILDER" ]]; then
    builder_args=(--builder "$BUILDER")
  fi

  info "Build $platform -> $tag"
  docker buildx build \
    "${builder_args[@]}" \
    --platform "$platform" \
    --build-arg "BUILD_VERSION=$VERSION" \
    --build-arg "BUILD_STAMP=$STAMP" \
    --load \
    -f "$DOCKERFILE" \
    -t "$tag" \
    "$PROJECT_DIR"
}

flatten_image() {
  local platform="$1"
  local source="$2"
  local flat="$3"
  local target="$4"
  local arch_label="$5"
  local container="mikhmon_flatten_${target}_${STAMP}"

  docker rm -f "$container" >/dev/null 2>&1 || true
  info "Aplatissement $source -> $flat"
  docker create --name "$container" --platform "$platform" "$source" >/dev/null

  docker export "$container" | docker import \
    --platform "$platform" \
    --change 'ENV PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' \
    --change 'ENV PHP_INI_DIR=/usr/local/etc/php' \
    --change 'ENV PHP_CLI_SERVER_WORKERS=4' \
    --change 'WORKDIR /src' \
    --change 'EXPOSE 80' \
    --change 'ENTRYPOINT ["php"]' \
    --change 'CMD ["-S", "0.0.0.0:80", "-t", "/src/src/"]' \
    --change 'LABEL org.opencontainers.image.title="mikhmonv3-safelinkhub"' \
    --change 'LABEL org.opencontainers.image.description="Mikhmon v3 SafeLink Hub for MikroTik RouterOS containers"' \
    --change "LABEL org.opencontainers.image.version=\"$VERSION\"" \
    --change "LABEL org.opencontainers.image.revision=\"$STAMP\"" \
    --change "LABEL mikrotik.target=\"$target\"" \
    --change "LABEL mikrotik.arch=\"$arch_label\"" \
    - "$flat" >/dev/null

  docker rm "$container" >/dev/null
}

export_archive() {
  local arch="$1"
  local variant="$2"
  local source="$3"
  local archive="$4"
  local reference="$5"
  local args=(--override-arch "$arch" --override-os linux)

  if [[ -n "$variant" ]]; then
    args+=(--override-variant "$variant")
  fi

  rm -f "$archive" "$archive.gz"
  info "Export skopeo -> $archive"
  skopeo copy \
    "${args[@]}" \
    "docker-daemon:$source" \
    "docker-archive:${archive}:${reference}"

  info "Compression gzip -9 -> $archive.gz"
  gzip -9 -f -k "$archive"
}

BASE_ARM64="$REPO:base-arm64-${STAMP}-runtime"
BASE_ARMV7="$REPO:base-armv7-${STAMP}-runtime"
FLAT_ARM64="$REPO:flat-arm64-${STAMP}-runtime"
FLAT_ARMV7="$REPO:flat-armv7-${STAMP}-runtime"
OUT_ARM64="$OUT/mikhmon-flat-arm64-mikrotik.tar"
OUT_ARMV7="$OUT/mikhmon-flat-armv7-mikrotik.tar"

info "Version: $VERSION"
info "Sortie: $OUT"

build_image "linux/arm64" "$BASE_ARM64"
build_image "linux/arm/v7" "$BASE_ARMV7"

flatten_image "linux/arm64" "$BASE_ARM64" "$FLAT_ARM64" "hap-ax2-chateau-pro" "arm64"
flatten_image "linux/arm/v7" "$BASE_ARMV7" "$FLAT_ARMV7" "rb4011-rb3011" "arm32/armv7"

export_archive "arm64" "" "$FLAT_ARM64" "$OUT_ARM64" "mikhmon-flat:arm64"
export_archive "arm" "v7" "$FLAT_ARMV7" "$OUT_ARMV7" "mikhmon-flat:armv7"

printf '%s\n' "$STAMP" > "$OUT/latest-fixed-stamp.txt"
printf '%s\n' "$VERSION" > "$OUT/latest-fixed-version.txt"
printf '%s\n' "$BASE_ARM64" > "$OUT/latest-fixed-arm64-base.txt"
printf '%s\n' "$BASE_ARMV7" > "$OUT/latest-fixed-armv7-base.txt"
printf '%s\n' "$FLAT_ARM64" > "$OUT/latest-fixed-arm64-flat.txt"
printf '%s\n' "$FLAT_ARMV7" > "$OUT/latest-fixed-armv7-flat.txt"

info "Inspection des archives exportées"
skopeo inspect "docker-archive:$OUT_ARM64" | python3 -c 'import json,sys; d=json.load(sys.stdin); print("arm64 archive:", d["Architecture"], "layers=", len(d["Layers"]))'
skopeo inspect "docker-archive:$OUT_ARMV7" | python3 -c 'import json,sys; d=json.load(sys.stdin); print("armv7 archive:", d["Architecture"], d.get("Variant", ""), "layers=", len(d["Layers"]))'

info "Artifacts"
for file in "$OUT_ARM64" "$OUT_ARM64.gz" "$OUT_ARMV7" "$OUT_ARMV7.gz"; do
  du -h "$file"
done
