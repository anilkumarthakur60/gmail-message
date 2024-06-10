<?php

namespace App\Http\Controllers;

use App\Models\EmailToken;
use Exception;
use Google\Service\Gmail;
use Google_Client;
use Google_Service_Gmail;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class GoogleController extends Controller
{
    private Google_Service_Gmail $googleServiceGmail;

    private Google_Client $client;

    public function __construct(Google_Client $client)
    {
        $this->client = $client;
        $this->initializeGoogleClient($client);
        $this->googleServiceGmail = new Google_Service_Gmail($client);
    }

    private function initializeGoogleClient(Google_Client $client): void
    {
        $client->setClientId(config('google.client_id'));
        $client->setClientSecret(config('google.client_secret'));
        $client->setRedirectUri(config('google.redirect_uri'));
        $client->setAccessType('offline');
        $client->addScope([
            Google_Service_Gmail::GMAIL_SEND,
            Google_Service_Gmail::GMAIL_READONLY,
            Google_Service_Gmail::GMAIL_COMPOSE,
            Google_Service_Gmail::MAIL_GOOGLE_COM,
            Google_Service_Gmail::GMAIL_MODIFY,
        ]);
    }

    public function login()
    {
        return redirect($this->client->createAuthUrl());
    }

    public function callback(Request $request)
    {
        if ($request->isNotFilled('code')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $token = $this->client->fetchAccessTokenWithAuthCode($request->get('code'));
            $this->storeTokenToDb($token);

            return to_route('google.emails');
        } catch (Exception $exception) {
            return to_route('google.login');
        }
    }

    /**
     * @throws \Google\Service\Exception|\Google\Exception
     */
    public function emails(Request $request)
    {
        $emailToken = $this->getEmailToken();
        if (! $emailToken) {
            return redirect()->route('google.login');
        }

        $client = $this->initializeClientWithToken($emailToken);

        if ($client->isAccessTokenExpired()) {
            $newToken = $this->refreshAccessToken($client);
            $this->updateAccessAndRefreshToken($newToken);
        }

        $service = new Gmail($client);
        $gmailLabels = collect($service->users_labels->listUsersLabels('me')->getLabels())->pluck('name')->toArray();

        $param = [
            'maxResults' => 10,
            'pageToken' => $request->query('pageToken'),
            'q' => $request->query('q'),
            'labelIds' => $request->filled('label') && in_array($request->query('label'),
                $gmailLabels) ? $request->query('label') : null,
        ];

        $messages = $service->users_messages->listUsersMessages('me', array_filter($param));
        $responseOrRequest = $service->users_messages->listUsersMessages('me', array_filter($param));

        return view('emails', [
            'messages' => $responseOrRequest,
            'service' => $service,
            'labels' => $gmailLabels,
            'nextPageToken' => $messages->getNextPageToken(),
            'total' => $messages->getResultSizeEstimate(),
        ]);
    }

    /**
     * @throws \Google\Service\Exception
     */
    public function thread($threadId)
    {
        $emailToken = $this->getEmailToken();
        if (! $emailToken) {
            return redirect()->route('google.redirect');
        }

        $client = $this->initializeClientWithToken($emailToken);

        if ($client->isAccessTokenExpired()) {
            $newToken = $this->refreshAccessToken($client);
            $this->updateAccessAndRefreshToken($newToken);
        }

        $service = new Google_Service_Gmail($client);
        $thread = $service->users_threads->get('me', $threadId);

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
    private function storeTokenToDb(array $token): void
    {
        $email = $this->googleServiceGmail->users->getProfile('me')->getEmailAddress();

        $data = [
            'access_token' => $token['access_token'],
            'expires_in' => $token['expires_in'],
            'created' => $token['created'],
            'scope' => $token['scope'],
            'token_type' => $token['token_type'],
            'refresh_token_updated_at' => Carbon::now(),
        ];

        if (isset($token['refresh_token'])) {
            $data['refresh_token'] = $token['refresh_token'];
        }

        EmailToken::query()->updateOrCreate(['email' => $email], $data);
    }

    private function initializeClientWithToken($token): Google_Client
    {
        $client = new Google_Client();
        $client->setAccessToken([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expires_in' => $token['expires_in'],
            'created' => $token['created'],
        ]);

        return $client;
    }

    private function refreshAccessToken(Google_Client $client): array
    {
        $client->refreshToken($client->getRefreshToken());

        return $client->getAccessToken();
    }

    private function getEmailToken(string $email = 'anilkumarthakur60@gmail.com')
    {
        return EmailToken::query()
            ->where('email', $email)
            ->first();
    }

    private function updateAccessAndRefreshToken(array $data, $email = 'anilkumarthakur60@gmail.com')
    {
        EmailToken::query()->where('email', $email)->first()?->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_in' => $data['expires_in'],
            'created' => $data['created'],
            'refresh_token_updated_at' => Carbon::now(),
        ]);
    }
}
