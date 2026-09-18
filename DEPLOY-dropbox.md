# Drop Box — deploy notes

A private file drop for clients, living at **robrichardson.co.uk/upload**.
Files go straight from the client's browser into your Google Drive. They never
touch the Catalyst2 server, so there's no size ceiling and no disk to fill.

Same shape as Roggl: PHP, no build step, config outside the web root.

---

## What goes where

| Local (this repo)          | Server                                        |
|----------------------------|-----------------------------------------------|
| `upload/`                  | `/home/robric01/public_html/upload/`          |
| `private-config/config.php`| `/home/robric01/private/dropbox/config.php`   |

The private folder sits **next to** `public_html`, not inside it. That's the
whole point — nothing in there is reachable over the web.

---

## 1. Google Cloud console (once, ~10 minutes)

Go to <https://console.cloud.google.com>.

1. **New project** — call it `Drop Box`.
2. **APIs & Services → Library** → search *Google Drive API* → **Enable**.
3. **APIs & Services → OAuth consent screen**
   - User type: **External**
   - App name: `Rob Richardson Drop Box`, your email for both contact fields
   - **Scopes** → Add → search `drive.file` → tick
     `.../auth/drive.file` — *"See, edit, create and delete only the specific
     Google Drive files you use with this app"*. Add nothing else.
   - Finish the wizard, then on the summary page click **Publish app** so the
     status reads **In production**.

   > **Do not skip the publish step.** While the status is "Testing", Google
   > expires the refresh token after 7 days and the drop box silently stops
   > working. Because `drive.file` is a *non-sensitive* scope, publishing is
   > just a form — there's no review, no security assessment, no annual audit.

4. **APIs & Services → Credentials → Create credentials → OAuth client ID**
   - Type: **Web application**
   - Name: `Drop Box web`
   - **Authorised redirect URIs** → Add:
     ```
     https://robrichardson.co.uk/upload/setup.php
     ```
     It must match character for character, including `https` and no trailing slash.
   - Create, then copy the **Client ID** and **Client secret**.

---

## 2. Fill in the config

Copy `private-config/config.sample.php` to `private-config/config.php` and set:

- `client_id` and `client_secret` from step 1
- `setup_key` — any long random string you invent. It stops a stranger
  pointing your drop box at *their* Drive.
- leave `refresh_token` and `passphrase_hash` alone; setup.php writes both.

Check `notify_email` is the address you want the alerts on.

---

## 3. Upload with Cyberduck

Connect to Catalyst2, then drag:

- the whole `upload/` folder → `public_html/`
- `private-config/config.php` → `private/dropbox/config.php`
  (create `private/` and `private/dropbox/` alongside `public_html` if they
  don't exist — **not inside** `public_html`)

Set `private/dropbox/` to permissions **700** if Cyberduck lets you.
`config.php` needs to stay writable so setup.php can save the token into it.

---

## 4. Connect Drive

Visit:

```
https://robrichardson.co.uk/upload/setup.php?key=YOUR_SETUP_KEY
```

You'll get a checklist. Everything should be green except the refresh token.

1. Click **Connect Google Drive**.
2. Sign in as the account whose Drive should receive the files.
3. You'll see **"Google hasn't verified this app"** — that's expected and
   correct for a private app. Click **Advanced** → **Go to robrichardson.co.uk
   (unsafe)**. It's your own app; the warning just means you haven't paid for
   a verification review you don't need.
4. Allow access. You land back on the setup page with the token saved.
5. Click **Run live test** — it refreshes the token and creates the
   `Client Uploads` folder in your Drive.
6. Set a **client passphrase** in the box further down the page.

---

## 5. Lock up

Delete `setup.php` from the server once it all works. (It refuses to do
anything without the setup key, so leaving it is not a disaster — but deleting
it is one less thing.)

Then send a client the link and the passphrase:

> Drop your files here: https://robrichardson.co.uk/upload
> Passphrase: whatever-you-set

---

## How it actually works

1. Client types the passphrase and picks files.
2. `api.php` checks the passphrase, creates a dated folder in your Drive, and
   asks Google for one **resumable upload session** per file.
3. The browser PUTs the file to Google in 8 MB chunks, direct.
4. If the browser can't reach Google cross-origin, it silently falls back to
   relaying chunks through `api.php`. Slower, but it still works.
5. `api.php` asks Google to confirm each file landed, logs the drop in SQLite,
   and emails you.

Because of step 3, `upload_max_filesize` and `post_max_size` on Catalyst2 are
irrelevant on the happy path.

---

## Where the data lives

- **Files** — your Google Drive, under `Client Uploads / YYYY-MM-DD — Name`.
  They count against your Drive quota.
- **Log** — `private/dropbox/dropbox.sqlite`. Who sent what, when, and the
  Drive file IDs. Nothing sensitive, but it's outside the web root anyway.

---

## Troubleshooting

**"Could not refresh the Google token"** — the consent screen probably slipped
back to Testing, or you revoked access. Re-run setup.php.

**"invalid_grant"** — same cause. Re-authorise.

**Nothing in Drive but the browser said it worked** — check
`private/dropbox/dropbox.sqlite`; the `files` table records the Drive file ID
for anything that completed.

**No notification email** — Catalyst2's `mail()` can land in spam. Check the
folder, and consider setting `notify_from` to a real address on your domain.

**Everything is slow** — the browser fell back to proxy mode. The upload is
going through the server twice. Worth telling me if it happens; it means
Google's CORS behaviour changed.
