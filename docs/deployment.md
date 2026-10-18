# Deployment (GitHub Actions → SFTP)

`.github/workflows/deploy.yml` uploads the mu-plugin over SFTP. The host is
SFTP-only (see `remote-access.md`), so the workflow uses `lftp` over `sftp://`
— no rsync, no remote git.

## Triggers

| Event | Target | Remote dir |
|---|---|---|
| Push to `main` | staging | `/public_html/labouche/wp-content/mu-plugins` |
| Push to `main`, head commit message contains `[ci prod]` | **production only** | `/public_html/wp-content/mu-plugins` |
| Actions → Deploy → Run workflow | chosen | as above |

Pushes run only when `lucina-spiza.php`, `mail-audit.php`, `lucina-spiza/**`
or the workflow itself changed.

`[ci prod]` is read from the **head commit** of the push. When merging a PR
with a merge commit, the head commit is the merge commit, whose message
includes the PR title — so put `[ci prod]` in the PR title (or in the squash
commit title).

## What a run does

1. `php -l` on every PHP file (PHP 8.2, same as the hosting).
2. Uploads `lucina-spiza/` with `mirror -R --delete` and puts
   `lucina-spiza.php` + `mail-audit.php`. Files are written to a temp name and
   renamed, so WordPress never loads a half-written PHP file.
   - `--delete` only applies inside `lucina-spiza/`: files there that are not
     in the repo are removed. Siblings in `mu-plugins/` are never touched.
   - `.git*` files and dirs (e.g. the old `.git-bak`) are excluded both ways.
3. Downloads what is now on the server and `diff`s it against the commit; the
   job fails on any difference. This replaces the manual `sha256sum` check.

Each target is a GitHub **environment** (`staging`, `production`). Adding a
required reviewer to `production` (Settings → Environments) makes every prod
deploy wait for a click.

## Connection and secrets

Host, user and port are plain `env` in the workflow, matching the `luce` alias:
`www258.your-server.de`, user `tessry`, port `22`.

Repository secrets (Settings → Secrets and variables → Actions):

| Secret | Value |
|---|---|
| `SSH_PRIVATE_KEY` | a dedicated deploy key (no passphrase) |
| `SSH_KNOWN_HOSTS` | the server's host-key line(s), from `ssh-keygen -F www258.your-server.de` on a machine that already trusts it; strict checking is on |

Tested against a local SFTP-only, chrooted OpenSSH server: upload, stale-file
deletion, `.git-bak` preserved, verify fails on a tampered file, wrong host key
refused.
