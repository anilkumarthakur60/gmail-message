<!DOCTYPE html>
<html>
<head>
    <title>Thread</title>
</head>
<body>
<h1>Thread</h1>
<ul>
    @foreach ($thread->getMessages() as $message)
        <li>
            <p><strong>From:</strong> {{ $message->getPayload()->getHeaders()[0]->getValue() }}</p>
            <p><strong>Subject:</strong> {{ $message->getPayload()->getHeaders()[1]->getValue() }}</p>
            <p><strong>Snippet:</strong> {{ $message->getSnippet() }}</p>
            <hr>

            @php
                $attachments = [];
           $parts = $message->getPayload()->getParts();
           foreach ($parts as $part) {
               if ($part->getFilename() && $part->getBody()) {
                   $attachmentId = $part->getBody()->getAttachmentId();
                   $attachment = $service->users_messages_attachments->get('me', $message->getId(), $attachmentId);
                   $attachments[] = [
                       'filename' => $part->getFilename(),
                       'mimeType' => $part->getMimeType(),
                       'data' => base64_decode(strtr($attachment->getData(), '-_', '+/')),
                   ];
               }
           }
            @endphp

            @if (isset($attachments))
                @foreach ($attachments as $attachment)
                    @if ($attachment['filename'])
                        <p><strong>Attachment:</strong> <a href="{{ route('google.download-attachment', ['data' => base64_encode($attachment['data']), 'filename' => $attachment['filename']]) }}">{{ $attachment['filename'] }}</a></p>
                    @endif
                @endforeach
            @endif
        </li>
    @endforeach
</ul>
<a href="{{ route('google.emails') }}">Back to Emails</a>
</body>
</html>
