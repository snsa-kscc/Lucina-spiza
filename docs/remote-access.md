# Remote access: SSH / SFTP / SSHFS

Host alias `luce` (remote user `tessry`, UID 1786). Site roots:
`/public_html` = production, `/public_html/labouche` = staging.

## Hard constraints

- **No remote shell.** `ssh luce 'cmd'` → `exec request failed on channel 0`.
  The account is SFTP-only, so nothing can be executed server-side (no remote
  `git`, `php`, etc.).
- `sftp -b - luce` works for get/put/rename/rmdir/mkdir.
- The user's SSHFS mount: `~/Work/luce/remote` → `luce:/`.

## Git over SSHFS — don't

Attempted `git pull` inside the mounted mu-plugins checkout failed in layers:

1. `fatal: detected dubious ownership` — repo owned by remote UID 1786, local
   user is 1000. Workaround exists (`git -c safe.directory=<abs path>`,
   per-command, never a global config entry) but only fixes the check.
2. `error: cannot open '.git/FETCH_HEAD': Permission denied` — Git opens
   FETCH_HEAD in a mode the SFTP backend refuses (`open('a')` fails while
   `open('w')` succeeds). A second mount with `-o idmap=user,uid=1000` fixed
   display ownership and manual writes, but Git still failed.
3. **Conclusion:** this SFTP backend can't support Git's file-access patterns.
   The staging mu-plugins `.git` was deleted; deploy by uploading files and
   verifying `sha256sum`.

## Deleting dirs over SSHFS — stale handle trap

`rm -rf .git` removed all contents but `rmdir` kept failing (`Directory not
empty` / SFTP `Failure`) although listings showed the dir empty — a classic
NFS-style silly-rename/stale-handle situation: a process (SSHFS mount, a shell
cwd'd inside, or the earlier Git procs) still held the directory open.

Workaround used: `rename .git .git-bak` over SFTP to get it out of the way.

To fully remove such a dir later:

1. `cd` out of the mount in every shell.
2. `fusermount3 -u ~/Work/luce/remote` (all mounts of that path).
3. Wait, remount, `rmdir .git-bak`. If it still fails, the handle is
   server-side and clears on its own; the empty dir is harmless meanwhile.

## Deploy procedure that works

Normally use CI instead — see `deployment.md`. Manual fallback:

```bash
sftp -b - luce <<'EOF'
put Lucina-spiza/lucina-spiza/checkout-company.php \
    /public_html/labouche/wp-content/mu-plugins/lucina-spiza/checkout-company.php
EOF
# verify
sftp luce <<'EOF'
get /public_html/labouche/wp-content/mu-plugins/lucina-spiza/checkout-company.php /tmp/remote.php
EOF
sha256sum /tmp/remote.php Lucina-spiza/lucina-spiza/checkout-company.php
```

## Browser automation note

`agent-browser` is not installed. Headed Brave with
`--remote-debugging-port=9222 --user-data-dir=~/.cache/devin-agent-browser`
plus CDP worked for the e-Računi session (user logs in, automation drives the
iframe app — real mouse events needed for its menus).
