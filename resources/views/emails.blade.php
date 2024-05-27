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
{{--    @dd(session('google_token'))--}}

<form method="GET" action="{{ route('google.emails') }}">
    <input type="text" name="q" value="{{ request()->get('q') }}" placeholder="Search emails...">
    <input type="number"
           name="pageToken" value="{{ request()->get('pageToken') }}" placeholder="pageToken...">
    <select name="label">
        @foreach($labels as $label)
            <option value="{{ $label }}" {{ request()->get('label') == $label ? 'selected' : '' }}>
                {{ $label }}
            </option>
        @endforeach
    </select>
    <button type="submit">Search</button>
</form>

<div class="">
    {{$nextPageToken}}-{{$total}}
</div>

<ul>
    @foreach ($messages as $message)
        <li>
                <?php
                $msg = $service->users_messages->get('me', $message->getId());
                $headers = $msg->getPayload()?->getHeaders();
                $from = collect($headers)->firstWhere('name', 'From')?->getValue();
                $subject = collect($headers)->firstWhere('name', 'Subject')?->getValue();
                ?>

            <p><strong>From:</strong> {{ $from }}</p>
            <p><strong>Subject:</strong> {{ $subject }}</p>
            <p><strong>Snippet:</strong> {!! $msg->getSnippet() !!}</p>
            <a href="{{ route('google.thread', ['id' => $message->getThreadId()]) }}">
                {{ $message->getId() }}
            </a>
            <hr>
        </li>
    @endforeach

</ul>
</body>

</html>
