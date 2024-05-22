<!DOCTYPE html>
<html>
<head>
    <title>Emails</title>
</head>
<body>
<h1>Emails</h1>
@if(session('success'))
    <p>{{ session('success') }}</p>
@endif
<ul>
    @foreach ($messages as $message)
        <li>
            <a href="{{ route('thread', ['id' => $message->getThreadId()]) }}">
                {{ $message->getId() }}
            </a>
        </li>
    @endforeach
</ul>
</body>
</html>
