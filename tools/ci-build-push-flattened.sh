#!/usr/bin/env bash
set -euo pipefail

IMAGE_NAME="${IMAGE_NAME:-latif225/mikhmonv3-safelinkhub}"
DOCKERFILE="${DOCKERFILE:-Dockerfile.mikrotik}"
BUILD_STAMP="${BUILD_STAMP:-$(date -u +%Y%m%d%H%M%S)}"
BUILD_VERSION="${BUILD_VERSION:-v3.0.${BUILD_STAMP}}"
MANIFEST_TAGS="${MANIFEST_TAGS:-latest v1}"

if [[ -z "${DOCKERHUB_USERNAME:-}" || -z "${DOCKERHUB_TOKEN:-}" ]]; then
  echo "DOCKERHUB_USERNAME and DOCKERHUB_TOKEN are required." >&2
  exit 1
fi

docker_login() {
  printf '%s' "$DOCKERHUB_TOKEN" | docker login -u "$DOCKERHUB_USERNAME" --password-stdin
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

push_flat_image() {
  local source_tag="$1"
  local dest_tag="$2"
  local platform="$3"
  local override_args=()

  if [[ "$platform" == "linux/arm/v7" ]]; then
    override_args=(--override-os linux --override-arch arm --override-variant v7)
  elif [[ "$platform" == "linux/arm64" ]]; then
    override_args=(--override-os linux --override-arch arm64)
  fi
  local compression_args=(
    --dest-compress
    --dest-compress-format gzip
    --dest-compress-level 9
  )
  if skopeo copy --help 2>&1 | grep -q -- '--dest-force-compress-format'; then
    compression_args+=(--dest-force-compress-format)
  fi

  skopeo copy \
    "${override_args[@]}" \
    "${compression_args[@]}" \
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
    "${IMAGE_NAME}:armv7" \
    "${IMAGE_NAME}:arm64"
}

docker_login

build_flat_image "linux/arm/v7" "mikhmon-build:armv7" "mikhmon-flat:armv7" "mikhmon_flat_armv7_${BUILD_STAMP}"
push_flat_image "mikhmon-flat:armv7" "armv7" "linux/arm/v7"
push_flat_image "mikhmon-flat:armv7" "arm32" "linux/arm/v7"

build_flat_image "linux/arm64" "mikhmon-build:arm64" "mikhmon-flat:arm64" "mikhmon_flat_arm64_${BUILD_STAMP}"
push_flat_image "mikhmon-flat:arm64" "arm64" "linux/arm64"

publish_manifest_tags

echo "Pushed flattened compressed images to ${IMAGE_NAME}: arm32, armv7, arm64; manifests: ${MANIFEST_TAGS}"
