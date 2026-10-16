# ci/ — building Evolution CMS with this plugin

A plugin is only really tested once it is inside a CMS. Everything here exists
to produce that: an Evolution CMS tree with this plugin installed into it the
way the CMS installs any package, which is then unit-tested, smoke-tested,
zipped and shipped as a docker image.

The same scripts run on a GitHub runner and inside a container, so a failure in
CI can be reproduced locally without pushing.

## Locally

Needs Docker. From the repository root:

```sh
docker compose -f ci/compose.yaml run --rm test     # unit suite
docker compose -f ci/compose.yaml run --rm build    # zip into ./dist
docker compose -f ci/compose.yaml up serve          # CMS on http://localhost:8080
```

By default the CMS is taken from the checkout at `../../evolution` — the layout
these plugins are developed in. Point `EVO_SRC` elsewhere, or at nothing, to
build against a clone of the published branch instead:

```sh
EVO_SRC=/path/to/evolution docker compose -f ci/compose.yaml run --rm test
PHP_VERSION=8.3 docker compose -f ci/compose.yaml run --rm test
EVO_REF=3.5.x docker compose -f ci/compose.yaml run --rm test
```

The manager of a `serve` or `build` tree is `admin` / `Passw0rd123`. These are
published credentials on a throwaway sqlite database — never a production
build.

Without Docker, the scripts run directly on any machine with php, composer,
git and tar:

```sh
ci/compose-evo.sh /tmp/evo --evo-src ../../evolution --dev
ci/run-tests.sh /tmp/evo
```

## In CI

| Workflow | Trigger | What it produces |
| --- | --- | --- |
| `.github/workflows/ci.yml` | push to `main`, PRs | unit suite on PHP 8.3/8.4 (8.5 non-blocking), plus an install + smoke test in a real CMS |
| `.github/workflows/build.yml` | push to `main`, manual | a pre-installed sqlite zip and the plugin zip on the `nightly-main` release, and GHCR images for PHP 8.3/8.4/8.5 × apache/nginx/frankenphp |

The images land under this repository's own owner, so:

```sh
docker run --rm -p 8080:80 ghcr.io/<owner>/<repo>:main
docker run --rm -p 8080:80 ghcr.io/<owner>/<repo>:main-php8.5-frankenphp
```

They are the upstream Evolution CMS images plus this plugin, built from the same
Salo runtimes with the same Dockerfile, so the tag grid matches upstream's:
`main`, `main-php8.4`, `main-php8.3-nginx`, each also with a `-<sha>` suffix.

## The pieces

| File | Role |
| --- | --- |
| `plugin.env` | the only file that differs between the plugin repositories sharing this pipeline — package name, service provider, slug, test mode |
| `compose-evo.sh` | assembles a CMS with the plugin in it: copy the CMS, `composer install` its locked tree, copy the plugin under `core/custom/packages/`, add it to `core/custom/composer.json`, partially update, and optionally install on sqlite, publish assets and migrate |
| `run-tests.sh` | runs the plugin's unit suite, in whichever of the three ways `TEST_MODE` says |
| `smoke.sh` | asserts the package resolved, its provider is discovered, its migrations applied, and the site answers over HTTP |
| `lib/*.php` | the JSON, lock and sqlite reading the shell scripts should not be doing inline |
| `Dockerfile`, `entrypoint.sh`, `compose.yaml` | the local toolchain — PHP and composer only; the CMS is assembled at run time so a bind-mounted checkout can be used |

`.github/docker/Dockerfile` is a different thing: the distributable runtime
image, kept byte-identical to the one in `evolution-cms/evolution` so that this
image differs from upstream's in exactly one way — the plugin inside it.

## How composer is driven, and why in two steps

The promise of this pipeline is that the published image is the upstream
Evolution CMS image **plus one plugin** — not plus a plugin and a few hundred
dependencies that happened to resolve differently that morning. Keeping that
promise takes two composer runs rather than one:

1. **`composer install`** — before the plugin is wired in. With no
   `core/custom/composer.json` yet there is nothing for
   `wikimedia/composer-merge-plugin` to fold into the root package, so the
   committed `core/composer.lock` still describes the root exactly and every
   version installed is the version upstream pinned. This is the same command
   the CMS's own build job runs.
2. **`composer update --with-dependencies <plugin>`** — after it is. A *partial*
   update: only the plugin and its own dependencies may move, everything else
   stays at the locked version.

`compose-evo.sh` then prints the difference between the lock before and after
step 2, so the claim is checked on every build rather than assumed:

```
added    elcreator/alattex dev-main
added    latte/latte v3.1.6

2 added, 0 removed, 0 moved off the version the CMS pinned
```

A package showing up under `CHANGED` means the plugin's constraints pulled a CMS
dependency off its pinned version, and the build is no longer upstream-plus-one-
plugin. `--with-dependencies` (rather than `--with-all-dependencies`) is what
makes that a loud failure instead of a silent bump.

Note what this cannot fix: a plugin that requires a floating version — aSocialAuth's
`hybridauth/hybridauth: dev-master`, for instance — is unpinned by its own
`composer.json`, and no build flag makes it reproducible.

### Why not a single `composer update`

Because it resolves the whole tree from scratch. Observed while bringing these
plugins up: a plain `composer update` moved `symfony/translation` and
`symfony/var-dumper` past the versions Evolution had locked — a difference
between this image and upstream's that has nothing to do with the plugin, and
that would surface as "your plugin broke my site".

The CMS itself does run a full, unrestricted update after writing
`custom/composer.json` — see `InstallPackageRequireCommand::runComposer()`
upstream. That is the right behaviour for a live site being maintained and the
wrong one for a build that has to be reproducible.

### Why `install` alone is not enough

Because `core/composer.lock` would still be considered fresh. Composer derives
the lock's content hash from `core/composer.json`, which this pipeline never
touches — the plugin is added to `core/custom/composer.json` and merged into the
root in memory. So `composer install` neither refuses nor complains; it installs
the locked tree and simply leaves the plugin out. Hence the explicit partial
update in step 2.
