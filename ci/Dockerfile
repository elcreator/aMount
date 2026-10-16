# Toolchain image for building and testing this plugin against Evolution CMS
# on a developer's machine.
#
# It carries only PHP, composer and the extensions the CMS needs. The CMS
# itself is assembled at *run* time by ci/compose-evo.sh, not baked in here,
# so that `--evo-src` can point at a checkout bind-mounted from the host - the
# whole reason this image exists is to build against the Evolution working copy
# you have open, not against whatever was current when the image was built.
#
# The distributable image is a different thing entirely and is built by
# .github/docker/Dockerfile on top of a Salo runtime. This one never ships.
#
#   docker compose -f ci/compose.yaml run --rm test
#
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli

# The extension set from the CMS's own CI (.github/workflows/ci.yml upstream),
# plus gd, which image handling in the manager needs.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        ctype dom exif fileinfo filter gd iconv intl mbstring \
        openssl pdo pdo_sqlite readline simplexml sqlite3 \
        tokenizer xml xmlreader zip \
    && rm -rf /var/lib/apt/lists/*

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip zip curl rsync \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Composer downloads survive between runs through this, mounted as a volume by
# ci/compose.yaml. A full core update is a few hundred packages.
ENV COMPOSER_CACHE_DIR=/composer-cache \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

# Where compose-evo.sh assembles the CMS. Outside /plugin on purpose: the
# script refuses to build into a directory inside the plugin it is copying.
ENV EVO_BUILD_DIR=/build

WORKDIR /plugin

ENTRYPOINT ["/plugin/ci/entrypoint.sh"]
CMD ["test"]
