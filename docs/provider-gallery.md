# Provider gallery (SPEC 82)

The private image gallery of a `provider`: `field_gallery`, up to **ten
images**, stored in `private://`.

Two routes, both read-only and both authenticated — one lists the images, the
other serves the bytes of one of them. The provider itself is **not** exposed
yet: there is no `GET /api/v1/providers` and no `GET /api/v1/providers/%`, and
`resources/provider.resource.inc` deliberately ships with the gallery and
nothing else. The provider listing is its own spec.

Writing is back office only. Nothing here uploads, deletes or reorders an
image: the operator does it from `node/<nid>/edit`, and the order of the
carousel is the order they drag the images into.

---

## GET /api/v1/providers/{nid}/gallery

The images of one provider.

**Authentication:** required

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer &lt;access_token&gt; |
| Accept-Language | `es` / `en` (optional, default `es`) |

**Request body**

None.

**Success response (200)**
```json
{
  "success": true,
  "data": {
    "images": [
      {
        "id": 42,
        "url": "https://midominio.com/api/v1/providers/7/gallery/42",
        "filename": "taller-01.jpg"
      }
    ]
  }
}
```

| Key | Type | Notes |
|---|---|---|
| `id` | int | `file_managed.fid`. A JSON integer, never a string. |
| `url` | string | Absolute URL of the download route below. **Never** a `sites/default/files` or `/system/files` one. |
| `filename` | string | The original file name. |

The three keys are exactly the ones a claim file returns
(`myapi_claim_build_file()`, see [claim.md](claim.md)), so the app can reuse the
same model and the same widget.

A published provider **with no images answers 200 and an empty list**, never
404. The order is the order of the Field API deltas — what the operator left in
the form — and it does not change between two identical requests.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401 | `missing_authorization` | No `Authorization` header. |
| 401 | `invalid_token` | Token unknown, revoked, expired, or of a deleted or blocked user. |
| 404 | `provider_not_found` | The `nid` does not exist, is not a `provider`, is unpublished, or is not a positive integer. |
| 405 | `method_not_allowed` | Any method other than `GET`. |

---

## GET /api/v1/providers/{nid}/gallery/{fid}

The **bytes** of one gallery image.

**Authentication:** required

**Headers**
| Header | Value |
|--------|-------|
| Authorization | Bearer &lt;access_token&gt; |

**Request body**

None.

**Success response (200)**

**The file itself, not the JSON envelope.** This and
`GET /api/v1/claims/%/files/%` are the only two endpoints of the module that
answer something other than the envelope, and it is on purpose: a binary file
has nothing to travel in, and base64 inside a JSON body would inflate it by a
third and break every standard image loader. The errors below **do** use the
envelope.

Response headers:

| Header | Value |
|--------|-------|
| Content-Type | The file's MIME type (`image/jpeg`, `image/png`) |
| Content-Length | Its size in bytes |
| Content-Disposition | `inline; filename="taller-01.jpg"` |
| Cache-Control | `private, no-store` |

Four checks run **in this order**:

1. Valid token → otherwise `401`.
2. The `{nid}` is a **published** `provider` → otherwise `404 provider_not_found`.
3. The `{fid}` hangs from **that** provider's `field_gallery` → otherwise `404 file_not_found`.
4. The file exists on disk → otherwise `404 file_not_found`.

**Possible errors**
| Code | `error_code` | When |
|------|--------------|------|
| 401 | `missing_authorization` | No `Authorization` header. |
| 401 | `invalid_token` | Token unknown, revoked, expired, or of a deleted or blocked user. |
| 404 | `provider_not_found` | The `nid` does not exist, is not a `provider`, or is unpublished. |
| 404 | `file_not_found` | The `fid` is not in that provider's gallery, or its bytes are missing from disk. |
| 405 | `method_not_allowed` | Any method other than `GET`. |

---

## What the app has to do — read this before wiring the carousel

**A bare `Image.network` does not work.** The URL requires the
`Authorization` header, and that widget does not send one; the symptom is a
broken image with no message and no log. There is no `?access_token=` query
parameter, in this endpoint or anywhere else in this module.

The way out is the same one the app already uses for claim images since
SPEC 65: a widget that accepts headers, or an image loader with its own cache.
For example:

```dart
Image.network(
  image.url,
  headers: {'Authorization': 'Bearer $accessToken'},
)
```

The `url` is absolute and already points at the right host: do not rebuild it,
and do not store it — the fid is the stable part, the host is not.

---

## Decisions worth knowing

**A `fid` of another provider answers `404 file_not_found`, not `403`.** The
caller already proved they can see the provider in the route, so telling the
two errors apart leaks nothing — but they are not told that the fid exists
somewhere else either. A `fid` of a **claim**, asked for through this route,
lands in the same `404`: the two families of private files never cross.

**An expired licence does not block the gallery.** `field_license_expiry`
decides whether a provider **appears** in the marketplace, not whether its bytes
are readable. Blocking them would break a carousel already open mid-session.

**Any valid token is enough.** No role, condominium or unit is read: the
marketplace is the same for the whole site, exactly like
`/api/v1/service-categories`. What is required is that the node be published.

**Every image costs a PHP request.** A private file is not served by the web
server: ten images are ten Drupal bootstraps, with no CDN, no web-server cache
and no `304` by `ETag`. That is the accepted price of the privacy decision, and
it is bounded by the 10-image cap and the 3 MB per file.

Out of scope today: image styles and thumbnails (the original is served),
`Range` and partial downloads, and any write path.

---

## The other reader: the back office

The operator does not go through these routes. `hook_file_download()` —
`myapi_file_download()` in `myapi.module` — resolves the same files for a
Drupal session, and the access decision delegates to
`node_access('view', $provider)`, i.e. whatever `myapi_node_access()` decided in
SPEC 78 (see [provider-role.md](provider-role.md)). No second role catalogue
was written for files.

| Reader | Door | Rule |
|--------|------|------|
| The app | The two routes above | Bearer token + the node must be published. |
| The back office | `hook_file_download()` | Drupal session + `node_access('view', $provider)`. Anonymous requests to `/system/files/...` get `403`. |

Both doors share `includes/myapi.provider_files.inc`, which resolves which
provider owns a given fid. Image styles keep working because
`image_style_deliver()` checks access on the **source** file, which that include
does resolve.

Since SPEC 82 the hook has **two** owners — claims first, providers second — and
the maintenance rule for adding a third is written in
[services-install.md](services-install.md#maintenance-rule--hook_file_download-now-has-two-owners).

---

## Implementation notes

**The `field_data_*` tables are read directly.** The list does not call
`node_load()`: the response needs three columns, and loading the node would fire
the entity load hooks and drag twelve fields along. Same approach and same
caveat as [claim.md](claim.md) — the query assumes the current field storage and
would break if someone rebuilt `field_gallery` from the UI.

**Query count is constant.** The list costs three queries whatever the size of
the gallery: the token, the provider, and one batch query for the images with
their file names joined in.

**`file_private_path` is an environment prerequisite**, inherited from SPEC 65
and already satisfied on this site. Without it the `private://` scheme does not
resolve at all.
