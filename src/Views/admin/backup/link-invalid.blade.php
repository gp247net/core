{{--
    Same neutral answer for every refused download link (expired, used, someone else's, bad
    signature, no right): the reason is in the operation log only.

    @aidlc-unit backup-admin
    @aidlc-story US-backup-admin-signed-download
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ gp247_language_render('admin.backup.link_invalid.title') }}</title>
    <style>body{font-family:system-ui,sans-serif;max-width:36rem;margin:4rem auto;padding:0 1rem;color:#1f2937}a{color:#2563eb}</style>
</head>
<body data-testid="backup-link-invalid">
    <h1>{{ gp247_language_render('admin.backup.link_invalid.title') }}</h1>
    <p>{{ gp247_language_render('admin.backup.link_invalid.body') }}</p>
    <p><a href="{{ gp247_route_admin('admin.backup.archives') }}">{{ gp247_language_render('admin.backup.title') }}</a></p>
</body>
</html>
