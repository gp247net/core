{{--
    Backup notification to the site owner (US-backup-admin-notifications). Never carries archive
    content, passwords or links that grant access — only what happened and a link to the screen.

    @aidlc-unit backup-admin
    @aidlc-story US-backup-admin-notifications

    Variables: $title, $lines (list of strings), $note, $link, $button.
--}}
@extends('gp247-admin::email.layout')

@section('main')
<h1 style="font-family:Avenir,Helvetica,sans-serif;box-sizing:border-box;color:#2f3133;font-size:19px;font-weight:bold;margin-top:0;text-align:left">{{ $title }}</h1>
@foreach ($lines as $line)
<p style="font-family:Avenir,Helvetica,sans-serif;box-sizing:border-box;color:#74787e;font-size:16px;line-height:1.5em;margin-top:0;text-align:left">{{ $line }}</p>
@endforeach
<p style="font-family:Avenir,Helvetica,sans-serif;box-sizing:border-box;margin:24px 0">
    <a href="{{ $link }}" style="font-family:Avenir,Helvetica,sans-serif;box-sizing:border-box;border-radius:3px;color:#fff;display:inline-block;text-decoration:none;background-color:#3097d1;border-top:10px solid #3097d1;border-right:18px solid #3097d1;border-bottom:10px solid #3097d1;border-left:18px solid #3097d1" target="_blank">{{ $button }}</a>
</p>
<p style="font-family:Avenir,Helvetica,sans-serif;box-sizing:border-box;color:#74787e;font-size:12px;line-height:1.5em;margin-top:0;text-align:left">{{ $note }}</p>
@endsection
