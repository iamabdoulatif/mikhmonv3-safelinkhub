#!/usr/bin/env bash
set -euo pipefail

IMAGE_NAME="${IMAGE_NAME:-latif225/mikhmonv3-safelinkhub}"
DOCKERFILE="${DOCKERFILE:-Dockerfile.mikrotik}"
BUILD_STAMP="${BUILD_STAMP:-$(date -u +%Y%m%d%H%M%S)}"
BUILD_VERSION="${BUILD_VERSION:-v3.0.${BUILD_STAMP}}"
MANIFEST_TAGS="${MANIFEST_TAGS:-latest v1}"
PUSH_IMAGES="${PUSH_IMAGES:-1}"
MIN_COMPRESSED_MB="${MIN_COMPRESSED_MB:-11}"
MAX_COMPRESSED_MB="${MAX_COMPRESSED_MB:-13}"
SIZE_CHECK_PLATFORMS="${SIZE_CHECK_PLATFORMS:-linux/arm64 linux/arm/v6 linux/arm/v7}"

PLATFORM_SPECS=(
  "linux/amd64|amd64|amd64"
  "linux/arm64|arm64 hap-ax2 ax2|arm64"
  "linux/s390x|s390x|s390x"
  "linux/arm/v6|armv6 arm32v6|arm-v6"
  "linux/arm/v7|armv7 arm32 hap-ax-lite|arm-v7"
)

push_enabled() {
  case "$PUSH_IMAGES" in
    1|true|TRUE|yes|YES) return 0 ;;
    *) return 1 ;;
  esac
}

if push_enabled && [[ -z "${DOCKERHUB_USERNAME:-}" || -z "${DOCKERHUB_TOKEN:-}" ]]; then
  echo "DOCKERHUB_USERNAME and DOCKERHUB_TOKEN are required." >&2
  exit 1
fi

docker_login() {
  printf '%s' "$DOCKERHUB_TOKEN" | docker login -u "$DOCKERHUB_USERNAME" --password-stdin
}

set_platform_override_args() {
  local platform="$1"

  case "$platform" in
    linux/amd64)
      OVERRIDE_ARGS=(--override-os linux --override-arch amd64)
      ;;
    linux/arm64)
      OVERRIDE_ARGS=(--override-os linux --override-arch arm64)
      ;;
    linux/s390x)
      OVERRIDE_ARGS=(--override-os linux --override-arch s390x)
      ;;
    linux/arm/v6)
      OVERRIDE_ARGS=(--override-os linux --override-arch arm --override-variant v6)
      ;;
    linux/arm/v7)
      OVERRIDE_ARGS=(--override-os linux --override-arch arm --override-variant v7)
      ;;
    *)
      echo "Unsupported platform: ${platform}" >&2
      exit 1
      ;;
  esac
}

set_compression_args() {
  COMPRESSION_ARGS=(
    --dest-compress
    --dest-compress-format gzip
    --dest-compress-level 9
  )

  if skopeo copy --help 2>&1 | grep -q -- '--dest-force-compress-format'; then
    COMPRESSION_ARGS+=(--dest-force-compress-format)
  fi
}

build_flat_image() {
  local platform="$1"
  local local_tag="$2"
  local flat_tag="$3"
  local container_name="$4"

  docker buildx build \
    --platform "$platform" \
    --build-arg "BUILD_VERSION=${BUILD_VERSION}" \
    --build-arg "BUILD_STAMP=${BUILD_STAMP}" \
    --build-arg "IMAGE_NAME=${IMAGE_NAME}" \
    --load \
    -f "$DOCKERFILE" \
    -t "$local_tag" \
    .

  docker rm -f "$container_name" >/dev/null 2>&1 || true
  docker create --name "$container_name" --platform "$platform" "$local_tag" >/dev/null

  docker export "$container_name" | docker import \
    --platform "$platform" \
    --change 'ENTRYPOINT ["php"]' \
    --change 'CMD ["-S","0.0.0.0:80","-t","/src/src/"]' \
    --change 'WORKDIR /src' \
    --change 'EXPOSE 80' \
    --change "LABEL org.opencontainers.image.title=mikhmonv3-safelinkhub" \
    --change "LABEL org.opencontainers.image.description=Mikhmon-V3-SafelinkHub-for-MikroTik-RouterOS-containers" \
    --change "LABEL org.opencontainers.image.version=${BUILD_VERSION}" \
    --change "LABEL org.opencontainers.image.revision=${BUILD_STAMP}" \
    - "$flat_tag" >/dev/null

  docker rm -f "$container_name" >/dev/null
}

platform_requires_size_check() {
  local platform="$1"

  case " ${SIZE_CHECK_PLATFORMS} " in
    *" ${platform} "*) return 0 ;;
    *) return 1 ;;
  esac
}

format_bytes_mib() {
  awk -v bytes="$1" 'BEGIN { printf "%.2f MiB", bytes / 1024 / 1024 }'
}

measure_compressed_size() {
  local source_tag="$1"
  local platform="$2"
  local tmp_dir
  local size_kb

  tmp_dir="$(mktemp -d "${TMPDIR:-/tmp}/mikhmon-skopeo-size.XXXXXX")"
  set_platform_override_args "$platform"
  set_compression_args

  if ! skopeo copy \
    "${OVERRIDE_ARGS[@]}" \
    "${COMPRESSION_ARGS[@]}" \
    "docker-daemon:${source_tag}" \
    "dir:${tmp_dir}" >/dev/null; then
    rm -rf "$tmp_dir"
    return 1
  fi

  size_kb="$(du -sk "$tmp_dir" | awk '{print $1}')"
  rm -rf "$tmp_dir"
  echo $((size_kb * 1024))
}

enforce_compressed_size() {
  local source_tag="$1"
  local platform="$2"
  local size_bytes
  local min_bytes=$((MIN_COMPRESSED_MB * 1024 * 1024))
  local max_bytes=$((MAX_COMPRESSED_MB * 1024 * 1024))

  if ! platform_requires_size_check "$platform"; then
    return 0
  fi

  size_bytes="$(measure_compressed_size "$source_tag" "$platform")"
  echo "Compressed ${platform} image size: $(format_bytes_mib "$size_bytes") (required ${MIN_COMPRESSED_MB}-${MAX_COMPRESSED_MB} MiB)"

  if (( size_bytes < min_bytes || size_bytes > max_bytes )); then
    echo "Compressed ${platform} image must stay between ${MIN_COMPRESSED_MB} MiB and ${MAX_COMPRESSED_MB} MiB." >&2
    exit 1
  fi
}

push_flat_image() {
  local source_tag="$1"
  local dest_tag="$2"
  local platform="$3"

  set_platform_override_args "$platform"
  set_compression_args

  skopeo copy \
    "${OVERRIDE_ARGS[@]}" \
    "${COMPRESSION_ARGS[@]}" \
    --dest-creds "${DOCKERHUB_USERNAME}:${DOCKERHUB_TOKEN}" \
    "docker-daemon:${source_tag}" \
    "docker://${IMAGE_NAME}:${dest_tag}"
}

publish_manifest_tags() {
  local create_args=()
  local tag

  for tag in $MANIFEST_TAGS; do
    create_args+=("-t" "${IMAGE_NAME}:${tag}")
  done

  docker buildx imagetools create \
    "${create_args[@]}" \
    "${IMAGE_NAME}:amd64" \
    "${IMAGE_NAME}:arm64" \
    "${IMAGE_NAME}:s390x" \
    "${IMAGE_NAME}:armv6" \
    "${IMAGE_NAME}:armv7"
}

if push_enabled; then
  docker_login
fi

PUBLISHED_TAGS=()

for spec in "${PLATFORM_SPECS[@]}"; do
  IFS='|' read -r platform tag_list suffix <<< "$spec"
  local_tag="mikhmon-build:${suffix}"
  flat_tag="mikhmon-flat:${suffix}"
  container_name="mikhmon_flat_${suffix//-/_}_${BUILD_STAMP}"

  build_flat_image "$platform" "$local_tag" "$flat_tag" "$container_name"
  enforce_compressed_size "$flat_tag" "$platform"

  for dest_tag in $tag_list; do
    PUBLISHED_TAGS+=("$dest_tag")
    if push_enabled; then
      push_flat_image "$flat_tag" "$dest_tag" "$platform"
    fi
  done
done

if push_enabled; then
  publish_manifest_tags
fi

if push_enabled; then
  echo "Pushed flattened compressed images to ${IMAGE_NAME}: ${PUBLISHED_TAGS[*]}; manifests: ${MANIFEST_TAGS}"
else
  echo "Built flattened compressed images locally: ${PUBLISHED_TAGS[*]}"
fi
