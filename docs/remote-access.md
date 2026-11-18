# Remote access

Host alias `luce` (`www258.your-server.de`, user `tessry`). SFTP paths:
`/public_html` = production, `/public_html/labouche` = staging,
`/` = account root (not web-served). Server path of `/public_html` is
`/usr/www/users/tessry`.

## Constraints

- **SFTP only.** No remote shell — `ssh luce 'cmd'` fails with `exec request
  failed`. Nothing (git, php, wp-cli) can run on the server.
- Use `sftp -b - luce <<'EOF' … EOF` for get / put / rename / rm.
- Don't run `git` on the server through any mount — the SFTP backend can't
  handle Git's file access. Code deploys go through CI (`deployment.md`).

## Editing wp-config.php safely

Production edits need explicit approval.

1. `get` the file to a local scratch dir; keep an untouched copy.
2. Edit locally, keeping the original line endings (production is CRLF).
3. `put` it to the **account root** under a temp name, `chmod 644`, then
   `rename` the old file to the account root (backup) and the new one into
   place. Never leave backups or temp files inside `public_html` — a
   non-`.php` name is served as plain text with the credentials.
4. `get` it back and compare `sha256sum`; check the site returns 200.
5. Never print the file or a full diff — it contains the DB password and salts.

## Temporary check scripts

For one-off checks that need WordPress (e.g. testing API credentials), upload
a PHP file with an unguessable name to `public_html`, have it print no
secrets, and delete it right after use.
