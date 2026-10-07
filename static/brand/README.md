# static/brand — bundled logo marks

Square PNGs (512×512, transparent corners where the mark is a circle) that ship with the
app, so a client's logo can show even when nothing has been uploaded for it yet.

| File | Used for |
|---|---|
| `joust.png` | The Joust Media mark: favicon (`<link rel="icon">`), the sign-in card, the avatar of the Joust (admin) actor in comment threads (incl. "Joust replied") and the activity feed, and the **admin seat's brand** — the sidebar brand ("Joust Media" + the scoped client or "All clients", `partials/tabbar.php`), the nav bar's trailing avatar on unscoped admin pages and the mark leading the nav eyebrow below 1024px (`partials/navbar.php`). Drawn round with `object-fit: contain` and a hairline ring (light in dark mode), so a replacement keeps its aspect ratio |
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
at 18–56 px with rounded corners, so keep the mark centred with a little padding).

The three `joust*.png` files are the **official Joust Media mark, supplied by Lance**
(October 2026). His PNG (342×372, transparent background, the circle ~295 px across) was
cropped to the circle and re-masked with a 4× supersampled anti-aliased circular mask (the
interior colour bled over the old white-matted edge, so no light halo on dark backgrounds),
then resampled with Lanczos to 512 / 180 / 32 px. The 512 px file is a ~1.7× upscale of
that source — swap in a larger PNG or an SVG export when one is available.
`privacybee.png`, `cometic.png` and `hmf.png` are still placeholder marks generated from
the brand descriptions — replace them with the real exports when you have them.

Adding a mark for a new client is the same: `static/brand/<slug>.png`, where `<slug>` is the
client's slug (`[a-z0-9-]`, as in `?client=<slug>`).
