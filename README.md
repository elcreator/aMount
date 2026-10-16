# aMount

Mount cloud and remote storage into the **Evolution CMS 3.5+** manager and offload big files to it in the
background: database backups, media, anything a manager can see in the file manager.

- **Any store Flysystem can talk to.** Amazon S3 and compatible (R2, MinIO, Wasabi, Backblaze B2), Google Drive,
  Dropbox, OneDrive, Google Cloud Storage, Azure Blob, SFTP, FTP/FTPS, WebDAV (Nextcloud, ownCloud), Bunny, Alibaba OSS,
  Tencent COS, Qiniu, MongoDB GridFS, a ZIP archive, or a folder on the server. Drivers whose package is missing are
  listed with the exact command to install them.
- **Background transfers.** Pick a folder, choose copy or move, optionally a schedule. Uploads, downloads back and
  "how much space does this take?" counts run in a queue; the page shows progress and can cancel.
- **Both directions.** Bring a file back into the site when you need it (a backup, for example), optionally deleting the
  remote copy afterwards.
- **Public files keep their URL.** An offloaded public file that is gone from disk is redirected to, or streamed from,
  the store when its old URL is requested. Private files (backups) are downloadable only through the manager.
- **For AI agents too.** With [eMCP](https://github.com/evolution-cms/emcp) installed, agents get tools to list mounts,
  browse, deliver a file ("put the result on my Google Drive") and fetch one back, with exactly the rights of the
  manager they act for. Other packages use the same thing through `Elcreator\aMount\Services\Delivery`.

## Install

```sh
cd core
php artisan package:installrequire elcreator/amount "*"
php artisan migrate
```

The module appears in the manager menu as **aMount**. Run `php artisan amount:drivers` to see which drivers are
installed; install a missing one with the command it prints. `php artisan amount:demo --run` seeds a demo on a local
disk (no account needed).

## Permissions

| Permission | Lets a manager |
|---|---|
| `amount` | Use the module: connect **their own** accounts (a personal Google Drive, Dropbox...) and offload folders they can see in the file manager. |
| `amount_manage` | Administer: site-wide mounts (shared credentials, optionally limited to chosen user groups), everybody's rules, the server configuration. |

Administrators (role 1) get both. Nobody else gets anything by default: grant `amount` to a role in
*Users -> Roles*. Holding `amount` never widens what a manager may see.

## Queue

Uploads need a worker. Two are supported, chosen with `queue.driver` in `core/custom/config/cms/settings/aMount.php`
(or `AMOUNT_QUEUE`):

- `auto` (default): [sTask](https://github.com/Seiger/sTask) when installed, otherwise the CMS task queue.
- `system`: the CMS task queue. Keep `php artisan system:task-worker` running (or let the scheduler start it).
- `stask`: sTask. Keep `php artisan stask:worker` running.

Scheduled rules and housekeeping are started by `amount:tick`, which aMount adds to the Laravel scheduler every minute.
That needs the usual one-line cron entry:

```
* * * * * cd /path/to/core && php artisan schedule:run >> /dev/null 2>&1
```

The Mounts tab shows which queue is active and whether it looks healthy. A run whose worker died is queued again
automatically after `limits.stale_after` seconds; every step is safe to repeat.

## Serving offloaded public files

When a public rule offloads `assets/images` and the local copy is removed (`mode = move`), the browser still asks for
`/assets/images/photo.jpg`. aMount answers when the CMS is asked, so the **web server must hand missing files to the
CMS** instead of answering 404 itself. The Server tab (and `php artisan amount:server`) generates exactly what is needed
for the folders your public rules use; the *Probe* button checks that it works.

- **nginx** (`ng.inx`): static-file locations answer 404 for missing files before the CMS is asked. Include the generated
  file (`core/custom/amount/amount.nginx.conf`) in the server block **after the deny rules and before the static-files
  location**, then reload nginx:

  ```nginx
  location ~ ^/(assets/(?!cache/|backup/|export/|import/)|uploads/) {
      try_files $uri @rewrite;
      expires max;
      access_log off;
  }
  ```
- **Apache** (`.htaccess`): `RewriteRule ^(manager|assets|js|css|images|img)/.*$ - [L]` stops friendly URLs for `/assets`.
  aMount maintains a block between `# BEGIN aMount` and `# END aMount` in front of that line (and keeps a backup,
  `.htaccess.amount.bak`):

  ```apache
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteRule ^(assets/(?!cache/|backup/|export/|import/)|uploads/).+$ index.php?q=$0 [L,QSA]
  ```
- **FrankenPHP / Caddy** (Salo `Caddyfile`): nothing to change, missing paths already go to the CMS.

`php artisan amount:server nginx --write` writes the file; `--probe` tests it. Serving can be switched off with
`serve.enabled = false`; `serve.mode` chooses redirect, proxy or auto (redirect when the mount has a public URL).

## Security model, in plain words

- **A manager can only offload what they can see in the file manager.** Every folder and file is checked against that
  manager's file root, the permission-protected folders and, with `use_udperms`, the file groups; links are refused.
  This is checked when a rule is saved and **again for every file on every run**, as the account stands at that moment:
  revoke a permission, block the account or narrow their file root, and the next run notices and stops.
- **Personal mounts are private.** Only their owner can open or use them, not even an administrator (who may delete
  them). Credentials are encrypted in the database and never sent to the browser: forms show `********` and a blank
  secret field means "keep the stored value". Credentials that must not live in the database can be defined in
  configuration (`mounts` in `aMount.php`); such mounts are read-only in the manager.
- **Site mounts** are usable by managers with `amount` (optionally only members of chosen user groups). A manager
  without `amount_manage` is confined to `u<their id>/` on a site mount, so one manager's uploads cannot land in or
  overwrite another's.
- **Downloads back are cautious.** The destination must be a folder the user can write to; nothing is overwritten unless
  asked and allowed; executable and server-configuration file names are refused unless the user may edit code; files are
  written to `<name>.amount-part`, size-checked, then renamed. A database dump downloaded back is restored with the CMS's
  own Backup manager (there is no queued restore).
- **Private files are never served by URL.** Only public rules answer anonymous requests; backups are downloaded through
  the authenticated manager endpoint, which re-checks that the manager may see the original.
- Every POST carries the manager's CSRF token; secrets never appear in responses, logs or error messages.

## Configuration

Override any key in `core/custom/config/cms/settings/aMount.php`; defaults are in `config/aMount.php` (`enable`, `queue`,
`limits`, `serve`, `server`, `mounts`).

## Development

The suite borrows an Evolution CMS core's autoloader. Point `EVO_CORE_PATH_TEST` at a core with dev dependencies
installed and run `php $EVO_CORE_PATH_TEST/vendor/pestphp/pest/bin/pest --no-coverage` from this directory. See
`AGENTS.md` for the architecture and `ci/README.md` for building a full CMS with the plugin inside (Docker).

Licence: see `LICENSE`.
