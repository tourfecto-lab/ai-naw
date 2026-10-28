Tourfecto Instagram / Meta media-fetch hotfix
Source version: tourfecto.pro (32).zip

Changed file:
  public_html/robots.txt

Install:
  1. Keep a backup of the current public_html/robots.txt on hosting.
  2. Extract this ZIP over the site directory containing public_html.
     Replace only public_html/robots.txt.
  3. Open https://tourfecto.pro/robots.txt and verify it includes the
     facebookexternalhit / Facebot / meta-externalagent group with:
       Allow: /api/creative-studio/publish-media/
       Disallow: /api/
  4. Retry the failed Instagram post from Posts & calendar.
  5. Confirm the post actually appears on Instagram before recording a
     Meta App Review demo.

Why:
  Creative Studio publishes private media via short-lived signed URLs at
  /api/creative-studio/publish-media/{id}, while the existing robots.txt
  blocked all /api/ routes. This patch gives Meta crawlers a narrow
  exemption for signed publish media only. Private previews remain blocked.

No database migration, credentials, or application-code replacement required.
This is a configuration hotfix; test on hosting after uploading it.
