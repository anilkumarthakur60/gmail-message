<!DOCTYPE html>
<html>

<head>
    <title>Emails</title>
</head>

<body>
    <h1>Emails</h1>
    @if (session('success'))
        <p>{{ session('success') }}</p>
    @endif

    <form method="GET" action="{{ route('emails') }}">
        <input type="text" name="q" value="{{ request()->get('q') }}" placeholder="Search emails...">
        <button type="submit">Search</button>
    </form>

    <ul>
        @foreach ($messages as $message)
            <li>
                <?php
                $msg = $service->users_messages->get('me', $message->getId());
                $headers = $msg->getPayload()->getHeaders();
                $from = collect($headers)->firstWhere('name', 'From')->getValue();
                $subject = collect($headers)->firstWhere('name', 'Subject')->getValue();
                ?>

                <p><strong>From:</strong> {{ $from }}</p>
                <p><strong>Subject:</strong> {{ $subject }}</p>
                <p><strong>Snippet:</strong> {!! $msg->getSnippet() !!}</p>
                <a href="{{ route('thread', ['id' => $message->getThreadId()]) }}">
                    {{ $message->getId() }}
                </a>
                <hr>
            </li>
        @endforeach

    </ul>
</body>

</html>
