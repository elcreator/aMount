---
name: amount
description: Moves files between Evolution CMS sites and cloud drives or own computers via aMount eMCP tools. Use for Evo backups and imports. Do NOT use for local copies.
version: "1.1.0"
license: MIT
compatibility: Evolution CMS 3.5.2+ with evolution-cms/emcp and elcreator/amount installed; an MCP client connected to the site's eMCP server
metadata:
  author: Artur Kyryliuk <mail@artur.work>
  tags:
    - evolution-cms
    - mcp
    - storage
    - backup
  package: elcreator/amount
---

# aMount via eMCP

## Purpose

Copy files between an Evolution CMS site and storage mounts (Google Drive,
Dropbox, S3, ...) or folders the user shares from their own computer through
the ai.artur.work program. Transfers run in the background on the site's queue.
Every call acts as the manager who owns the eMCP token and sees only the mounts,
rules, runs and file-manager folders that manager may use.

## When to Use

Use when the user asks to back up site folders to cloud storage, pull files from
a drive or from "my computer" into the site, send generated files somewhere,
restore offloaded files, check what is offloaded, or measure storage use.

Do NOT use for copying files on the local disk, for sites without aMount, for
creating mounts or rules (done on the aMount page), or for document edits (use
the `evolution-cms-mcp` skill).

## Prerequisites

- The site is connected as described in the `evolution-cms-mcp` skill and
  `tools/list` contains `amount.*` tools; otherwise install
  `php artisan package:installrequire elcreator/amount "*"` and grant the role
  the `amount` permission (`amount_manage` for site-wide mounts).
- Read tools need `mcp:read`, `mcp:call`. `deliver`, `fetch`, `recall` and `cancel`
  need write tools enabled in **Tools → MCP settings** plus `mcp:write`.
  Overwriting and deleting need destructive tools enabled plus `mcp:destructive`.
- A queue worker runs (`php artisan system:task-worker` or `php artisan stask:worker`)
  and the scheduler calls `amount:tick` every minute.
- For own computers: the ai.artur.work program runs there and shares the folder.
- The mount's storage driver is installed. Only the local folder driver ships with
  aMount; S3, SFTP, WebDAV, Google Drive and the rest are optional packages that an
  administrator installs (**Install** next to the driver on the aMount page, or
  `php artisan amount:drivers --install=<driver id>`). No MCP tool installs packages.

## Instructions

1. **Resolve the target first.** `amount.mounts` `{"name": "google drive"}` returns
   one mount or `candidates`; with several, ask the user which uuid. For computers
   call `amount.computers`; a computer's name (e.g. `mypc`) works as `mount`, and
   remote paths start with the shared folder: `"<shared folder>/<path>"`.
2. **Look before copying.** `amount.browse` lists remote sub-folders; check the
   destination exists and is the one the user means.
3. **Start one run**, then poll `amount.status` `{"run": "<uuid>"}` every few
   seconds until `finished` is true. Report files done, skipped and why.
4. **Keep by default.** Leave `on_conflict` unset (or `skip`). Use `newer`,
   `move=true` or `delete_remote=true` only when the user asked for replacing or
   removing, and say what will be removed first.
5. **Delete only with explicit consent.** `amount.delete_remote` is irreversible:
   name the exact file from `amount.files`, wait for the user's yes, then call it
   with `confirm=true`.
6. **Pass `site`** with the domain the user named on multi-site installations, so
   a wrong site is refused.
7. If a computer folder is not approved for this site yet, tell the user to
   approve it at that computer after comparing the code shown there with
   `site.code` from `amount.computers`, then retry.

## Tools

| Tool | Kind | Key arguments |
|---|---|---|
| `amount.mounts` | read | `name` |
| `amount.computers` | read | none: computers, online state, shared folders, access, `site.code` |
| `amount.browse` | read | `mount`*, `path` |
| `amount.rules` | read | none: standing folder rules |
| `amount.files` | read | `rule`*, `q`, `status`, `page` (50 per page) |
| `amount.runs` | read | `active`, `rule` |
| `amount.status` | read | `run`* |
| `amount.measure` | read, background | `mount` or `rule`, `path`, `local` |
| `amount.deliver` | write | `path`*, `mount`, `folder`, `move`, `public`, `on_conflict`, `site` |
| `amount.fetch` | write | `mount`*, `remote`*, `to`*, `recursive`, `on_conflict`, `site`, `delete_remote` |
| `amount.recall` | write | `files`* (ids, max 200, one rule), `to`, `overwrite`, `delete_remote` |
| `amount.cancel` | write | `run`* |
| `amount.delete_remote` | destructive | `file`*, `confirm`* |

`*` required. Limits: `fetch` takes up to 2000 files recursively or 200 directly
inside a folder; `browse` returns up to 500 folders; `runs` the newest 50.

## Examples

### Back up a site folder to Google Drive

```json
{"name": "amount.mounts", "arguments": {"name": "google drive"}}
{"name": "amount.deliver", "arguments": {"path": "assets/backup", "mount": "<uuid>", "folder": "Backups/site"}}
{"name": "amount.status", "arguments": {"run": "<run uuid>"}}
```

### Import a folder from the user's computer

"Upload sqlbackups 2025/08 from my computer mypc to backups":

```json
{"name": "amount.fetch", "arguments": {"mount": "mypc", "remote": "sqlbackups/2025/08", "to": "backups", "recursive": true}}
```

### Restore offloaded files

1. `amount.rules` to find the rule, `amount.files` `{"rule": "<uuid>", "q": "products"}` for ids.
2. `amount.recall` `{"files": [12, 13], "to": "assets/restored"}`, then poll `amount.status`.

### Measure storage use

`amount.measure` `{"mount": "<uuid>"}`, poll `amount.status`, read `usage` when finished.

### Combine with aIMage

Fetch source images from a computer with `amount.fetch`, edit them with
`aimage.job.create`, and send results back with its `deliver_to: "mypc"` and
`deliver_folder` (see the `aimage` skill).

## Limitations

- Mounts and standing rules are created on the aMount page, not through MCP.
- Only paths the manager can see (and, for writes, modify) in the file manager are allowed;
  executable files are never written. Symbolic links and junctions are never followed: a
  path through a linked folder is refused, and links inside a copied folder are skipped.
- A mount connects only where an administrator allows: a mount last set up by a
  non-administrator reaches public addresses only (never this server or the internal
  network), local-folder and ZIP mounts must be set up by an administrator, and SFTP
  mounts need a pinned host key.
- Computer folders have quotas, and files from a computer fill a site folder only up to the
  mount's `site_quota_mb`.
- `amount.cancel` is cooperative: files already transferred stay.
- Credentials are never returned.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| No `amount.*` tools | aMount not installed or `amount` permission missing | Install the package, grant the permission |
| HTTP `403` `tool_denied` / `scope_denied` on a transfer | Write (or destructive) tools disabled, or token lacks the scope | Enable the switch in MCP settings; recreate the token |
| Run stays `queued` | No queue worker or scheduler | Start the worker and the cron `schedule:run` |
| JSON-RPC `-32602` with `candidates` | A mount name matched several mounts | Ask the user which one, repeat with its uuid |
| `-32602` `path_denied` / `modify_denied` | Path outside the manager's file root or not writable | Use a path the manager may edit |
| `path_denied` with `(link)` | The path goes through a symbolic link or junction | Use the real folder's path |
| `browse_failed` / `fetch_failed`, or a failed run: "needs <package>. Install it with: ... amount:drivers --install=<id>" | The mount's driver package is not installed | Ask an administrator to install it (aMount page or that command), then retry |
| Same, with "only an administrator can set it up" or "is on this server or an internal network" | The mount points at this server or the internal network and an administrator did not configure it | An administrator re-saves the mount on the aMount page, or the owner picks a public address |
| Same, with "Pin the server's host key" | SFTP mount without a host key | The owner adds the key (`ssh-keyscan HOST \| ssh-keygen -lf -`) on the aMount page |
| `-32602` `destructive_denied` | `on_conflict=newer`, `move` or `delete_remote` without destructive permission | Enable destructive tools and `mcp:destructive`, or keep files |
| `-32602` `wrong_site` | `site` names a domain this installation does not serve | Check the domain with the user |
| `-32602` `quota_exceeded` / `too_many_files` | Quota or batch limit reached | Narrow the folder or free space |
| Computer folder refused | Not approved for this site, or computer offline | Ask the user to approve it there (compare `site.code`) or start the computer |
| Files listed as skipped in `amount.status` | Name conflict, invalid name on the target OS, quota | Read each reason; rerun with the user's choice |
