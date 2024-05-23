<?php

namespace App\Http\Controllers;

use Google_Client;
use Google_Service_Gmail;
use Illuminate\Http\Request;

class GoogleController extends Controller
{
    public function redirectToGoogle()
    {
        $client = new Google_Client();
        $client->setClientId(config('google.client_id'));
        $client->setClientSecret(config('google.client_secret'));
        $client->setRedirectUri(config('google.redirect_uri'));
        $client->addScope(Google_Service_Gmail::GMAIL_READONLY);

        return redirect($client->createAuthUrl());
    }

    public function handleGoogleCallback(Request $request)
    {
        $client = new Google_Client();
        $client->setClientId(config('google.client_id'));
        $client->setClientSecret(config('google.client_secret'));
        $client->setRedirectUri(config('google.redirect_uri'));
        $client->authenticate($request->get('code'));

        $token = $client->getAccessToken();

        // Save the token for future use (e.g., in the database or session)
        // Here, we'll just store it in the session for simplicity
        session(['google_token' => $token]);

        return to_route('emails')->with('success', 'Google token stored successfully!');
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
            'maxResults' => 1,
            'pageToken' => $request->query('pageToken'),
        ];
        if ($request->filled('q')) {
            $param['q'] = $request->query('q');
        }
        if ($request->filled('label') && in_array($request->query('label'), $gmailLabels)) {
            $param['labelIds'] = $request->query('label');
        }

        $messages = $service->users_messages->listUsersMessages('me', $param);

        return view('emails', ['messages' => $messages, 'service' => $service,'labels'=>$gmailLabels]);
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
}
