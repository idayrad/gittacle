Gittacle — small web GitHub client (MVP)

How to run
1. Place `public/` on your web root and `server/` next to it such that `public/app.js` calls `../server/api.php`.
2. Ensure PHP has cURL, ZipArchive, and PDO_SQLITE enabled.
3. Create a writable `data/` folder next to `server/` (the script creates it if missing).
4. Visit the site, click Setup, enter your GitHub username, repository (owner/repo), branch and a PAT.
   - The PAT is stored in SQLite at data/db.sqlite (MVP: stored in plaintext; for production encrypt it).
5. Click Pull to fetch the repository contents to the server folder: data/{owner}/{repo}/{branch}/...
6. Use the explorer to open files, edit, autosave, and push files using the "Save & Push" button.

Notes and limitations (MVP)
- For simplicity pushes are done per-file via the GitHub Contents API. For many-file changes you should implement a single multi-file commit using the Git DB API (create blobs, a tree, commit, update ref).
- Tokens are stored in SQLite in plaintext — in production these must be encrypted and access restricted.
- There is no user authentication: the app trusts whoever can access the UI. Add real user accounts for multi-user hosting.
- Error handling is basic; expand as needed.
- Unzip is not a dedicated endpoint in this MVP; you can upload a zip (upload_files) then call server-side unzip later if you need it.

JSON special endpoint
- POST to server/api.php?action=json_op with JSON body as you specified in the prompt (single file or files array). Example:
  {
    "owner":"username",
    "repo":"my-app",
    "branch":"develop",
    "message":"Add js and css",
    "files":[
      { "path":"src/js/app.js", "content":"function()" },
      { "path":"src/css/style.css", "content":"body, html {}" }
    ]
  }
- To append to files, include "append": true. If a file doesn't exist it will be created.

Extending
- Add user accounts and login system, encrypt and rotate tokens.
- Implement multi-file commits atomically via the Git Data API.
- Add conflict handling (compare base sha).
- Add file rename as a server-side copy+delete and then push both changes.
