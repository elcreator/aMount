# aMount — later

## "My computer" through the ai.artur.work mesh

The gateway grew the capability this section waited for: shared folders on the owner's own
computers, sealed end to end. It is the `ai-computer` driver, a full Flysystem filesystem over the folders the person shares - see the
README, "My computer". Left: resumable transfers of one huge file across sessions (a file is
one session today; a broken one is sent again from the start).

## Other ideas

- Resumable uploads for adapters that support multipart sessions (S3 already streams multipart through the SDK).
- Per-rule bandwidth limits and quiet hours.
- Webhook/event when a run finishes (so a backup job can chain "upload, then notify").
