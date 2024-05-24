<?php

namespace App\Http\Controllers;

use App\Models\EmailToken;
use Google_Client;
use Google_Service_Gmail;
use Illuminate\Http\Request;

class GoogleController extends Controller
{
    public Google_Service_Gmail $googleServiceGmail;

    public function __construct(public Google_Client $client)
    {
        $client->setClientId(config('google.client_id'));
        $client->setClientSecret(config('google.client_secret'));
        $client->setRedirectUri(config('google.redirect_uri'));
        $client->addScope([
            Google_Service_Gmail::GMAIL_SEND,
            Google_Service_Gmail::GMAIL_READONLY,
            Google_Service_Gmail::GMAIL_COMPOSE
        ]);
        $this->googleServiceGmail = new Google_Service_Gmail($client);
    }

    public function redirectToGoogle()
    {
        return redirect($this->client->createAuthUrl());
    }

    public function handleGoogleCallback(Request $request)
    {
        if ($request->isNotFilled('code')) {
            abort(403, 'Unauthorized action.');
        }

        $d = $this->client->fetchAccessTokenWithAuthCode($request->get('code'));
        $this->storeTokenToDb($d);
    }

    /**
     * @throws \Google\Service\Exception
     * @throws \Exception
     */
    public function getEmails(Request $request)
    {
        $token = session('google_token');
        if (!$token) {
            return redirect()->route('google.redirect');
        }

        $client = new Google_Client();
        $client->setAccessToken($token);

        if ($client->isAccessTokenExpired()) {
            // Refresh the token if it's expired
            $client->refreshToken($client->getRefreshToken());
            session(['google_token' => $client->getAccessToken()]);
        }

        $service = new Google_Service_Gmail($client);
        $gmailLabels = $service->users_labels->listUsersLabels('me')->getLabels();

        $gmailLabels = collect($gmailLabels)->pluck('name')->toArray();

        $param = [
            'maxResults' => $perPage = 10,
            'pageToken' => $request->query('pageToken'),
        ];
        if ($request->filled('q')) {
            $param['q'] = $request->query('q');
        }
        if ($request->filled('label') && in_array($request->query('label'), $gmailLabels)) {
            $param['labelIds'] = $request->query('label');
        }

        $messages = $service->users_messages->listUsersMessages('me', $param);
        $total = $messages->getResultSizeEstimate();

        return view('emails', [
            'messages' => $messages,
            'service' => $service,
            'labels' => $gmailLabels,
            'nextPageToken' => $request->query('pageToken') + $perPage,
            'total' => $total
        ]);
    }

    public function getThread($threadId)
    {
        $token = session('google_token');
        if (!$token) {
            return redirect()->route('google.redirect');
        }

        $client = new Google_Client();
        $client->setAccessToken($token);

        if ($client->isAccessTokenExpired()) {
            $client->refreshToken($client->getRefreshToken());
            session(['google_token' => $client->getAccessToken()]);
        }

        $service = new Google_Service_Gmail($client);
        $thread = $service->users_threads->get('me', $threadId);

        //        $attachments = [];
        //        foreach ($thread->getMessages() as $message) {
        //            $parts = $message->getPayload()->getParts();
        //            foreach ($parts as $part) {
        //                if ($part->getFilename() && $part->getBody()) {
        //                    $attachmentId = $part->getBody()->getAttachmentId();
        //                    $attachment = $service->users_messages_attachments->get('me', $message->getId(), $attachmentId);
        //                    $attachments[] = [
        //                        'filename' => $part->getFilename(),
        //                        'mimeType' => $part->getMimeType(),
        //                        'data' => base64_decode(strtr($attachment->getData(), '-_', '+/')),
        //                    ];
        //                }
        //            }
        //        }

        return view('thread', ['thread' => $thread, 'service' => $service]);
    }

    public function downloadAttachment(Request $request)
    {
        $data = base64_decode($request->query('data'));
        $filename = $request->query('filename');

        return response($data)
            ->header('Content-Type', 'application/octet-stream')
            ->header('Content-Disposition', 'inline; filename="'.$filename.'"');
    }

    /**
     * @throws \Google\Service\Exception
     */
    private function storeTokenToDb($token)
    {

        $email = $this->googleServiceGmail->users->getProfile('me')->getEmailAddress();

        dd($token);

        return EmailToken::query()->updateOrCreate(
            ['email' => $email],
            [
                'access_token' => $token['access_token'],
                'expires_in' => $token['expires_in'],
                'created' => $token['created'],
                'refresh_token' => $token['refresh_token'],
                'scope' => $token['scope'],
                'token_type' => $token['token_type'],
            ]
        );
    }
}
