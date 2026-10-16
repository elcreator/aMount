# aMount — later

## "My computer" through the ai.artur.work mesh (deferred, by decision)

Wanted: a manager enters their ai.artur.work key (same tiers as aIMage: own key, then site key, verified through
`GET /account`, optional "Connect" OAuth flow) and picks one of their mesh computers as a mount.

Facts established from the gateway sources (`ai-artur-work`):
- There is no drive/filesystem API. The mesh daemon is a job executor; it takes **job inputs** (images, audio — signed,
  short-lived `GET /api/v1/mesh/input/{jobGuid}/{name}` URLs, only for the worker holding the lease) and returns
  **job results** (`SubmitController`). Containers get no host mounts except a per-job temp dir.
- So a "mesh mount" cannot be a general Flysystem filesystem today. What exists maps onto *deliveries of images and
  sounds to a chosen worker for an edit, and the edit's result coming back*.

Decision: do not invent a protocol. When the gateway grows a partial capability (inputs/results addressed to one of the
user's own workers), add a restricted driver that offers only what it supports (image/audio types, deliver + collect),
and say so in the picker. Until then aMount ships without it; the key handling can be lifted from
`aIMage/src/Support/ApiKeys.php`.

## Other ideas

- Resumable uploads for adapters that support multipart sessions (S3 already streams multipart through the SDK).
- Per-rule bandwidth limits and quiet hours.
- Webhook/event when a run finishes (so a backup job can chain "upload, then notify").
