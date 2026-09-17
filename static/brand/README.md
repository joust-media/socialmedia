# static/brand — bundled logo marks

Square PNGs (512×512, transparent corners where the mark is a circle) that ship with the
app, so a client's logo can show even when nothing has been uploaded for it yet.

| File | Used for |
|---|---|
| `joust.png` | The Joust Media mark: favicon (`<link rel="icon">`), the admin chooser header, the sign-in card, and the avatar of the Joust (admin) actor in comment threads and the activity feed |
| `joust-180.png` | `apple-touch-icon` (Add to Home Screen) |
| `joust-32.png` | 32×32 favicon |
| `privacybee.png`, `cometic.png`, `hmf.png` | Slug-based fallback logos for those clients (see below) |

## How the fallback works (`brandLogoUrl()` in helpers.php)

For a company row, the logo URL is resolved in this order:

1. `companies.logo_url` as stored — an `uploads/...` value (relative, or root-rooted under
   a former folder such as `/socialmedia/uploads/x.png`) is re-rooted under the current app
   folder **when that file exists in this app's `uploads/`**; an absolute URL passes through;
   a root-rooted path whose file exists under the document root is kept as-is.
2. When the stored file is missing (or `logo_url` is empty): `static/brand/<slug>.png`
   (also `.svg`, `.jpg`, `.jpeg`, `.webp`) when a file with the company's slug exists here.
3. Otherwise no image — the UI shows the initials avatar.

So creating a client in Studio → Clients with slug `cometic` or `hmf` shows its bundled mark
immediately; uploading a logo there writes `uploads/logo_<slug>.png` and takes precedence.

## Replacing a mark

Drop a square PNG over the file with the same name (512×512 recommended; the UI renders it
at 18–56 px with rounded corners, so keep the mark centred with a little padding). The
files in this folder were generated from the brand descriptions as clean placeholder marks
— replace them with the real exports when you have them. `joust-180.png` / `joust-32.png`
are plain downscales of `joust.png`.

Adding a mark for a new client is the same: `static/brand/<slug>.png`, where `<slug>` is the
client's slug (`[a-z0-9-]`, as in `?client=<slug>`).
