{{--
    Newsletter broadcast email. Wraps the editor's content in the shared
    branded shell so every campaign matches the brand. When $isHtml is true
    the content is rendered raw ({!! !!}); when false, plain text is split
    into paragraphs on blank lines so editors can write naturally.
--}}
<x-emails.layout :mail-message="$message ?? null" :title="$subjectLine">

    @if ($isHtml)
        {!! $bodyContent !!}
    @else
        @foreach (preg_split("/\n\s*\n/", trim($bodyContent)) as $paragraph)
            @if (trim($paragraph) !== '')
                <p style="margin: 0 0 16px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 15px; line-height: 1.6; color: #3f3f46;">
                    {{ $paragraph }}
                </p>
            @endif
        @endforeach
    @endif

    <p style="margin: 32px 0 0 0; padding-top: 24px; border-top: 1px solid #e4e4e7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 12px; line-height: 1.6; color: #71717a;">
        You received this email because you subscribed to {{ $siteName }} updates.
        Don't want these anymore?
        <a href="{{ url('/') }}" style="color: #2563eb;">Visit the site</a> to manage your preferences.
    </p>

</x-emails.layout>
